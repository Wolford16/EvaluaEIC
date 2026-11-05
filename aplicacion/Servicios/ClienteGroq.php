<?php
declare(strict_types=1);
namespace EvaluaIA\Servicios;

/** Cliente exclusivo de Groq. El transporte opcional permite pruebas sin claves reales. */
final class ClienteGroq {
    private ?\Closure $transporte;
    private string $clave;
    public readonly string $modelo;
    public readonly array $ajustes;
    public int $solicitudes = 0;
    public int $tokensUsados = 0;
    public string $formato = 'json_schema';
    private ?array $metadatos = null;
    private float $limiteTiempo;

    public function __construct(?callable $transporte = null, ?array $ajustes = null) {
        $this->transporte = $transporte ? \Closure::fromCallable($transporte) : null;
        $this->clave = trim(entorno('GROQ_API_KEY'));
        $this->modelo = trim(entorno('GROQ_MODEL'));
        $this->ajustes = array_replace(require RAIZ . '/configuracion/groq.php', $ajustes ?? []);
        $this->limiteTiempo = microtime(true) + $this->ajustes['tiempo_total'];
    }

    public function validarConfiguracion(): void {
        if ($this->clave === '' || $this->modelo === '') {
            throw new ErrorAplicacion('Completa GROQ_API_KEY y GROQ_MODEL en el archivo .env.', 503, 'groq_configuracion');
        }
        if (!preg_match('/^[a-zA-Z0-9._:\/-]{1,150}$/D', $this->modelo) || preg_match('/[\s\x00-\x1f\x7f]/', $this->clave)) {
            throw new ErrorAplicacion('Revisa la clave y el identificador del modelo de Groq.', 503, 'groq_configuracion');
        }
    }

    public function metadatos(int $tiempo = 10): array {
        $this->validarConfiguracion();
        if ($this->metadatos !== null) return $this->metadatos;
        $datos = $this->solicitar('/models/' . str_replace('%2F', '/', rawurlencode($this->modelo)), null, $tiempo);
        if (($datos['id'] ?? null) !== $this->modelo || ($datos['active'] ?? true) === false) {
            throw new ErrorAplicacion('El modelo configurado no está disponible para esta cuenta de Groq. Selecciona uno habilitado en Groq Console y actualiza GROQ_MODEL en .env.', 503, 'groq_modelo');
        }
        $contexto = filter_var($datos['context_window'] ?? 8192, FILTER_VALIDATE_INT);
        $salida = filter_var($datos['max_completion_tokens'] ?? 4096, FILTER_VALIDATE_INT);
        if (!$contexto || $contexto < 2048 || !$salida || $salida < 256) {
            throw new ErrorAplicacion('El modelo configurado no tiene capacidad suficiente para generar preguntas.', 503, 'groq_modelo');
        }
        return $this->metadatos = ['contexto' => $contexto, 'salida' => $salida];
    }

