<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;
final class DatosIniciales {
    public static function preguntas(): array {
        return [
            ['enunciado'=>'¿Qué garantiza la continuidad de la cadena de custodia de una evidencia?', 'opciones'=>['El registro de cada entrega, recepción y responsable','La entrega verbal sin dejar constancia','El almacenamiento sin identificación','La circulación libre entre oficinas'], 'correcta'=>0,'explicacion'=>'El registro de los responsables y de cada transferencia permite seguir el recorrido de la evidencia.'],
            ['enunciado'=>'¿Qué debe incluir la identificación de un indicio recolectado?', 'opciones'=>['Solamente el nombre del investigador','Un código único y la descripción del objeto','Una referencia verbal sin documentación','Únicamente una fotografía general'], 'correcta'=>1,'explicacion'=>'La identificación única y la descripción permiten distinguir un indicio de los demás.'],
            ['enunciado'=>'¿Cuál es el propósito de documentar el estado inicial de un indicio?', 'opciones'=>['Sustituir todos los análisis posteriores','Acelerar su eliminación','Permitir la comparación y detectar cambios posteriores','Evitar que se registre su traslado'], 'correcta'=>2,'explicacion'=>'Registrar el estado inicial ayuda a comparar la evidencia y detectar alteraciones durante su manejo.'],
            ['enunciado'=>'¿Qué práctica ayuda a proteger la integridad de una evidencia?', 'opciones'=>['Mezclar indicios sin identificar','Abrir el embalaje sin dejar registro','Permitir acceso sin control','Usar embalaje adecuado, sellado e identificación'], 'correcta'=>3,'explicacion'=>'El embalaje, el sellado y la identificación ayudan a preservar la integridad y trazabilidad del indicio.'],
        ];
    }
    public static function crearPdf(string $ruta): void {
        // Material breve de demostración con texto seleccionable y sin dependencias de diseño.
        $lineas=['EVALUAEIC - MATERIAL DE DEMOSTRACION','Investigacion y cadena de custodia','',
            'La cadena de custodia documenta el recorrido de una evidencia.',
            'Cada entrega y recepcion debe registrar a sus responsables.',
            'Cada indicio se identifica con un codigo unico y una descripcion.',
            'El estado inicial se documenta para detectar cambios posteriores.',
            'El embalaje adecuado, el sellado y la identificacion protegen el indicio.',
            '', 'Este documento es un ejemplo para probar el funcionamiento de EvaluaEIC.'];
        $texto="BT /F1 12 Tf 50 780 Td 18 TL ";
        foreach($lineas as $linea) $texto.='('.str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$linea).") Tj T*\n";
        $texto.='ET';
        $objetos=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '.strlen($texto).">>\nstream\n".$texto."\nendstream"];
        $pdf="%PDF-1.4\n";$posiciones=[0];
        foreach($objetos as $indice=>$objeto){$posiciones[]=strlen($pdf);$pdf.=($indice+1)." 0 obj\n".$objeto."\nendobj\n";}
        $inicio=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
        foreach(array_slice($posiciones,1) as $posicion)$pdf.=sprintf("%010d 00000 n \n",$posicion);
        $pdf.="trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$inicio}\n%%EOF";
        file_put_contents($ruta,$pdf);
    }
}
