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
        $preguntas=Materiales::detalle($id,$usuario)['preguntas'];
        if (!$preguntas) throw new ErrorAplicacion('El material necesita al menos una pregunta.');
        $copia=array_map(fn($p)=>['enunciado'=>$p['enunciado'],'opciones'=>$p['opciones'],'correcta'=>(int)$p['correcta'],'explicacion'=>$p['explicacion']],$preguntas);
        do { $codigo='EVAL-'.strtoupper(bin2hex(random_bytes(3))); } while (BaseDatos::ejecutar('SELECT id FROM salas_evaluacion WHERE codigo=?',[$codigo])->fetchColumn());
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
            if ($cantidad===0) throw new ErrorAplicacion('Espera a que se una al menos un participante.');
            BaseDatos::ejecutar("UPDATE salas_evaluacion SET estado='en_curso',inicio=? WHERE id=?",[microtime(true)+3,$sala['id']]);
            return ['mensaje'=>'La actividad comienza en tres segundos.'];
        });
    }
    public static function listar(array $usuario): array {
        $condicion=$usuario['rol']==='admin'?'':' WHERE s.creado_por_id=?';
        $salas=BaseDatos::ejecutar('SELECT s.*,(SELECT COUNT(*) FROM participantes p WHERE p.sala_id=s.id) AS participantes FROM salas_evaluacion s'.$condicion.' ORDER BY s.id DESC',$condicion?[$usuario['id']]:[])->fetchAll();
        foreach ($salas as &$sala) { $sala['fase']=Evaluacion::fase($sala)['fase']; unset($sala['preguntas_json']); }
        return ['salas'=>$salas];
    }
}