    public function completar(array $mensajes, array $esquema, int $tokens, int $tiempo): array {
        $this->validarConfiguracion();
        $datos = [
            'model' => $this->modelo, 'messages' => $mensajes,
            'max_completion_tokens' => $tokens, 'stream' => false,
            'response_format' => $this->formato === 'json_schema'
                ? ['type' => 'json_schema', 'json_schema' => ['name' => 'evaluaeic', 'strict' => true, 'schema' => $esquema]]
                : ['type' => 'json_object'],
        ];
        // Estos modelos reservan parte de la salida para razonar. Un esfuerzo bajo
        // deja más margen para el JSON sin enviar parámetros ajenos a otros modelos.
        if (in_array($this->modelo, ['openai/gpt-oss-20b', 'openai/gpt-oss-120b'], true)) {
            $datos['reasoning_effort'] = 'low';
        }
        try {
            $respuesta = $this->solicitar('/chat/completions', $datos, $tiempo);
        } catch (ErrorAplicacion $error) {
            // El mismo modelo sigue seleccionado. Solo se adapta el formato si Groq lo rechaza expresamente.
            if ($error->codigo !== 'groq_formato_no_compatible' || $this->formato !== 'json_schema') throw $error;
            $this->formato = 'json_object';
            $datos['response_format'] = ['type' => 'json_object'];
            $respuesta = $this->solicitar('/chat/completions', $datos, $tiempo);
        }
        $this->tokensUsados += max(0, (int)($respuesta['usage']['total_tokens'] ?? 0));
        $eleccion = $respuesta['choices'][0] ?? [];
        $mensaje = $eleccion['message'] ?? [];
        if (!empty($mensaje['refusal']) || ($eleccion['finish_reason'] ?? '') === 'content_filter') {
            throw new ErrorAplicacion('Groq rechazó este contenido. Revisa el documento o utiliza redacción manual.', 422, 'groq_rechazo');
        }
        if (($eleccion['finish_reason'] ?? null) !== 'stop') {
            throw new ErrorAplicacion('Groq devolvió una respuesta incompleta. Intenta con menos preguntas.', 502, 'groq_incompleta');
        }
        $contenido = $mensaje['content'] ?? null;
        if (!is_string($contenido) || trim($contenido) === '') {
            throw new ErrorAplicacion('Groq devolvió una respuesta vacía.', 502, 'groq_json');
        }
        try { $objeto = json_decode($contenido, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ErrorAplicacion('Groq no devolvió un JSON válido.', 502, 'groq_json'); }
        if (!is_array($objeto) || array_is_list($objeto)) {
            throw new ErrorAplicacion('La respuesta de Groq no tiene el formato esperado.', 502, 'groq_json');
        }
        return $objeto;
    }

    private function solicitar(string $ruta, ?array $datos, int $tiempo): array {
        $restante = (int)floor($this->limiteTiempo - microtime(true));
        if ($restante < 1) throw new ErrorAplicacion('La operación con Groq alcanzó su tiempo máximo.', 503, 'groq_tiempo');
        $tiempo = max(1, min($tiempo, $restante));
        if ($this->solicitudes >= $this->ajustes['max_solicitudes']) {
            throw new ErrorAplicacion('Este documento requiere demasiadas solicitudes. Divídelo en archivos más pequeños.', 422, 'groq_documento_extenso');
        }
        $this->solicitudes++;
        $cabeceras = ['Authorization: Bearer ' . $this->clave, 'Content-Type: application/json'];
        $respuesta = $this->transporte
            ? ($this->transporte)($ruta, $datos, $cabeceras, $tiempo)
            : $this->enviarHttp($ruta, $datos, $cabeceras, $tiempo);
        $errorConexion = (int)($respuesta['error'] ?? 0);
        if ($errorConexion !== 0) {
            $mensaje = match ($errorConexion) {
                CURLE_OPERATION_TIMEDOUT => 'Groq tardó demasiado en responder. Inténtalo de nuevo.',
                CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE => 'PHP no pudo validar el certificado HTTPS. Revisa curl.cainfo en el PHP del servidor.',
                default => 'No se pudo conectar con Groq. Revisa la conexión a Internet del servidor.',
            };
            throw new ErrorAplicacion($mensaje, 503, 'groq_conexion');
        }
        $estado = (int)($respuesta['estado'] ?? 0);
        $cuerpo = (string)($respuesta['cuerpo'] ?? '');
        $json = json_decode($cuerpo, true);
        if ($estado < 200 || $estado >= 300) $this->rechazarHttp($estado, is_array($json) ? $json : [], $respuesta['cabeceras'] ?? []);
        if (!is_array($json)) throw new ErrorAplicacion('No se pudo leer la respuesta de Groq.', 502, 'groq_json');
        return $json;
    }

    private function enviarHttp(string $ruta, ?array $datos, array $cabeceras, int $tiempo): array {
        if (!extension_loaded('curl')) throw new ErrorAplicacion('Activa la extensión cURL en el PHP del servidor.', 503, 'groq_configuracion');
        $curl = curl_init($this->ajustes['url'] . $ruta);
        $recibidas = []; $cuerpo = ''; $demasiadoGrande = false;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(8, $tiempo),
            CURLOPT_TIMEOUT => max(1, $tiempo), CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($recurso, string $linea) use (&$recibidas): int {
                if (str_contains($linea, ':')) { [$nombre, $valor] = explode(':', $linea, 2); $recibidas[strtolower(trim($nombre))] = trim($valor); }
                return strlen($linea);
            },
            CURLOPT_WRITEFUNCTION => static function ($recurso, string $parte) use (&$cuerpo, &$demasiadoGrande): int {
                if (strlen($cuerpo) + strlen($parte) > 2 * 1024 * 1024) { $demasiadoGrande = true; return 0; }
                $cuerpo .= $parte; return strlen($parte);
            },
        ]);
        if ($datos !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        curl_exec($curl);
        $estado = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_errno($curl);
        curl_close($curl);
        if ($demasiadoGrande) throw new ErrorAplicacion('La respuesta de Groq supera el tamaño permitido.', 502, 'groq_json');
        return ['estado' => $estado, 'cuerpo' => $cuerpo, 'cabeceras' => $recibidas, 'error' => $error];
    }

