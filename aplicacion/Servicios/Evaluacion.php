<?php
declare(strict_types=1);
namespace EvaluaIA\Servicios;

final class Evaluacion {
    public const AVATARES = ['🕵️','💻','🧬','🎯','🔍','🛡️','🔬','🖐️','⚡','🔭','⚖️','🦅'];
    public static function fase(array $sala, ?float $ahora = null): array {
        $ahora ??= microtime(true);
        $total=count(json_decode($sala['preguntas_json'],true));
        if ($sala['estado']==='espera') return ['fase'=>'espera','indice'=>0,'limite'=>null,'cerradas'=>0];
        if ($sala['estado']!=='finalizada' && $ahora<(float)$sala['inicio']) return ['fase'=>'preparacion','indice'=>0,'limite'=>(float)$sala['inicio'],'cerradas'=>0];
        $transcurrido=max(0,$ahora-(float)$sala['inicio']);
        $ciclo=(int)$sala['tiempo']+4;
        $indice=(int)floor($transcurrido/$ciclo);
        if ($sala['estado']==='finalizada' || $indice >= $total) return ['fase'=>'finalizada','indice'=>$total,'limite'=>null,'cerradas'=>$total];
        $revelar=($transcurrido-$indice*$ciclo)>=(int)$sala['tiempo'];
        return ['fase'=>$revelar?'revelacion':'pregunta','indice'=>$indice,'limite'=>(float)$sala['inicio']+$indice*$ciclo+($revelar?$ciclo:(int)$sala['tiempo']),'cerradas'=>$indice+($revelar?1:0)];
    }
    public static function puntos(bool $correcta,float $tiempo,int $maximo): int {
        return $correcta ? 500+(int)round((1-min(1,max(0,$tiempo/$maximo)))*500) : 0;
    }
    private static function sincronizar(array $sala,array $fase): void {
        // La restricción UNIQUE evita una segunda respuesta. Los ausentes reciben cero puntos.
        for ($indice=0;$indice<$fase['cerradas'];$indice++) {
            BaseDatos::ejecutar('INSERT INTO respuestas_participantes (participante_id,indice,opcion,correcta,tiempo,puntos) SELECT p.id,?,NULL,0,?,0 FROM participantes p WHERE p.sala_id=? AND NOT EXISTS (SELECT 1 FROM respuestas_participantes r WHERE r.participante_id=p.id AND r.indice=?)',[$indice,$sala['tiempo'],$sala['id'],$indice]);
        }
        if ($fase['fase']==='finalizada' && $sala['estado']!=='finalizada') BaseDatos::ejecutar("UPDATE salas_evaluacion SET estado='finalizada' WHERE id=?",[$sala['id']]);
    }
    public static function participante(array $sala,string $token): ?array {
        if ($token==='') return null;
        return BaseDatos::ejecutar('SELECT id,nombre,avatar,visto_en FROM participantes WHERE sala_id=? AND token_hash=?',[$sala['id'],hash('sha256',$token)])->fetch() ?: null;
    }
    public static function autorizar(array $sala,?array $usuario): bool {
        return $usuario && ($usuario['rol']==='admin' || (int)$usuario['id']===(int)$sala['creado_por_id']);
    }
    private static function ranking(array $sala,int $cerradas,float $ahora): array {
        $filas=BaseDatos::ejecutar('SELECT p.id,p.nombre,p.avatar,p.visto_en,COALESCE(SUM(r.puntos),0) AS puntos,COALESCE(SUM(r.correcta),0) AS aciertos,COALESCE(SUM(r.tiempo),0) AS tiempo_total FROM participantes p LEFT JOIN respuestas_participantes r ON r.participante_id=p.id AND r.indice < ? WHERE p.sala_id=? GROUP BY p.id,p.nombre,p.avatar,p.visto_en ORDER BY aciertos DESC,puntos DESC,tiempo_total ASC,p.id ASC',[$cerradas,$sala['id']])->fetchAll();
        $total=count(json_decode($sala['preguntas_json'],true));
        foreach ($filas as &$fila) {
            $fila['id']=(int)$fila['id']; $fila['avatar']=(int)$fila['avatar']; $fila['puntos']=(int)$fila['puntos']; $fila['aciertos']=(int)$fila['aciertos'];
            $fila['porcentaje']=round(100*$fila['aciertos']/$total,1); $fila['tiempo_total']=round((float)$fila['tiempo_total'],2);
            $fila['conectado']=$ahora-(float)$fila['visto_en']<12; unset($fila['visto_en']);
        }
        return $filas;
    }
    public static function estado(string $codigo,?array $usuario,string $token): array {
        return BaseDatos::transaccion(function() use ($codigo,$usuario,$token) {
            $sala=BaseDatos::bloquearSala($codigo);
            if (!$sala) throw new ErrorAplicacion('No encontramos esa sala. Revisa el código.',404);
            $ahora=microtime(true); $fase=self::fase($sala,$ahora);
            self::sincronizar($sala,$fase);
            $participante=self::participante($sala,$token);
            $anfitrion=self::autorizar($sala,$usuario);
            if ($token!=='' && !$participante && !$anfitrion) throw new ErrorAplicacion('Tu acceso a esta sala ya no es válido. Vuelve a ingresar.',401);
            if ($participante) BaseDatos::ejecutar('UPDATE participantes SET visto_en=? WHERE id=?',[$ahora,$participante['id']]);
            $preguntas=json_decode($sala['preguntas_json'],true);
            $ranking=self::ranking($sala,$fase['cerradas'],$ahora);
            $resultado=['codigo'=>$sala['codigo'],'titulo'=>$sala['titulo'],'docente'=>$sala['docente'],'tiempo'=>(int)$sala['tiempo'],'total'=>count($preguntas),'fase'=>$fase['fase'],'indice'=>$fase['indice'],'limite'=>$fase['limite'],'ahora'=>$ahora,'cantidad'=>count($ranking),'conectados'=>count(array_filter($ranking,fn($p)=>$p['conectado'])),'anfitrion'=>$anfitrion];
            if (!$anfitrion && !$participante) return $resultado;
            $resultado['ranking']=$ranking;
            if ($participante) {
                $id=(int)$participante['id'];
                $resultado['participante']=current(array_filter($ranking,fn($p)=>$p['id']===$id)) ?: ['id'=>$id,'nombre'=>$participante['nombre']];
            }
            if (in_array($fase['fase'],['pregunta','revelacion'],true)) {
                $pregunta=$preguntas[$fase['indice']];
                if ($fase['fase']==='pregunta') unset($pregunta['correcta'],$pregunta['explicacion']);
                $resultado['pregunta']=$pregunta;
                if ($participante) {
                    $respuesta=BaseDatos::ejecutar('SELECT opcion,correcta,tiempo,puntos FROM respuestas_participantes WHERE participante_id=? AND indice=?',[$participante['id'],$fase['indice']])->fetch();
                    if ($respuesta && $fase['fase']==='pregunta') unset($respuesta['correcta'],$respuesta['puntos']);
                    $resultado['respuesta']=$respuesta ?: null;
                }
                $resultado['respondieron']=(int)BaseDatos::ejecutar('SELECT COUNT(*) FROM respuestas_participantes r JOIN participantes p ON p.id=r.participante_id WHERE p.sala_id=? AND r.indice=? AND r.opcion IS NOT NULL',[$sala['id'],$fase['indice']])->fetchColumn();
            }
            return $resultado;
        });
    }
    public static function unirse(array $datos): array {
        $codigo=mb_strtoupper(Seguridad::texto($datos['codigo']??'',4,12,'Código'));
        $nombre=Seguridad::texto($datos['nombre']??'',2,100,'Nombre');
        $avatar=Seguridad::entero($datos['avatar']??0,0,count(self::AVATARES)-1,'avatar');
        return BaseDatos::transaccion(function() use ($codigo,$nombre,$avatar) {
            $sala=BaseDatos::bloquearSala($codigo);
            if (!$sala) throw new ErrorAplicacion('No encontramos esa sala.',404);
            if ($sala['estado']!=='espera') throw new ErrorAplicacion('La actividad ya comenzó o finalizó. Solicita una nueva sala al docente.',409);
            if ((int)BaseDatos::ejecutar('SELECT COUNT(*) FROM participantes WHERE sala_id=?',[$sala['id']])->fetchColumn()>=300) throw new ErrorAplicacion('La sala alcanzó su límite de 300 participantes.',409);
            $token=bin2hex(random_bytes(32));
            BaseDatos::ejecutar('INSERT INTO participantes (sala_id,nombre,avatar,token_hash,visto_en,creado_en) VALUES (?,?,?,?,?,?)',[$sala['id'],$nombre,$avatar,hash('sha256',$token),microtime(true),date('Y-m-d H:i:s')]);
            return ['token'=>$token,'codigo'=>$codigo];
        });
    }
    public static function responder(array $datos,string $token): array {
        $codigo=Seguridad::texto($datos['codigo']??'',4,12,'Código');
        $indice=Seguridad::entero($datos['indice']??null,0,29,'pregunta');
        $opcion=Seguridad::entero($datos['opcion']??null,0,3,'opción');
        return BaseDatos::transaccion(function() use ($codigo,$indice,$opcion,$token) {
            $sala=BaseDatos::bloquearSala($codigo);
            if (!$sala) throw new ErrorAplicacion('Sala no encontrada.',404);
            $p=self::participante($sala,$token);
            if (!$p) throw new ErrorAplicacion('Ingresa a la sala para responder.',401);
            $ahora=microtime(true); $fase=self::fase($sala,$ahora);
            if ($fase['fase']!=='pregunta' || $fase['indice']!==$indice) throw new ErrorAplicacion('El tiempo de esta pregunta terminó.',409);
            if (BaseDatos::ejecutar('SELECT id FROM respuestas_participantes WHERE participante_id=? AND indice=?',[$p['id'],$indice])->fetchColumn()) throw new ErrorAplicacion('Tu respuesta ya quedó registrada.',409);
            $tiempo=max(0,$ahora-((float)$sala['inicio']+$indice*((int)$sala['tiempo']+4)));
            $preguntas=json_decode($sala['preguntas_json'],true);
            $correcta=$opcion===(int)$preguntas[$indice]['correcta'];
            BaseDatos::ejecutar('INSERT INTO respuestas_participantes (participante_id,indice,opcion,correcta,tiempo,puntos) VALUES (?,?,?,?,?,?)',[$p['id'],$indice,$opcion,(int)$correcta,$tiempo,self::puntos($correcta,$tiempo,(int)$sala['tiempo'])]);
            BaseDatos::ejecutar('UPDATE participantes SET visto_en=? WHERE id=?',[$ahora,$p['id']]);
            return ['mensaje'=>'Respuesta registrada. Espera a que termine el tiempo.','opcion'=>$opcion];
        });
    }
}
