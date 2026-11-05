<?php
declare(strict_types=1);
namespace EvaluaIA\Servicios;

/** Punto de entrada de la IA de EvaluaEIC: Groq es el único proveedor. */
final class InteligenciaArtificial {
    public static function proveedores(): array {
        $clave = trim(entorno('GROQ_API_KEY')) !== '';
        $modelo = trim(entorno('GROQ_MODEL'));
        return [[
            'id'=>'groq', 'nombre'=>'Groq', 'modelo'=>$modelo,
            'configurado'=>$clave && $modelo !== '', 'clave_configurada'=>$clave,
            'modelo_configurado'=>$modelo !== '', 'activo'=>true,
        ]];
    }

    public static function validarMotor(string $motor): void {
        if ($motor !== 'groq') throw new ErrorAplicacion('Selecciona Groq o redacción manual.');
        (new ClienteGroq())->validarConfiguracion();
    }

    public static function generar(string $texto, int $cantidad, string $motor = 'groq'): array {
        self::validarMotor($motor);
        return (new GeneradorGroq(new ClienteGroq()))->generar($texto, $cantidad);
    }

    public static function validarPreguntas(mixed $preguntas): array {
        if (!is_array($preguntas) || !array_is_list($preguntas) || count($preguntas) < 1 || count($preguntas) > 30) {
            throw new ErrorAplicacion('Guarda entre 1 y 30 preguntas.');
        }
        $resultado = [];
        foreach ($preguntas as $pregunta) {
            if (!is_array($pregunta)) throw new ErrorAplicacion('Revisa el formato de las preguntas.');
            $opciones = $pregunta['opciones'] ?? [];
            if (!is_array($opciones) || !array_is_list($opciones) || count($opciones) !== 4) {
                throw new ErrorAplicacion('Cada pregunta debe tener cuatro opciones.');
            }
            $opciones = array_map(fn($opcion) => Seguridad::texto($opcion, 1, 1500, 'Opción'), $opciones);
            if (count(array_unique(array_map('mb_strtolower', $opciones))) !== 4) {
                throw new ErrorAplicacion('Las cuatro opciones deben ser diferentes.');
            }
            if (!is_int($pregunta['correcta'] ?? null)) throw new ErrorAplicacion('La respuesta correcta debe ser un índice entero de 0 a 3.');
            $resultado[] = [
                'enunciado'=>Seguridad::texto($pregunta['enunciado'] ?? '', 5, 3000, 'Enunciado'),
                'opciones'=>$opciones,
                'correcta'=>Seguridad::entero($pregunta['correcta'], 0, 3, 'respuesta correcta'),
                'explicacion'=>Seguridad::texto($pregunta['explicacion'] ?? '', 1, 3000, 'Explicación'),
            ];
        }
        self::validarSinDuplicados($resultado);
        return $resultado;
    }

    public static function validarSinDuplicados(array $preguntas): void {
        $vistos = [];
        foreach ($preguntas as $pregunta) {
            $clave = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($pregunta['enunciado'])));
            if (isset($vistos[$clave])) throw new ErrorAplicacion('Hay preguntas con el mismo enunciado. Edita o elimina las repetidas.', 422, 'groq_formato');
            $vistos[$clave] = true;
        }
    }

    public static function probar(string $motor = 'groq'): array {
        self::validarMotor($motor);
        $inicio = microtime(true);
        $cliente = new ClienteGroq();
        $metadatos = $cliente->metadatos();
        $esquema = ['type'=>'object','properties'=>['estado'=>['type'=>'string','enum'=>['ok']]],'required'=>['estado'],'additionalProperties'=>false];
        $resultado = $cliente->completar([
            ['role'=>'system','content'=>'Prueba técnica de conexión. Devuelve únicamente JSON {"estado":"ok"}.'],
            ['role'=>'user','content'=>'EvaluaEIC prepara evaluaciones. Confirma la recepción de este texto breve.'],
        ], $esquema, min(1024, $metadatos['salida']), 20);
        if (($resultado['estado'] ?? null) !== 'ok') throw new ErrorAplicacion('El modelo respondió, pero no superó la prueba JSON.', 502, 'groq_formato');
        return [
            'mensaje'=>'Clave, modelo y generación verificados.', 'modelo'=>$cliente->modelo,
            'milisegundos'=>(int)round((microtime(true) - $inicio) * 1000),
            'formato'=>$cliente->formato, 'tokens'=>$cliente->tokensUsados,
        ];
    }
}
