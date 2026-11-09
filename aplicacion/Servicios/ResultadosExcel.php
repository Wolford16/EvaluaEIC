<?php
declare(strict_types=1);

namespace EvaluaEIC\Servicios;

use Shuchkin\SimpleXLSXGen;

final class ResultadosExcel {
    public static function generar(array $datos): string {
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
        $filas[] = ['<center>' . self::texto('Sala ' . $datos['codigo']) . '</center>'];
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
        return (string) $excel;
    }

    private static function texto(string $valor): string {
        $valor = preg_replace('/\s+/u', ' ', $valor);
        // El contenido de evaluaciones y nombres se escribe como texto literal en Excel.
        return '<raw>' . htmlspecialchars($valor, ENT_XML1 | ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8') . '</raw>';
    }
}
