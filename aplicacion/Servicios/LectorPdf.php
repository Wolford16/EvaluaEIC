<?php
declare(strict_types=1);
namespace EvaluaIA\Servicios;
final class LectorPdf {
    public static function analizar(string $ruta): array {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) throw new ErrorAplicacion('Falta instalar el lector PDF. Ejecuta el paso de Composer indicado en LEEME.md.', 503);
        try {
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($ruta);
            return ['paginas' => count($pdf->getPages()), 'texto' => trim($pdf->getText())];
        } catch (\Throwable $e) {
            throw new ErrorAplicacion('No se pudo leer este PDF. Comprueba que no esté dañado ni protegido con contraseña.');
        }
    }
}
