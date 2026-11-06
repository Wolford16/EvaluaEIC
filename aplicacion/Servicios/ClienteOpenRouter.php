<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;

/** Cliente exclusivo de OpenRouter. El transporte opcional permite pruebas sin claves reales. */
final class ClienteOpenRouter {
    private ?\Closure $transporte;
    private string $clave;
    public string $modelo;
    public array $ajustes;
    public int $solicitudes = 0;
    public int $tokensUsados = 0;
    public string $formato = 'json_texto';
    private array $parametros = [];
    private ?array $metadatos = null;
    private float $limiteTiempo;

    public function __construct(?callable $transporte = null, ?array $ajustes = null) {
        $this->transporte = $transporte ? \Closure::fromCallable($transporte) : null;
        $this->clave = trim(entorno('OPENROUTER_API_KEY'));
        $this->modelo = trim(entorno('OPENROUTER_MODEL'));
        $this->ajustes = array_replace(require RAIZ . '/configuracion/openrouter.php', $ajustes ?? []);
        $this->limiteTiempo = microtime(true) + $this->ajustes['tiempo_total'];
    }

    public function validarConfiguracion(): void {
        if ($this->clave === '' || $this->modelo === '') {
            throw new ErrorAplicacion('Completa OPENROUTER_API_KEY y OPENROUTER_MODEL en el archivo configuracion/servidor.php.', 503, 'openrouter_configuracion');
        }
        if (!preg_match('/^[a-zA-Z0-9._:\/-]{1,150}$/D', $this->modelo) || preg_match('/[\s\x00-\x1f\x7f]/', $this->clave)) {
            throw new ErrorAplicacion('Revisa la clave y el identificador del modelo de OpenRouter.', 503, 'openrouter_configuracion');
        }
    }

    public function metadatos(int $tiempo = 10): array {
        $this->validarConfiguracion();
        if ($this->metadatos !== null) return $this->metadatos;
        $catalogo = $this->solicitar('/models', null, $tiempo);
        $datos = null;
        foreach ($catalogo['data'] ?? [] as $entrada) {
            if (($entrada['id'] ?? null) === $this->modelo) {
                $datos = $entrada;
                break;
            }
        }
        if ($datos === null) {
            throw new ErrorAplicacion('OpenRouter no encuentra el modelo configurado. Revisa OPENROUTER_MODEL en configuracion/servidor.php.', 503, 'openrouter_modelo');
        }
        $contexto = filter_var($datos['context_length'] ?? 8192, FILTER_VALIDATE_INT);
        $salida = filter_var($datos['top_provider']['max_completion_tokens'] ?? 4096, FILTER_VALIDATE_INT);
        if (!$contexto || $contexto < 2048 || !$salida || $salida < 256) {
            throw new ErrorAplicacion('El modelo configurado no tiene capacidad suficiente para generar preguntas.', 503, 'openrouter_modelo');
        }
        $this->parametros = $datos['supported_parameters'] ?? [];
        $this->formato = match (true) {
            in_array('structured_outputs', $this->parametros, true) => 'json_schema',
            in_array('response_format', $this->parametros, true) => 'json_object',
            default => 'json_texto',
        };
        return $this->metadatos = ['contexto' => $contexto, 'salida' => $salida];
    }

