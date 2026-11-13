<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;

/** Punto de entrada de la IA de EvaluaEIC: OpenRouter es el único proveedor. */
final class InteligenciaArtificial {
    public static function proveedores(): array {
        $clave = trim(entorno('OPENROUTER_API_KEY')) !== '';
        $modelo = trim(entorno('OPENROUTER_MODEL'));
        return [[
            'id'=>'openrouter', 'nombre'=>'OpenRouter', 'modelo'=>$modelo,
            'configurado'=>$clave && $modelo !== '', 'clave_configurada'=>$clave,
            'modelo_configurado'=>$modelo !== '', 'activo'=>true,
        ]];
    }

    public static function validarMotor(string $motor): void {
        if ($motor !== 'openrouter') throw new ErrorAplicacion('EvaluaEIC usa OpenRouter para generar preguntas.');
        (new ClienteOpenRouter())->validarConfiguracion();
    }

    public static function generar(string $texto, int $cantidad): array {
        return (new GeneradorOpenRouter(new ClienteOpenRouter()))->generar($texto, $cantidad);
    }

    public static function validarPreguntas(mixed $preguntas, int $cantidadOpciones = 4): array {
        if (!is_array($preguntas) || !es_lista($preguntas) || count($preguntas) < 1 || count($preguntas) > 30) {
            throw new ErrorAplicacion('Guarda entre 1 y 30 preguntas.');
        }
        $resultado = [];
        foreach ($preguntas as $pregunta) {
            if (!is_array($pregunta)) throw new ErrorAplicacion('Revisa el formato de las preguntas.');
            $contenido = Preguntas::validarOpciones($pregunta, $cantidadOpciones);
            $resultado[] = [
                'enunciado'=>Seguridad::texto($pregunta['enunciado'] ?? '', 5, 3000, 'Enunciado'),
                'explicacion'=>Seguridad::texto($pregunta['explicacion'] ?? '', 1, 3000, 'Explicación'),
            ] + $contenido;
        }
        self::validarSinDuplicados($resultado);
        return $resultado;
    }

    public static function validarSinDuplicados(array $preguntas): void {
        Preguntas::validarSinDuplicados($preguntas, 'openrouter_formato');
    }

    public static function probar(string $motor = 'openrouter'): array {
        self::validarMotor($motor);
        $inicio = microtime(true);
        $cliente = new ClienteOpenRouter();
        $metadatos = $cliente->metadatos();
        $esquema = ['type'=>'object','properties'=>['estado'=>['type'=>'string','enum'=>['ok']]],'required'=>['estado'],'additionalProperties'=>false];
        $resultado = $cliente->completar([
            ['role'=>'system','content'=>'Prueba técnica de conexión. Devuelve únicamente JSON {"estado":"ok"}.'],
            ['role'=>'user','content'=>'EvaluaEIC prepara evaluaciones. Confirma la recepción de este texto breve.'],
        ], $esquema, min(1024, $metadatos['salida']), 20);
        if (($resultado['estado'] ?? null) !== 'ok') throw new ErrorAplicacion('El modelo respondió, pero no superó la prueba JSON.', 502, 'openrouter_formato');
        return [
            'mensaje'=>'Clave, modelo y generación verificados.', 'modelo'=>$cliente->modelo,
            'milisegundos'=>(int)round((microtime(true) - $inicio) * 1000),
            'formato'=>$cliente->formato, 'tokens'=>$cliente->tokensUsados,
        ];
    }
}
