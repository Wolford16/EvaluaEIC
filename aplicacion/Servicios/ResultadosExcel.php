<?php
declare(strict_types=1);

namespace EvaluaEIC\Servicios;

use Shuchkin\SimpleXLSXGen;

final class ResultadosExcel {
    public static function generar(array $datos): string {
        if (isset($datos['codigo_salida'])) {
            $excel = (new SimpleXLSXGen())->setDefaultFont('Times New Roman')->setDefaultFontSize(12);
            self::agregarTablas($excel, $datos);
            return (string) $excel;
        }
        $individual = isset($datos['participante']);
        $filas = [];
        foreach (InformeResultados::ENCABEZADO as $n => $linea) {
            $texto = self::texto($linea);
            if ($n === array_key_last(InformeResultados::ENCABEZADO)) $texto = '<b>' . $texto . '</b>';
            $filas[] = ['<center>' . $texto . '</center>'];
        }
        $filas[] = [];
        $filas[] = ['<center><b>' . ($individual ? 'FICHA INDIVIDUAL DE RESPUESTAS' : 'RESULTADOS DE LA EVALUACIÓN') . '</b></center>'];
        $filas[] = ['<middle><center><b><wraptext><style height="32">' . self::texto($datos['titulo']) . '</style></wraptext></b></center></middle>'];
        if ($individual) $filas[] = ['<center>' . self::texto('Participante: ' . $datos['participante']) . '</center>'];
        $filas[] = ['<center>' . self::texto('Sala ' . $datos['codigo'] . (isset($datos['etapa']) ? ' / ' . $datos['etapa'] : '')) . '</center>'];
        $filas[] = [];
        $encabezado = count($filas) + 1;
        $columnas = $individual ? InformeResultados::COLUMNAS_INDIVIDUALES : InformeResultados::COLUMNAS;
        $filas[] = array_map(
            static fn(string $titulo): string => '<style bgcolor="#12365A" color="#FFFFFF" height="22"><b><center>' . $titulo . '</center></b></style>',
            array_keys($columnas)
        );
        $anchos = $individual ? [7, 58, 42, 42, 19] : [48, 16, 16, 19, 15];
        foreach ($datos['filas'] as $fila) {
            if ($individual) {
                $alto = 16 * max(
                    (int) ceil(mb_strwidth($fila['pregunta']) / 49),
                    (int) ceil(mb_strwidth($fila['respuesta']) / 35),
                    (int) ceil(mb_strwidth($fila['correcta']) / 35)
                ) + 6;
                $estilo = '<style height="' . min(400, $alto) . '"><top><wraptext>';
                $filas[] = [
                    '<top><center>' . $fila['numero'] . '</center></top>',
                    $estilo . self::texto($fila['pregunta']) . '</wraptext></top></style>',
                    '<top><wraptext>' . self::texto($fila['respuesta']) . '</wraptext></top>',
                    '<top><wraptext>' . self::texto($fila['correcta']) . '</wraptext></top>',
                    '<top>' . self::texto($fila['resultado']) . '</top>',
                ];
            } else {
                $alto = 16 * max(1, (int) ceil(mb_strwidth($fila['nombre']) / 39)) + 6;
                $filas[] = [
                    '<style height="' . $alto . '"><wraptext>' . self::texto($fila['nombre']) . '</wraptext></style>',
                    $fila['aciertos'], $fila['incorrectas'], $fila['sin_responder'], $fila['puntos'],
                ];
            }
        }

        $excel = SimpleXLSXGen::fromArray($filas, $individual ? 'Ficha individual' : 'Resultados generales')
            ->setDefaultFont('Times New Roman')
            ->setDefaultFontSize(12)
            ->freezePanes('A' . ($encabezado + 1));
        for ($n = 1; $n < $encabezado; $n++) {
            if ($filas[$n - 1]) $excel->mergeCells('A' . $n . ':E' . $n);
        }
        foreach ($anchos as $n => $ancho) $excel->setColWidth($n + 1, $ancho);
        if (count($filas) > $encabezado) $excel->autoFilter('A' . $encabezado . ':E' . count($filas));
        self::agregarTablas($excel, $datos);
        return (string) $excel;
    }

    private static function agregarTablas(SimpleXLSXGen $excel, array $datos): void {
        foreach ($datos['tablas'] ?? [] as $indice => $tabla) {
            $filas = array_map(fn(string $linea) => [self::texto($linea)], InformeResultados::ENCABEZADO);
            $filas[] = [self::texto($datos['titulo'])];
            if (isset($datos['participante'])) $filas[] = [self::texto('Participante: ' . $datos['participante'])];
            $salas = isset($datos['codigo_salida']) ? 'Entrada ' . $datos['codigo'] . ' / Salida ' . $datos['codigo_salida']
                : 'Sala ' . $datos['codigo'] . ' / ' . $datos['etapa'];
            $filas[] = [self::texto($salas)];
            foreach ($datos['resumen'] ?? [] as $linea) $filas[] = [self::texto($linea)];
            $filas[] = [self::texto($tabla['titulo'])];
            $encabezado = count($filas) + 1;
            $filas[] = array_map(fn(string $columna) => '<b>' . self::texto($columna) . '</b>', array_keys($tabla['columnas']));
            foreach ($tabla['filas'] as $fila) {
                $filas[] = array_map(fn($valor) => is_int($valor) ? $valor : '<wraptext>' . self::texto($valor) . '</wraptext>', $fila);
            }
            $hoja = isset($datos['codigo_salida']) && $indice === 0 && !isset($datos['participante']) ? 'Comparación' : 'Por pregunta';
            $excel->addSheet($filas, $hoja)->freezePanes('A' . ($encabezado + 1));
            $ultimaColumna = chr(64 + count($tabla['columnas']));
            for ($n = 1; $n < $encabezado; $n++) $excel->mergeCells('A' . $n . ':' . $ultimaColumna . $n);
            foreach (array_values($tabla['columnas']) as $n => $ancho) $excel->setColWidth($n + 1, max(6, (int) ($ancho / 4)));
        }
    }

    private static function texto(string $valor): string {
        $valor = preg_replace('/\s+/u', ' ', $valor);
        // El contenido de evaluaciones y nombres se escribe como texto literal en Excel.
        return '<raw>' . htmlspecialchars($valor, ENT_XML1 | ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8') . '</raw>';
    }
}
