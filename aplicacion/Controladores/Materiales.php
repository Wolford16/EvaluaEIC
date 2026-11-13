<?php
declare(strict_types=1);
namespace EvaluaEIC\Controladores;
use EvaluaEIC\Servicios\{BaseDatos, Seguridad, ErrorAplicacion, LectorPdf, InteligenciaArtificial, Preguntas};
final class Materiales {
    public static function listar(array $usuario): array {
        $condicion = $usuario['rol'] === 'admin'
            ? ''
            : ' WHERE d.subido_por_id = ? OR (d.compartido = 1 AND d.curso_id IS NOT NULL)';
        $documentos = BaseDatos::ejecutar(
            'SELECT d.id, d.titulo, d.nombre_archivo, d.paginas, d.creado_en, d.subido_por_id, d.curso_id, d.compartido,
                    u.nombre AS autor, c.nombre AS curso,
                    (SELECT COUNT(*) FROM preguntas p WHERE p.documento_id = d.id) AS cantidad
             FROM documentos d JOIN usuarios u ON u.id = d.subido_por_id LEFT JOIN cursos c ON c.id = d.curso_id'
            . $condicion . ' ORDER BY d.id DESC',
            $condicion ? [$usuario['id']] : []
        )->fetchAll();
        foreach ($documentos as &$documento) {
            $documento['puede_gestionar'] = Seguridad::puedeGestionarDocumento($documento, $usuario);
        }
        return ['documentos' => $documentos] + Cursos::listar();
    }
    public static function detalle(int $id,array $usuario): array {
        $documento = Seguridad::documento($id, $usuario, permitirCompartido: true);
        $documento['puede_gestionar'] = Seguridad::puedeGestionarDocumento($documento, $usuario);
        unset($documento['ruta_archivo'], $documento['token_manual']);
        $condicion = $usuario['rol'] === 'admin' ? '' : ' AND creado_por_id = ?';
        $salas = BaseDatos::ejecutar(
            'SELECT id,codigo,titulo,tiempo,estado,creado_en FROM salas_evaluacion WHERE documento_id=?'
            . $condicion . ' ORDER BY id DESC',
            $condicion ? [$id, $usuario['id']] : [$id]
        )->fetchAll();
        return ['documento' => $documento, 'preguntas' => self::preguntas($id), 'salas' => $salas];
    }
    public static function preguntas(int $id): array {
        $preguntas=BaseDatos::ejecutar('SELECT * FROM preguntas WHERE documento_id=? ORDER BY id',[$id])->fetchAll();
        foreach ($preguntas as &$p) {
            $p['opciones'] = json_decode($p['opciones_json'], true);
            if ($p['tipo'] === Preguntas::COMPLETACION) {
                $p['respuestas_aceptadas'] = json_decode($p['respuestas_aceptadas_json'], true);
            }
            unset($p['opciones_json'], $p['respuestas_aceptadas_json']);
        }
        return $preguntas;
    }
    private static function insertarPreguntas(int $id, array $preguntas): void {
        foreach ($preguntas as $pregunta) {
            BaseDatos::ejecutar(
                'INSERT INTO preguntas (documento_id,enunciado,opciones_json,correcta,explicacion,tipo,respuestas_aceptadas_json)
                 VALUES (?,?,?,?,?,?,?)',
                [$id, $pregunta['enunciado'], json_encode($pregunta['opciones'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                 $pregunta['correcta'], $pregunta['explicacion'], $pregunta['tipo'] ?? Preguntas::OPCION_MULTIPLE,
                 isset($pregunta['respuestas_aceptadas'])
                     ? json_encode($pregunta['respuestas_aceptadas'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                     : null]
            );
        }
    }
    private static function accesoCurso(array $datos): array {
        $curso = $datos['curso_id'] ?? '';
        $cursoId = $curso === '' ? null : Seguridad::entero($curso, 1, PHP_INT_MAX, 'el curso');
        if ($cursoId !== null) Cursos::buscar($cursoId);
        $compartido = Seguridad::entero($datos['compartido'] ?? 0, 0, 1, 'la visibilidad');
        if ($compartido && $cursoId === null) throw new ErrorAplicacion('Asigna un curso antes de compartir el examen.');
        return [$cursoId, $compartido];
    }
    public static function actualizarCurso(int $id, array $datos, array $usuario): array {
        BaseDatos::transaccion(function () use ($id, $datos, $usuario): void {
            Seguridad::documento($id, $usuario, bloquear: true);
            [$cursoId, $compartido] = self::accesoCurso($datos);
            BaseDatos::ejecutar('UPDATE documentos SET curso_id = ?, compartido = ? WHERE id = ?', [$cursoId, $compartido, $id]);
        });
        return ['mensaje' => 'Curso y visibilidad actualizados.'];
    }
    public static function copiar(int $id, array $usuario): array {
        $copia = bin2hex(random_bytes(24)) . '.pdf';
        $directorio = RAIZ . '/almacenamiento/documentos/';
        $destino = $directorio . $copia;
        try {
            return BaseDatos::transaccion(function () use ($id, $usuario, $copia, $directorio, $destino) {
                $original = Seguridad::documento($id, $usuario, permitirCompartido: true, bloquear: true);
                $archivoCopia = null;
                if ($original['ruta_archivo'] !== null) {
                    $origen = $directorio . $original['ruta_archivo'];
                    if (!is_file($origen)) throw new ErrorAplicacion('El PDF original ya no está disponible.', 404);
                    if (!copy($origen, $destino)) throw new ErrorAplicacion('No se pudo copiar el PDF.', 500);
                    $archivoCopia = $copia;
                }
                BaseDatos::ejecutar(
                    'INSERT INTO documentos (titulo,nombre_archivo,ruta_archivo,tamano,paginas,subido_por_id,creado_en,curso_id)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$original['titulo'], $original['nombre_archivo'], $archivoCopia, $original['tamano'], $original['paginas'],
                     $usuario['id'], date('Y-m-d H:i:s'), $original['curso_id']]
                );
                $nuevo = (int) BaseDatos::conexion()->lastInsertId();
                BaseDatos::ejecutar(
                    'INSERT INTO preguntas (documento_id,enunciado,opciones_json,correcta,explicacion,tipo,respuestas_aceptadas_json)
                     SELECT ?,enunciado,opciones_json,correcta,explicacion,tipo,respuestas_aceptadas_json
                     FROM preguntas WHERE documento_id = ? ORDER BY id',
                    [$nuevo, $id]
                );
                return ['id' => $nuevo, 'mensaje' => 'Copia guardada en tu biblioteca.'];
            });
        } catch (\Throwable $error) {
            if (is_file($destino)) unlink($destino);
            throw $error;
        }
    }
    public static function crearManual(array $datos, array $usuario): array {
        $token = Seguridad::texto($datos['token'] ?? '', 48, 48, 'Identificador del examen');
        if (!ctype_xdigit($token)) throw new ErrorAplicacion('El identificador del examen no es válido.');
        $titulo = Seguridad::texto($datos['titulo'] ?? '', 3, 200, 'Título');
        [$cursoId, $compartido] = self::accesoCurso($datos);
        if ($cursoId === null) throw new ErrorAplicacion('Selecciona un curso para el examen.');
        $preguntas = Preguntas::validarManuales($datos['preguntas'] ?? null);

        return BaseDatos::transaccion(function () use ($usuario, $token, $titulo, $cursoId, $compartido, $preguntas): array {
            $insercion = BaseDatos::ejecutar(
                'INSERT INTO documentos (titulo,tamano,paginas,subido_por_id,creado_en,curso_id,compartido,token_manual)
                 VALUES (?,0,0,?,?,?,?,?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                [$titulo, $usuario['id'], date('Y-m-d H:i:s'), $cursoId, $compartido, $token]
            );
            $id = (int) BaseDatos::conexion()->lastInsertId();
            if ($insercion->rowCount() === 1) self::insertarPreguntas($id, $preguntas);
            return ['id' => $id, 'mensaje' => 'Examen guardado.'];
        });
    }
    public static function editarManual(int $id, array $datos, array $usuario): array {
        $titulo = Seguridad::texto($datos['titulo'] ?? '', 3, 200, 'Título');
        [$cursoId, $compartido] = self::accesoCurso($datos);
        $preguntas = Preguntas::validarManuales($datos['preguntas'] ?? null);
        return BaseDatos::transaccion(function () use ($id, $usuario, $titulo, $cursoId, $compartido, $preguntas): array {
            $documento = Seguridad::documento($id, $usuario, bloquear: true);
            if ($documento['ruta_archivo'] !== null) throw new ErrorAplicacion('Este examen se preparó a partir de un PDF.');
            BaseDatos::ejecutar('UPDATE documentos SET titulo=?, curso_id=?, compartido=? WHERE id=?', [$titulo, $cursoId, $compartido, $id]);
            BaseDatos::ejecutar('DELETE FROM preguntas WHERE documento_id = ?', [$id]);
            self::insertarPreguntas($id, $preguntas);
            return ['id' => $id, 'mensaje' => 'Examen actualizado.'];
        });
    }
    public static function preparar(array $usuario): array {
        $cantidad=Seguridad::entero($_POST['cantidad']??10,1,30,'cantidad de preguntas');
        $titulo=Seguridad::texto($_POST['titulo']??'',3,200,'Título');
        $motor=Seguridad::texto($_POST['motor']??'openrouter',1,20,'Preparación');
        InteligenciaArtificial::validarMotor($motor);
        [$cursoId, $compartido] = self::accesoCurso($_POST);
        if ($cursoId === null) throw new ErrorAplicacion('Selecciona un curso para el examen.');
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
            return ['token'=>$token,'titulo'=>$titulo,'paginas'=>$lectura['paginas'],'preguntas'=>$preguntas,'analisis'=>$analisis,
                    'curso_id'=>$cursoId,'compartido'=>$compartido];
        } catch (\Throwable $e) { if (is_file($ruta)) unlink($ruta); throw $e; }
    }
    public static function guardar(array $datos,array $usuario): array {
        $token=Seguridad::texto($datos['token']??'',48,48,'Borrador');
        if (!ctype_xdigit($token)) throw new ErrorAplicacion('El identificador del borrador no es válido.');
        return BaseDatos::transaccion(function() use ($token,$usuario,$datos) {
            $b=BaseDatos::ejecutar('SELECT * FROM borradores WHERE token=? AND usuario_id=? FOR UPDATE',[$token,$usuario['id']])->fetch();
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
            [$cursoId, $compartido] = self::accesoCurso($datos);
            BaseDatos::ejecutar(
                'INSERT INTO documentos (titulo,nombre_archivo,ruta_archivo,tamano,paginas,subido_por_id,creado_en,curso_id,compartido)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$titulo,$b['nombre_archivo'],$b['ruta_archivo'],$b['tamano'],$b['paginas'],$usuario['id'],date('Y-m-d H:i:s'),$cursoId,$compartido]
            );
            $id=(int)BaseDatos::conexion()->lastInsertId();
            self::insertarPreguntas($id, $preguntas);
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
        $d = BaseDatos::transaccion(function () use ($id, $usuario) {
            $documento = Seguridad::documento($id, $usuario, bloquear: true);
            BaseDatos::ejecutar('DELETE FROM documentos WHERE id = ?', [$id]);
            return $documento;
        });
        if ($d['ruta_archivo'] !== null) {
            $ruta = RAIZ . '/almacenamiento/documentos/' . $d['ruta_archivo'];
            if (is_file($ruta)) unlink($ruta);
        }
        return ['mensaje'=>'Material eliminado.'];
    }
    public static function eliminarPregunta(int $id,array $usuario): array {
        return BaseDatos::transaccion(function () use ($id, $usuario) {
            $documentoId = BaseDatos::ejecutar('SELECT documento_id FROM preguntas WHERE id = ?', [$id])->fetchColumn();
            if (!$documentoId) throw new ErrorAplicacion('La pregunta ya no existe.', 404);
            Seguridad::documento((int) $documentoId, $usuario, bloquear: true);
            BaseDatos::ejecutar('DELETE FROM preguntas WHERE id = ?', [$id]);
            return ['mensaje' => 'Pregunta eliminada.'];
        });
    }
}