    private function rechazarHttp(int $estado, array $json, array $cabeceras): never {
        $mensajeProveedor = strtolower((string)($json['error']['message'] ?? ''));
        $codigo = strtolower((string)($json['error']['code'] ?? ''));
        if ($estado === 429) {
            $valor = $cabeceras['retry-after'] ?? null;
            $espera = is_numeric($valor) ? max(1, min(86400, (int)ceil((float)$valor))) : null;
            $mensaje = 'Groq alcanzó un límite de uso.' . ($espera ? " Espera {$espera} segundos antes de reintentar." : ' Espera a que se restablezca la cuota.');
            throw new ErrorAplicacion($mensaje, 429, 'groq_limite', $espera);
        }
        if ($estado === 401) throw new ErrorAplicacion('Groq rechazó la clave API. Revisa GROQ_API_KEY en .env.', 503, 'groq_clave');
        if ($estado === 403) throw new ErrorAplicacion('La cuenta de Groq no tiene permiso para usar este modelo.', 503, 'groq_permisos');
        if ($estado === 404 || str_contains($codigo, 'model_not_found') || str_contains($codigo, 'model_decommissioned')) {
            throw new ErrorAplicacion('El modelo configurado no está disponible para esta cuenta de Groq. Selecciona uno habilitado en Groq Console y actualiza GROQ_MODEL en .env.', 503, 'groq_modelo');
        }
        $hablaDeFormato = str_contains($mensajeProveedor, 'response_format') || str_contains($mensajeProveedor, 'json_schema') || str_contains($mensajeProveedor, 'strict');
        $noCompatible = str_contains($mensajeProveedor, 'not support') || str_contains($mensajeProveedor, 'unsupported') || str_contains($mensajeProveedor, 'not available');
        if ($estado === 400 && $hablaDeFormato && $noCompatible) throw new ErrorAplicacion('El modelo no admite este formato de JSON.', 502, 'groq_formato_no_compatible');
        if ($estado === 413 || str_contains($codigo, 'context_length') || str_contains($mensajeProveedor, 'context length') || str_contains($mensajeProveedor, 'request too large')) {
            throw new ErrorAplicacion('La solicitud supera el límite de tokens del modelo o de la cuenta. Reduce el documento o ajusta el presupuesto de Groq.', 422, 'groq_contexto');
        }
        if ($codigo === 'json_validate_failed' || $codigo === 'json_generation_failed') throw new ErrorAplicacion('Groq no pudo completar el formato JSON.', 502, 'groq_json');
        if ($estado >= 500) throw new ErrorAplicacion('Groq no está disponible temporalmente. Inténtalo más tarde.', 503, 'groq_servidor');
        throw new ErrorAplicacion('Groq rechazó la solicitud. Comprueba que el modelo sea de generación de texto y admita JSON.', 502, 'groq_solicitud');
    }
}
