<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;

final class Comparacion {
    public static function resultados(string $codigo, array $usuario, ?int $participante = null): array {
        return BaseDatos::transaccion(function () use ($codigo, $usuario, $participante): array {
            $entrada = BaseDatos::bloquearSala($codigo);
            if (!$entrada || !$entrada['es_entrada']) throw new ErrorAplicacion('No encontramos el examen de entrada.', 404);
            if (!Evaluacion::autorizar($entrada, $usuario)) throw new ErrorAplicacion('No tienes acceso a esta evaluación.', 403);
            $salida = BaseDatos::ejecutar('SELECT * FROM salas_evaluacion WHERE entrada_id = ? FOR UPDATE', [$entrada['id']])->fetch();
            $preguntas = Evaluacion::contenido($entrada)['preguntas'];
            $total = count($preguntas);
            if (!$salida || Evaluacion::fase($salida, $total)['fase'] !== 'finalizada') {
                throw new ErrorAplicacion('La comparación estará disponible cuando termine la salida.', 409);
            }
            $datosEntrada = self::participantes((int) $entrada['id'], $total);
            $datosSalida = self::participantes((int) $salida['id'], $total);
            $pares = self::vincular($datosEntrada, $datosSalida);
            $datos = [
                'titulo' => $entrada['titulo'], 'docente' => $entrada['docente'],
                'codigo' => $entrada['codigo'], 'codigo_salida' => $salida['codigo'],
                'participantes' => [], 'resumen' => [], 'tablas' => [],
            ];
            if ($participante !== null) {
                foreach ($pares as [$antes, $despues]) {
                    if ($participante === (int) ($antes['id'] ?? 0) || $participante === (int) ($despues['id'] ?? 0)) {
                        return self::ficha($datos, $preguntas, $antes, $despues);
                    }
                }
                throw new ErrorAplicacion('No encontramos a ese participante en estas salas.', 404);
            }

            $filas = [];
            $aciertosEntrada = $aciertosSalida = array_fill(0, $total, 0);
            $totalEntrada = $totalSalida = $comparados = 0;
            foreach ($pares as [$antes, $despues]) {
                $id = (int) ($antes['id'] ?? $despues['id']);
                $nombre = $antes['nombre'] ?? $despues['nombre'];
                $diferencia = self::diferencia($antes, $despues, $total);
                $datos['participantes'][] = ['id'=>$id, 'nombre'=>$nombre, 'entrada'=>$antes['porcentaje'] ?? null,
                    'salida'=>$despues['porcentaje'] ?? null, 'diferencia'=>$diferencia];
                $filas[] = [$nombre, self::puntaje($antes), self::puntaje($despues), self::variacion($diferencia)];
                if ($diferencia === null) continue;
                $comparados++;
                $totalEntrada += $antes['aciertos'];
                $totalSalida += $despues['aciertos'];
                foreach (array_keys($preguntas) as $indice) {
                    $aciertosEntrada[$indice] += (int) ($antes['respuestas'][$indice]['correcta'] ?? 0);
                    $aciertosSalida[$indice] += (int) ($despues['respuestas'][$indice]['correcta'] ?? 0);
                }
            }
            $datos['resumen'][] = sprintf('Participantes en entrada: %d. En salida: %d. En ambas: %d.',
                count($datosEntrada), count($datosSalida), $comparados);
            $datos['resumen'][] = $comparados
                ? 'Cambio promedio de quienes completaron ambas: ' . self::variacion(round(100 * ($totalSalida - $totalEntrada) / ($comparados * $total), 1)) . '.'
                : 'Aún no hay participantes vinculados para calcular el cambio promedio.';
            $porPregunta = [];
            foreach ($preguntas as $indice => $pregunta) {
                $porPregunta[] = [$indice + 1, $pregunta['enunciado'], Evaluacion::solucionInforme($pregunta),
                    $comparados ? $aciertosEntrada[$indice] . ' de ' . $comparados : '—',
                    $comparados ? $aciertosSalida[$indice] . ' de ' . $comparados : '—',
                    self::variacion($comparados ? round(100 * ($aciertosSalida[$indice] - $aciertosEntrada[$indice]) / $comparados, 1) : null)];
            }
            $datos['tablas'] = [
                ['titulo'=>'Resultados por participante', 'columnas'=>['Participante'=>252,'Entrada'=>90,'Salida'=>90,'Cambio'=>84], 'filas'=>$filas],
                ['titulo'=>'Aciertos por pregunta de quienes completaron ambas', 'columnas'=>[
                    'N.º'=>25,'Pregunta'=>170,'Respuesta correcta'=>125,'Entrada'=>64,'Salida'=>64,'Cambio'=>68], 'filas'=>$porPregunta],
            ];
            return $datos;
        });
    }

