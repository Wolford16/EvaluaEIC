<?php
declare(strict_types=1);
namespace EvaluaEIC\Controladores;
use EvaluaEIC\Servicios\{BaseDatos, Seguridad, ErrorAplicacion, Evaluacion};
final class Salas {
    public static function crear(array $datos,array $usuario): array {
        $url = url_publica();
        if ($url === null) throw new ErrorAplicacion('Configura APLICACION_URL con la dirección pública de EvaluaEIC para crear salas.', 503);
        $id=Seguridad::entero($datos['documento_id']??null,1,PHP_INT_MAX,'documento');
        $documento=Seguridad::documento($id,$usuario);
        $tiempo=Seguridad::entero($datos['tiempo']??20,15,30,'tiempo');
        if (!in_array($tiempo,[15,20,30],true)) throw new ErrorAplicacion('Elige 15, 20 o 30 segundos.');
        $modalidad=$datos['modalidad']??'individual';
        if (!in_array($modalidad,['individual','equipos'],true)) throw new ErrorAplicacion('Elige una modalidad de evaluación.');
        $equipos=$modalidad==='equipos' ? Seguridad::entero($datos['cantidad_equipos']??null,2,8,'la cantidad de equipos') : 0;
        $preguntas=Materiales::detalle($id,$usuario)['preguntas'];
        if (!$preguntas) throw new ErrorAplicacion('El material necesita al menos una pregunta.');
        shuffle($preguntas);
        $posiciones = [];
        $copia = [];
        foreach ($preguntas as $pregunta) {
            $opciones = $pregunta['opciones'];
            $correcta = $opciones[(int) $pregunta['correcta']];
            $cantidad = count($opciones);
            unset($opciones[(int) $pregunta['correcta']]);
            shuffle($opciones);
            // Reparte las respuestas correctas entre las letras, con un orden aleatorio.
            if (empty($posiciones[$cantidad])) {
                $posiciones[$cantidad] = range(0, $cantidad - 1);
                shuffle($posiciones[$cantidad]);
            }
            $indiceCorrecto = array_pop($posiciones[$cantidad]);
            array_splice($opciones, $indiceCorrecto, 0, [$correcta]);
            $copia[] = [
                'enunciado' => $pregunta['enunciado'],
                'opciones' => $opciones,
                'correcta' => $indiceCorrecto,
                'explicacion' => $pregunta['explicacion'],
            ];
        }
        do { $codigo='EVAL-'.strtoupper(bin2hex(random_bytes(3))); } while (BaseDatos::ejecutar('SELECT id FROM salas_evaluacion WHERE codigo=?',[$codigo])->fetchColumn());
        if ($equipos) $copia=['preguntas'=>$copia,'cantidad_equipos'=>$equipos];
        BaseDatos::ejecutar('INSERT INTO salas_evaluacion (documento_id,titulo,docente,codigo,creado_por_id,tiempo,preguntas_json,creado_en) VALUES (?,?,?,?,?,?,?,?)',[$id,$documento['titulo'],$usuario['nombre'],$codigo,$usuario['id'],$tiempo,json_encode($copia,JSON_UNESCAPED_UNICODE),date('Y-m-d H:i:s')]);
        return ['codigo'=>$codigo,'url'=>$url.'/sala/'.$codigo];
    }
    public static function iniciar(array $datos,array $usuario): array {
        return BaseDatos::transaccion(function() use ($datos,$usuario) {
            $sala=BaseDatos::bloquearSala($datos['codigo']??'');
            if (!$sala) throw new ErrorAplicacion('Sala no encontrada.',404);
            if (!Evaluacion::autorizar($sala,$usuario)) throw new ErrorAplicacion('Solo el docente responsable puede iniciar esta sala.',403);
            if ($sala['estado']!=='espera') throw new ErrorAplicacion('Esta actividad ya comenzó.',409);
            $cantidad=(int)BaseDatos::ejecutar('SELECT COUNT(*) FROM participantes WHERE sala_id=?',[$sala['id']])->fetchColumn();
            $equipos=Evaluacion::contenido($sala)['cantidad_equipos'];
            if ($equipos && $cantidad<$equipos) throw new ErrorAplicacion("Se necesitan al menos {$equipos} participantes para formar los equipos.");
            if ($cantidad===0) throw new ErrorAplicacion('Espera a que se una al menos un participante.');
            BaseDatos::ejecutar("UPDATE salas_evaluacion SET estado='en_curso',inicio=? WHERE id=?",[microtime(true)+3,$sala['id']]);
            return ['mensaje'=>'La actividad comienza en tres segundos.'];
        });
    }
    public static function listar(array $usuario): array {
        $condicion=$usuario['rol']==='admin'?'':' WHERE s.creado_por_id=?';
        $salas=BaseDatos::ejecutar('SELECT s.*,(SELECT COUNT(*) FROM participantes p WHERE p.sala_id=s.id) AS participantes FROM salas_evaluacion s'.$condicion.' ORDER BY s.id DESC',$condicion?[$usuario['id']]:[])->fetchAll();
        foreach ($salas as &$sala) {
            $contenido=Evaluacion::contenido($sala);
            $sala['fase']=Evaluacion::fase($sala,count($contenido['preguntas']))['fase'];
            $sala['cantidad_equipos']=$contenido['cantidad_equipos'];
            unset($sala['preguntas_json']);
        }
        return ['salas'=>$salas];
    }
    public static function respuestas(array $consulta, ?array $usuario, string $token): array {
        $codigo = mb_strtoupper(Seguridad::texto($consulta['codigo'] ?? '', 4, 12, 'Código'));
        $id = isset($consulta['participante']) ? Seguridad::entero($consulta['participante'], 1, PHP_INT_MAX, 'participante') : null;
        return Evaluacion::historialRespuestas($codigo, $usuario, $token, $id);
    }
}
