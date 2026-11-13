<?php
declare(strict_types=1);

namespace EvaluaEIC\Servicios;

final class InformeResultados extends \tFPDF {
    public const ENCABEZADO = [
        'República de Honduras',
        'Secretaría de Seguridad',
        'Dirección General de Policía Nacional',
        'Universidad Nacional de la Policía de Honduras',
        'ESCUELA DE INVESTIGACIÓN CRIMINAL',
    ];
    public const COLUMNAS = [
        'Nombre del estudiante' => 232,
        'Correctas' => 65,
        'Incorrectas' => 69,
        'Sin responder' => 82,
        'Puntaje' => 68,
    ];
    public const COLUMNAS_INDIVIDUALES = [
        'N.º' => 26,
        'Pregunta' => 189,
        'Respuesta marcada' => 122,
        'Respuesta correcta' => 131,
        'Resultado' => 72,
    ];
    private const MARGEN = 48;
    private const ALTO_LINEA = 15;

    public static function generar(array $datos): string {
        $datos['titulo'] = preg_replace('/\s+/u', ' ', $datos['titulo']);
        $datos['docente'] = preg_replace('/\s+/u', ' ', $datos['docente']);
        $pdf = new self('P', 'pt', 'Letter');
        $individual = isset($datos['participante']);
        $margen = $individual ? 36 : self::MARGEN;
        $pdf->SetMargins($margen, $individual ? 32 : 42, $margen);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetLineWidth(0.5);
        $pdf->SetTitle($datos['titulo'], true);
        $pdf->prepararFuente();
        if (isset($datos['codigo_salida'])) {
            foreach ($datos['tablas'] as $indice => $tabla) $pdf->tablaAdicional($datos, $tabla, $indice === 0);
            return $pdf->Output('S');
        }
        $pdf->pagina($datos, true);

        if ($individual) {
            foreach ($datos['filas'] as $fila) {
                $pdf->filaDetallada(array_values($fila), self::COLUMNAS_INDIVIDUALES, fn() => $pdf->pagina($datos, false));
            }
            return $pdf->Output('S');
        }

        $ultimo = array_key_last($datos['filas']);
        if ($ultimo === null) {
            $pdf->Cell(0, 24, 'No hay estudiantes registrados.', 1, 1, 'C');
        }
        foreach ($datos['filas'] as $indice => $fila) {
            $nombre = $pdf->ajustarTexto($fila['nombre'], self::COLUMNAS['Nombre del estudiante']);
            $alto = count($nombre) * self::ALTO_LINEA + 8;
            // Reserva un espacio para firmar al pie de la última página.
            $limite = $indice === $ultimo ? 646 : 722;
            if ($pdf->GetY() + $alto > $limite) $pdf->pagina($datos, false);
            $pdf->fila($nombre, $alto, $fila);
        }
        $pdf->firma($datos['docente']);
        foreach ($datos['tablas'] ?? [] as $tabla) $pdf->tablaAdicional($datos, $tabla, false);
        return $pdf->Output('S');
    }

    private function prepararFuente(): void {
        $fuentes = RAIZ . '/aplicacion/Recursos/fuentes/';
        $windows = getenv('WINDIR');
        $times = entorno('PDF_FUENTES_TIMES', $windows ? $windows . '/Fonts' : '');
        $times = rtrim($times, '/\\') . '/';
        $usaTimes = is_readable($times . 'times.ttf') && is_readable($times . 'timesbd.ttf');
        if (!defined('_SYSTEM_TTFONTS')) define('_SYSTEM_TTFONTS', $usaTimes ? $times : $fuentes);
        // Las fuentes se leen del directorio indicado. Evita dejar cachés con rutas del servidor en las bibliotecas.
        $this->fontpath = $fuentes;
        $this->AddFont('Informe', '', $usaTimes ? 'times.ttf' : 'LiberationSerif-Regular.ttf', true);
        $this->AddFont('Informe', 'B', $usaTimes ? 'timesbd.ttf' : 'LiberationSerif-Bold.ttf', true);
    }

    private function encabezado(array $datos, bool $primera): void {
        $this->AddPage();
        $individual = isset($datos['participante']);
        if ($primera) {
            $tamanoLogo = $individual ? 46 : 56;
            $this->Image(RAIZ . '/publico/imagenes/logo-eic.png', ($this->GetPageWidth() - $tamanoLogo) / 2, $this->GetY(), $tamanoLogo, $tamanoLogo);
            $this->SetY($individual ? 84 : 108);
            $this->SetFont('Informe', '', 12);
            foreach (array_slice(self::ENCABEZADO, 0, -1) as $linea) {
                $this->Cell(0, $individual ? 13 : 15, $linea, 0, 1, 'C');
            }
        }
        $this->SetFont('Informe', 'B', 12);
        $this->Cell(0, 16, self::ENCABEZADO[4], 0, 1, 'C');
        $this->Ln($individual ? 7 : 12);
        $titulo = isset($datos['codigo_salida']) ? 'COMPARACIÓN DE ENTRADA Y SALIDA'
            : ($individual ? 'FICHA INDIVIDUAL DE RESPUESTAS' : 'RESULTADOS DE LA EVALUACIÓN');
        $this->Cell(0, 16, $titulo, 0, 1, 'C');
        $this->MultiCell(0, 16, $datos['titulo'], 0, 'C');
        $this->SetFont('Informe', '', 12);
        if ($individual) $this->MultiCell(0, 16, 'Participante: ' . $datos['participante'], 0, 'C');
        $salas = isset($datos['codigo_salida']) ? 'Entrada ' . $datos['codigo'] . ' / Salida ' . $datos['codigo_salida']
            : 'Sala ' . $datos['codigo'] . (isset($datos['etapa']) ? ' / ' . $datos['etapa'] : '');
        $this->Cell(0, 16, $salas, 0, 1, 'C');
        $this->Ln($individual ? 7 : 12);
    }
    private function pagina(array $datos, bool $primera): void {
        $this->encabezado($datos, $primera);
        $this->cabeceraTabla(isset($datos['participante']) ? self::COLUMNAS_INDIVIDUALES : self::COLUMNAS);
    }
    private function cabeceraTabla(array $columnas): void {
        $this->SetFont('Informe', 'B', 12);
        $this->SetFillColor(232, 238, 245);
        foreach ($columnas as $titulo => $ancho) {
            $this->Cell($ancho, 23, $titulo, 1, 0, 'C', true);
        }
        $this->Ln();
        $this->SetFont('Informe', '', 12);
    }