    private static function participantes(int $salaId, int $total): array {
        $participantes = BaseDatos::ejecutar('SELECT id, nombre, participante_entrada_id FROM participantes WHERE sala_id = ? ORDER BY id', [$salaId])->fetchAll();
        $respuestas = BaseDatos::ejecutar(
            'SELECT r.participante_id, r.indice, r.opcion, r.texto, r.correcta
             FROM respuestas_participantes r JOIN participantes p ON p.id = r.participante_id WHERE p.sala_id = ? AND r.indice < ?',
            [$salaId, $total]
        )->fetchAll();
        $porParticipante = [];
        foreach ($respuestas as $respuesta) $porParticipante[$respuesta['participante_id']][$respuesta['indice']] = $respuesta;
        foreach ($participantes as &$persona) {
            $persona['respuestas'] = $porParticipante[$persona['id']] ?? [];
            $persona['aciertos'] = array_sum(array_column($persona['respuestas'], 'correcta'));
            $persona['porcentaje'] = round(100 * $persona['aciertos'] / $total, 1);
        }
        return $participantes;
    }

    private static function vincular(array $entrada, array $salida): array {
        $parejas = [];
        foreach ($entrada as $persona) $parejas[$persona['id']] = [$persona, null];
        foreach ($salida as $persona) {
            $idEntrada = $persona['participante_entrada_id'];
            if ($idEntrada !== null && isset($parejas[$idEntrada])) $parejas[$idEntrada][1] = $persona;
            else $parejas[] = [null, $persona];
        }
        return array_values($parejas);
    }

    private static function ficha(array $datos, array $preguntas, ?array $antes, ?array $despues): array {
        $datos['participante'] = $antes['nombre'] ?? $despues['nombre'];
        $datos['resumen'][] = 'Entrada: ' . self::puntaje($antes) . '. Salida: ' . self::puntaje($despues)
            . '. Cambio: ' . self::variacion(self::diferencia($antes, $despues, count($preguntas))) . '.';
        $filas = [];
        foreach ($preguntas as $indice => $pregunta) {
            $filas[] = [$indice + 1, $pregunta['enunciado'], Evaluacion::solucionInforme($pregunta),
                self::respuesta($pregunta, $antes, $indice), self::respuesta($pregunta, $despues, $indice)];
        }
        $datos['tablas'][] = ['titulo'=>'Respuestas por pregunta', 'columnas'=>[
            'N.º'=>25,'Pregunta'=>149,'Respuesta correcta'=>122,'Entrada'=>110,'Salida'=>110], 'filas'=>$filas];
        return $datos;
    }

    private static function respuesta(array $pregunta, ?array $persona, int $indice): string {
        if ($persona === null) return 'Pendiente';
        $fila = $persona['respuestas'][$indice] ?? [];
        $respuesta = isset($fila['opcion']) ? (int) $fila['opcion'] : ($fila['texto'] ?? null);
        if ($respuesta === null) return 'Sin responder';
        return Evaluacion::respuestaInforme($pregunta, $respuesta) . ' (' . ($fila['correcta'] ? 'Correcta' : 'Incorrecta') . ')';
    }

    private static function diferencia(?array $antes, ?array $despues, int $total): ?float {
        return $antes && $despues ? round(100 * ($despues['aciertos'] - $antes['aciertos']) / $total, 1) : null;
    }

    private static function puntaje(?array $persona): string {
        return $persona === null ? 'Pendiente' : $persona['porcentaje'] . ' %';
    }

    private static function variacion(?float $diferencia): string {
        return $diferencia === null ? '—' : ($diferencia > 0 ? '+' : '') . $diferencia . ' pp';
    }
}