    public function completar(array $mensajes, array $esquema, int $tokens, int $tiempo): array {
        $this->metadatos();
        $datos = [
            'model' => $this->modelo, 'messages' => $mensajes,
            'max_tokens' => $tokens, 'stream' => false,
        ];
        $modeloBase = explode(':', $this->modelo, 2)[0];
        if (in_array($modeloBase, ['nvidia/nemotron-3-ultra-550b-a55b', 'nvidia/nemotron-3.5-super-vl'], true)
            && in_array('reasoning', $this->parametros, true)) {
            $datos['reasoning'] = ['enabled' => false];
        }
        $this->configurarFormato($datos, $esquema);
        try {
            $respuesta = $this->solicitar('/chat/completions', $datos, $tiempo);
        } catch (ErrorAplicacion $error) {
            if ($error->codigo !== 'openrouter_formato_no_compatible' || $this->formato === 'json_texto') throw $error;
            $this->formato = $this->formato === 'json_schema' && in_array('response_format', $this->parametros, true)
                ? 'json_object' : 'json_texto';
            $this->configurarFormato($datos, $esquema);
            $respuesta = $this->solicitar('/chat/completions', $datos, $tiempo);
        }
        $this->tokensUsados += max(0, (int)($respuesta['usage']['total_tokens'] ?? 0));
        $eleccion = $respuesta['choices'][0] ?? [];
        $mensaje = $eleccion['message'] ?? [];
        if (!empty($mensaje['refusal']) || ($eleccion['finish_reason'] ?? '') === 'content_filter') {
            throw new ErrorAplicacion('OpenRouter rechazó este contenido. Revisa el documento e inténtalo de nuevo.', 422, 'openrouter_rechazo');
        }
        if (($eleccion['finish_reason'] ?? null) !== 'stop') {
            throw new ErrorAplicacion('OpenRouter devolvió una respuesta incompleta. Intenta con menos preguntas.', 502, 'openrouter_incompleta');
        }
        $contenido = $mensaje['content'] ?? null;
        if (!is_string($contenido) || trim($contenido) === '') {
            throw new ErrorAplicacion('OpenRouter devolvió una respuesta vacía.', 502, 'openrouter_json');
        }
        $contenido = trim($contenido);
        if ($this->formato === 'json_texto' && preg_match('/\A```(?:json)?\h*\R(.*?)\R```\z/is', $contenido, $bloque)) {
            $contenido = $bloque[1];
        }
        try { $objeto = json_decode($contenido, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ErrorAplicacion('OpenRouter no devolvió un JSON válido.', 502, 'openrouter_json'); }
        if (!is_array($objeto) || es_lista($objeto)) {
            throw new ErrorAplicacion('La respuesta de OpenRouter no tiene el formato esperado.', 502, 'openrouter_json');
        }
        return $objeto;
    }

    private function configurarFormato(array &$datos, array $esquema): void {
        unset($datos['response_format'], $datos['provider']);
        if ($this->formato === 'json_texto') return;
        $datos['provider'] = ['require_parameters' => true];
        $datos['response_format'] = $this->formato === 'json_schema'
            ? ['type' => 'json_schema', 'json_schema' => ['name' => 'evaluaeic', 'strict' => true, 'schema' => $esquema]]
            : ['type' => 'json_object'];
    }

    private function solicitar(string $ruta, ?array $datos, int $tiempo): array {
        $restante = (int)floor($this->limiteTiempo - microtime(true));
        if ($restante < 1) throw new ErrorAplicacion('La operación con OpenRouter alcanzó su tiempo máximo.', 503, 'openrouter_tiempo');
        $tiempo = max(1, min($tiempo, $restante));
        if ($this->solicitudes >= $this->ajustes['max_solicitudes']) {
            throw new ErrorAplicacion('Este documento requiere demasiadas solicitudes. Divídelo en archivos más pequeños.', 422, 'openrouter_documento_extenso');
        }
        $this->solicitudes++;
        $cabeceras = ['Authorization: Bearer ' . $this->clave, 'Content-Type: application/json'];
        $respuesta = $this->transporte
            ? ($this->transporte)($ruta, $datos, $cabeceras, $tiempo)
            : $this->enviarHttp($ruta, $datos, $cabeceras, $tiempo);
        $errorConexion = (int)($respuesta['error'] ?? 0);
        if ($errorConexion !== 0) {
            $mensaje = match ($errorConexion) {
                CURLE_OPERATION_TIMEDOUT => 'OpenRouter tardó demasiado en responder. Inténtalo de nuevo.',
                CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE => 'PHP no pudo validar el certificado HTTPS. Revisa curl.cainfo en el PHP del servidor.',
                default => 'No se pudo conectar con OpenRouter. Revisa la conexión a Internet del servidor.',
            };
            throw new ErrorAplicacion($mensaje, 503, 'openrouter_conexion');
        }
        $estado = (int)($respuesta['estado'] ?? 0);
        $cuerpo = (string)($respuesta['cuerpo'] ?? '');
        $json = json_decode($cuerpo, true);
        if ($estado < 200 || $estado >= 300) $this->rechazarHttp($estado, is_array($json) ? $json : [], $respuesta['cabeceras'] ?? []);
        if (!is_array($json)) throw new ErrorAplicacion('No se pudo leer la respuesta de OpenRouter.', 502, 'openrouter_json');
        if (isset($json['error'])) {
            $codigoHttp = (int)($json['error']['code'] ?? 0);
            $this->rechazarHttp($codigoHttp >= 400 && $codigoHttp <= 599 ? $codigoHttp : 502, $json, $respuesta['cabeceras'] ?? []);
        }
        return $json;
    }

    private function enviarHttp(string $ruta, ?array $datos, array $cabeceras, int $tiempo): array {
        if (!extension_loaded('curl')) throw new ErrorAplicacion('Activa la extensión cURL en el PHP del servidor.', 503, 'openrouter_configuracion');
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
        if ($demasiadoGrande) throw new ErrorAplicacion('La respuesta de OpenRouter supera el tamaño permitido.', 502, 'openrouter_json');
        return ['estado' => $estado, 'cuerpo' => $cuerpo, 'cabeceras' => $recibidas, 'error' => $error];
    }

    private function rechazarHttp(int $estado, array $json, array $cabeceras): void {
        $mensajeProveedor = strtolower((string)($json['error']['message'] ?? ''));
        $codigo = strtolower((string)($json['error']['code'] ?? ''));
        if ($estado === 429) {
            $valor = $cabeceras['retry-after'] ?? null;
            $espera = is_numeric($valor) ? max(1, min(86400, (int)ceil((float)$valor))) : null;
            $mensaje = 'OpenRouter alcanzó un límite de uso.' . ($espera ? " Espera {$espera} segundos antes de reintentar." : ' Espera a que se restablezca la cuota.');
            throw new ErrorAplicacion($mensaje, 429, 'openrouter_limite', $espera);
        }
        if ($estado === 401) throw new ErrorAplicacion('OpenRouter rechazó la clave API. Revisa OPENROUTER_API_KEY en configuracion/servidor.php.', 503, 'openrouter_clave');
        if ($estado === 402) throw new ErrorAplicacion('OpenRouter indica que el saldo o el límite de gasto de la clave es insuficiente para esta solicitud.', 503, 'openrouter_saldo');
        if ($estado === 403) throw new ErrorAplicacion('La cuenta de OpenRouter no tiene permiso para usar este modelo.', 503, 'openrouter_permisos');
        $hablaDeFormato = str_contains($mensajeProveedor, 'response_format') || str_contains($mensajeProveedor, 'json_schema') || str_contains($mensajeProveedor, 'strict');
        $noCompatible = str_contains($mensajeProveedor, 'not support') || str_contains($mensajeProveedor, 'unsupported') || str_contains($mensajeProveedor, 'not available');
        if (($estado === 400 && $hablaDeFormato && $noCompatible)
            || ($estado === 404 && str_contains($mensajeProveedor, 'requested parameters'))) {
            throw new ErrorAplicacion('El modelo no admite este formato de JSON.', 502, 'openrouter_formato_no_compatible');
        }
        if ($estado === 404 || str_contains($codigo, 'model_not_found') || str_contains($mensajeProveedor, 'not a valid model id')) {
            throw new ErrorAplicacion('OpenRouter no tiene un proveedor disponible para este modelo. Reintenta o revisa OPENROUTER_MODEL en configuracion/servidor.php.', 503, 'openrouter_modelo');
        }
        if ($estado === 413 || str_contains($codigo, 'context_length') || str_contains($mensajeProveedor, 'context length') || str_contains($mensajeProveedor, 'request too large')) {
            throw new ErrorAplicacion('La solicitud supera el límite de tokens del modelo o de la cuenta. Reduce el documento o ajusta el presupuesto de OpenRouter.', 422, 'openrouter_contexto');
        }
        if ($codigo === 'json_validate_failed' || $codigo === 'json_generation_failed') throw new ErrorAplicacion('OpenRouter no pudo completar el formato JSON.', 502, 'openrouter_json');
        if ($estado >= 500) throw new ErrorAplicacion('OpenRouter no está disponible temporalmente. Inténtalo más tarde.', 503, 'openrouter_servidor');
        throw new ErrorAplicacion('OpenRouter rechazó la solicitud. Comprueba que el modelo sea de generación de texto y admita JSON.', 502, 'openrouter_solicitud');
    }
}