    private function tablaAdicional(array $datos, array $tabla, bool $primera): void {
        $abrirPagina = function (bool $portada = false) use ($datos, $tabla): void {
            $this->encabezado($datos, $portada);
            if ($portada) {
                foreach ($datos['resumen'] ?? [] as $linea) $this->MultiCell(0, 15, $linea);
                $this->Ln(7);
            }
            $this->SetFont('Informe', 'B', 12);
            $this->MultiCell(0, 16, $tabla['titulo']);
            $this->cabeceraTabla($tabla['columnas']);
        };
        $abrirPagina($primera);
        foreach ($tabla['filas'] as $fila) $this->filaDetallada($fila, $tabla['columnas'], $abrirPagina);
    }

    private function ajustarTexto(string $texto, int $ancho): array {
        $ancho -= 2 * $this->cMargin;
        $lineas = [];
        $linea = '';
        foreach (preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) as $palabra) {
            $candidata = $linea === '' ? $palabra : $linea . ' ' . $palabra;
            if ($this->GetStringWidth($candidata) <= $ancho) {
                $linea = $candidata;
                continue;
            }
            if ($linea !== '') $lineas[] = $linea;
            $linea = '';
            foreach (mb_str_split($palabra) as $letra) {
                if ($this->GetStringWidth($linea . $letra) > $ancho) {
                    $lineas[] = $linea;
                    $linea = '';
                }
                $linea .= $letra;
            }
        }
        $lineas[] = $linea;
        return $lineas;
    }

    private function filaDetallada(array $fila, array $columnas, callable $abrirPagina): void {
        $anchos = array_values($columnas);
        $celdas = [];
        foreach (array_values($fila) as $columna => $valor) {
            $celdas[] = $this->ajustarTexto((string) $valor, $anchos[$columna]);
        }
        $total = max(array_map('count', $celdas));
        $interlineado = 13;
        $relleno = 4;
        $limite = 728;
        $inicio = 0;
        while ($inicio < $total) {
            $espacio = (int) floor(($limite - $this->GetY() - $relleno) / $interlineado);
            $restantes = $total - $inicio;
            // Mantiene las preguntas habituales juntas y divide las excepcionalmente largas.
            if ($espacio < min($restantes, 28)) {
                $abrirPagina();
                $espacio = (int) floor(($limite - $this->GetY() - $relleno) / $interlineado);
            }
            $lineas = min($espacio, $restantes);
            $alto = $lineas * $interlineado + $relleno;
            $x = $this->GetX();
            $y = $this->GetY();
            foreach ($celdas as $columna => $textos) {
                $ancho = $anchos[$columna];
                $this->Rect($x, $y, $ancho, $alto);
                foreach (array_slice($textos, $inicio, $lineas) as $n => $linea) {
                    $this->SetXY($x, $y + $relleno / 2 + $n * $interlineado);
                    $this->Cell($ancho, $interlineado, $linea, 0, 0, in_array($columna, [0, 4], true) ? 'C' : 'L');
                }
                $x += $ancho;
            }
            $this->SetY($y + $alto);
            $inicio += $lineas;
        }
    }

    private function fila(array $nombre, int $alto, array $fila): void {
        $x = $this->GetX();
        $y = $this->GetY();
        $anchos = array_values(self::COLUMNAS);
        $this->Rect($x, $y, $anchos[0], $alto);
        $this->SetY($y + 4);
        foreach ($nombre as $linea) {
            $this->Cell($anchos[0], self::ALTO_LINEA, $linea, 0, 1);
        }
        $this->SetXY($x + $anchos[0], $y);
        $valores = [$fila['aciertos'], $fila['incorrectas'], $fila['sin_responder'], $fila['puntos']];
        foreach ($valores as $indice => $valor) {
            $this->Cell($anchos[$indice + 1], $alto, (string) $valor, 1, 0, 'C');
        }
        $this->SetY($y + $alto);
    }

    private function firma(string $docente): void {
        $this->Ln(42);
        $centro = $this->GetPageWidth() / 2;
        $this->Line($centro - 103, $this->GetY(), $centro + 103, $this->GetY());
        $this->Ln(6);
        $this->MultiCell(0, 15, $docente, 0, 'C');
        $this->Cell(0, 15, 'Firma del docente', 0, 1, 'C');
    }

    public function Footer(): void {
        $this->SetY(-38);
        $this->SetFont('Informe', '', 12);
        $this->Cell(0, 15, 'Página ' . $this->PageNo(), 0, 0, 'C');
    }
}
