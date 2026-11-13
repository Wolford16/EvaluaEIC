<?php
declare(strict_types=1);

namespace EvaluaEIC\Servicios;

final class Preguntas {
    public const OPCION_MULTIPLE = 'opcion_multiple';
    public const VERDADERO_FALSO = 'verdadero_falso';
    public const COMPLETACION = 'completacion';
    public const MAXIMO_TEXTO = 150;

    public static function validarOpciones(array $pregunta, int $cantidad): array {
        $opciones = $pregunta['opciones'] ?? [];
        if (!is_array($opciones) || !es_lista($opciones) || count($opciones) !== $cantidad) {
            throw new ErrorAplicacion("Cada pregunta debe tener {$cantidad} opciones.");
        }
        $opciones = array_map(fn($opcion) => Seguridad::texto($opcion, 1, 1500, 'Opción'), $opciones);
        if (count(array_unique(array_map('mb_strtolower', $opciones))) !== $cantidad) {
            throw new ErrorAplicacion('Las opciones deben ser diferentes.');
        }
        $ultimoIndice = $cantidad - 1;
        if (!is_int($pregunta['correcta'] ?? null)) {
            throw new ErrorAplicacion("La respuesta correcta debe ser un índice entero de 0 a {$ultimoIndice}.");
        }
        return [
            'opciones' => $opciones,
            'correcta' => Seguridad::entero($pregunta['correcta'], 0, $ultimoIndice, 'respuesta correcta'),
        ];
    }

    public static function normalizarRespuesta(string $texto): string {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $texto)));
    }

    public static function textoRespuesta(mixed $texto): string {
        $texto = Seguridad::texto($texto, 1, self::MAXIMO_TEXTO, 'Respuesta de completación');
        if (!mb_check_encoding($texto, 'UTF-8') || self::normalizarRespuesta($texto) === '') {
            throw new ErrorAplicacion('Escribe una respuesta válida.');
        }
        return $texto;
    }

    public static function validarManuales(mixed $preguntas): array {
        if (!is_array($preguntas) || !es_lista($preguntas) || count($preguntas) < 1 || count($preguntas) > 30) {
            throw new ErrorAplicacion('Guarda entre 1 y 30 preguntas.');
        }
        $resultado = [];
        foreach ($preguntas as $indice => $pregunta) {
            try {
                $resultado[] = self::validarManual($pregunta);
            } catch (ErrorAplicacion $error) {
                throw new ErrorAplicacion('Pregunta ' . ($indice + 1) . '. ' . $error->getMessage());
            }
        }
        self::validarSinDuplicados($resultado);
        return $resultado;
    }

    private static function validarManual(mixed $pregunta): array {
        if (!is_array($pregunta)) throw new ErrorAplicacion('Revisa el formato de la pregunta.');
        $tipo = $pregunta['tipo'] ?? null;
        $contenido = match ($tipo) {
            self::OPCION_MULTIPLE => self::validarOpciones($pregunta, 4),
            self::VERDADERO_FALSO => self::validarOpciones(['opciones' => ['Verdadero', 'Falso'], 'correcta' => $pregunta['correcta'] ?? null], 2),
            self::COMPLETACION => ['opciones' => [], 'correcta' => null,
                'respuestas_aceptadas' => self::validarAceptadas($pregunta['respuestas_aceptadas'] ?? null)],
            default => throw new ErrorAplicacion('Selecciona un tipo de pregunta.'),
        };
        return [
            'tipo' => $tipo,
            'enunciado' => Seguridad::texto($pregunta['enunciado'] ?? '', 5, 3000, 'Enunciado'),
            'explicacion' => Seguridad::texto($pregunta['explicacion'] ?? '', 0, 3000, 'Explicación'),
        ] + $contenido;
    }

    private static function validarAceptadas(mixed $respuestas): array {
        if (!is_array($respuestas) || !es_lista($respuestas) || count($respuestas) < 1 || count($respuestas) > 10) {
            throw new ErrorAplicacion('Escribe entre 1 y 10 respuestas aceptadas.');
        }
        $aceptadas = [];
        foreach ($respuestas as $respuesta) {
            $texto = self::textoRespuesta($respuesta);
            $clave = self::normalizarRespuesta($texto);
            if (isset($aceptadas[$clave])) throw new ErrorAplicacion('Las respuestas aceptadas deben ser diferentes.');
            $aceptadas[$clave] = $texto;
        }
        return array_values($aceptadas);
    }

    public static function validarSinDuplicados(array $preguntas, ?string $codigo = null): void {
        $vistos = [];
        foreach ($preguntas as $pregunta) {
            $clave = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($pregunta['enunciado'])));
            if (isset($vistos[$clave])) {
                throw new ErrorAplicacion('Hay preguntas con el mismo enunciado. Edita o elimina las repetidas.', 422, $codigo);
            }
            $vistos[$clave] = true;
        }
    }
}
