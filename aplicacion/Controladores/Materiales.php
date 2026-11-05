<?php
declare(strict_types=1);
namespace EvaluaIA\Controladores;
use EvaluaIA\Servicios\{BaseDatos, Seguridad, ErrorAplicacion, LectorPdf, InteligenciaArtificial};
final class Materiales {
    public static function listar(array $usuario): array {
        $condicion = $usuario['rol']==='admin' ? '' : ' WHERE d.subido_por_id = ?';
        $documentos=BaseDatos::ejecutar('SELECT d.*,u.nombre AS autor,(SELECT COUNT(*) FROM preguntas p WHERE p.documento_id=d.id) AS cantidad FROM documentos d JOIN usuarios u ON u.id=d.subido_por_id'.$condicion.' ORDER BY d.id DESC',$condicion ? [$usuario['id']] : [])->fetchAll();
        return ['documentos'=>$documentos];
    }
    public static function detalle(int $id,array $usuario): array {
        $documento=Seguridad::documento($id,$usuario);
        $preguntas=BaseDatos::ejecutar('SELECT * FROM preguntas WHERE documento_id=? ORDER BY id',[$id])->fetchAll();
        foreach ($preguntas as &$p) { $p['opciones']=json_decode($p['opciones_json'],true); unset($p['opciones_json']); }
        $salas=BaseDatos::ejecutar('SELECT id,codigo,titulo,tiempo,estado,creado_en FROM salas_evaluacion WHERE documento_id=? ORDER BY id DESC',[$id])->fetchAll();
        return ['documento'=>$documento,'preguntas'=>$preguntas,'salas'=>$salas];
    }
    public static function preparar(array $usuario): array {
        $cantidad=Seguridad::entero($_POST['cantidad']??10,1,30,'cantidad de preguntas');
        $titulo=Seguridad::texto($_POST['titulo']??'',3,200,'Título');
        $motor=Seguridad::texto($_POST['motor']??'openrouter',1,20,'Preparación');
        InteligenciaArtificial::validarMotor($motor);
        $archivo=$_FILES['archivo']??null;
        if (!$archivo || $archivo['error']!==UPLOAD_ERR_OK) throw new ErrorAplicacion('Selecciona un PDF de hasta 12 MB. Si es grande, revisa los límites de PHP.');
        if ($archivo['size']>12*1024*1024 || $archivo['size']<5) throw new ErrorAplicacion('El PDF debe pesar como máximo 12 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        if (strtolower(pathinfo($archivo['name'],PATHINFO_EXTENSION))!=='pdf' || $mime!=='application/pdf' || file_get_contents($archivo['tmp_name'],false,null,0,5)!=='%PDF-') throw new ErrorAplicacion('El archivo debe ser un PDF válido.');
        $directorio=RAIZ.'/almacenamiento/documentos';
        if (!is_dir($directorio)) mkdir($directorio,0700,true);
        $token=bin2hex(random_bytes(24)); $ruta=$directorio.'/'.$token.'.pdf';
        if (!move_uploaded_file($archivo['tmp_name'],$ruta)) throw new ErrorAplicacion('No se pudo guardar el archivo.',500);
        try {
            $lectura=LectorPdf::analizar($ruta);
            $generacion=InteligenciaArtificial::generar($lectura['texto'],$cantidad);
            $preguntas=$generacion['preguntas']; $analisis=$generacion['analisis'];
            BaseDatos::ejecutar('INSERT INTO borradores (token,usuario_id,titulo,nombre_archivo,ruta_archivo,tamano,paginas,preguntas_json,creado_en) VALUES (?,?,?,?,?,?,?,?,?)',[$token,$usuario['id'],$titulo,mb_substr(basename($archivo['name']),0,255),$token.'.pdf',$archivo['size'],$lectura['paginas'],json_encode($preguntas,JSON_UNESCAPED_UNICODE),microtime(true)]);
            return ['token'=>$token,'titulo'=>$titulo,'paginas'=>$lectura['paginas'],'preguntas'=>$preguntas,'analisis'=>$analisis];
        } catch (\Throwable $e) { if (is_file($ruta)) unlink($ruta); throw $e; }
    }
    public static function guardar(array $datos,array $usuario): array {
        $token=Seguridad::texto($datos['token']??'',48,48,'Borrador');
        if (!ctype_xdigit($token)) throw new ErrorAplicacion('El identificador del borrador no es válido.');
        return BaseDatos::transaccion(function() use ($token,$usuario,$datos) {
            $esMysql=BaseDatos::conexion()->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
            if (!$esMysql) BaseDatos::ejecutar('UPDATE borradores SET token=token WHERE token=? AND usuario_id=?',[$token,$usuario['id']]);
            $b=BaseDatos::ejecutar('SELECT * FROM borradores WHERE token=? AND usuario_id=?'.($esMysql?' FOR UPDATE':''),[$token,$usuario['id']])->fetch();
            if (!$b) {
                $existente=BaseDatos::ejecutar('SELECT id FROM documentos WHERE ruta_archivo=? AND subido_por_id=?',[$token.'.pdf',$usuario['id']])->fetchColumn();
                if ($existente) return ['id'=>(int)$existente,'mensaje'=>'El material ya estaba guardado.','reutilizado'=>true];
                throw new ErrorAplicacion('El borrador venció. Vuelve a cargar el PDF.',404);
            }
            if (microtime(true)-$b['creado_en']>86400) throw new ErrorAplicacion('El borrador venció. Vuelve a cargar el PDF.',404);
            $originales=json_decode($b['preguntas_json'],true);
            $cantidadOpciones=count($originales[0]['opciones']??[])===2 ? 2 : 4;
            $preguntas=InteligenciaArtificial::validarPreguntas($datos['preguntas']??[],$cantidadOpciones);
            $titulo=Seguridad::texto($datos['titulo']??$b['titulo'],3,200,'Título');
            BaseDatos::ejecutar('INSERT INTO documentos (titulo,nombre_archivo,ruta_archivo,tamano,paginas,subido_por_id,creado_en) VALUES (?,?,?,?,?,?,?)',[$titulo,$b['nombre_archivo'],$b['ruta_archivo'],$b['tamano'],$b['paginas'],$usuario['id'],date('Y-m-d H:i:s')]);
            $id=(int)BaseDatos::conexion()->lastInsertId();
            foreach ($preguntas as $p) BaseDatos::ejecutar('INSERT INTO preguntas (documento_id,enunciado,opciones_json,correcta,explicacion) VALUES (?,?,?,?,?)',[$id,$p['enunciado'],json_encode($p['opciones'],JSON_UNESCAPED_UNICODE),$p['correcta'],$p['explicacion']]);
            BaseDatos::ejecutar('DELETE FROM borradores WHERE token=?',[$token]);
            return ['id'=>$id,'mensaje'=>'Material guardado.'];
        });
    }
    public static function descartar(array $datos,array $usuario): array {
        $b=BaseDatos::ejecutar('SELECT * FROM borradores WHERE token=? AND usuario_id=?',[$datos['token']??'',$usuario['id']])->fetch();
        if ($b) { BaseDatos::ejecutar('DELETE FROM borradores WHERE token=?',[$b['token']]); $ruta=RAIZ.'/almacenamiento/documentos/'.$b['ruta_archivo']; if (is_file($ruta)) unlink($ruta); }
        return ['mensaje'=>'Borrador descartado.'];
    }
    public static function eliminar(int $id,array $usuario): array {
        $d=Seguridad::documento($id,$usuario);
        BaseDatos::ejecutar('DELETE FROM documentos WHERE id=?',[$id]);
        $ruta=RAIZ.'/almacenamiento/documentos/'.$d['ruta_archivo'];
        if (is_file($ruta)) unlink($ruta);
        return ['mensaje'=>'Material eliminado.'];
    }
    public static function eliminarPregunta(int $id,array $usuario): array {
        $p=BaseDatos::ejecutar('SELECT * FROM preguntas WHERE id=?',[$id])->fetch();
        if (!$p) throw new ErrorAplicacion('La pregunta ya no existe.',404);
        Seguridad::documento((int)$p['documento_id'],$usuario);
        BaseDatos::ejecutar('DELETE FROM preguntas WHERE id=?',[$id]);
        return ['mensaje'=>'Pregunta eliminada.'];
    }
}
