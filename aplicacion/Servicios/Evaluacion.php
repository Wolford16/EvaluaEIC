<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;

final class Evaluacion {
    public const AVATARES = [
        '🕵️','💻','🧬','🎯','🔍','🛡️','🔬','🖐️','⚡','🔭','⚖️','🦅',
        '🧩','🧠','🧭','🐺','🦉','🦊','🦋','🦾','🤖','📚','🎓','🏅',
    ];
    public static function contenido(array $sala): array {
        $copia = json_decode($sala['preguntas_json'], true, 512, JSON_THROW_ON_ERROR);
        // Las salas individuales, incluidas las anteriores, guardan la lista directamente.
        return isset($copia['preguntas']) ? $copia : ['preguntas' => $copia, 'cantidad_equipos' => 0];
    }
    public static function fase(array $sala, int $total, ?float $ahora = null): array {
        $ahora ??= microtime(true);
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
    public static function etapa(array $sala): ?string {
        return $sala['es_entrada'] ? 'Entrada' : ($sala['entrada_id'] !== null ? 'Salida' : null);
    }
    public static function resultadosInforme(string $codigo, array $usuario, ?int $id = null): array {
        return BaseDatos::transaccion(function () use ($codigo, $usuario, $id): array {
            $sala = BaseDatos::bloquearSala($codigo);
            if (!$sala) throw new ErrorAplicacion('No encontramos esa sala.', 404);
            if (!self::autorizar($sala, $usuario)) throw new ErrorAplicacion('No tienes acceso a los resultados de esta sala.', 403);

            $preguntas = self::contenido($sala)['preguntas'];
            $total = count($preguntas);
            $ahora = microtime(true);
            if (self::fase($sala, $total, $ahora)['fase'] !== 'finalizada') {
                throw new ErrorAplicacion('El informe estará disponible cuando termine la evaluación.', 409);
            }
            $datos = ['titulo' => $sala['titulo'], 'docente' => $sala['docente'], 'codigo' => $sala['codigo']];
            $datos['etapa'] = self::etapa($sala);
            if ($id !== null) {
                $participante = self::buscarParticipante($sala, $id);
                $filas = [];
                foreach (self::preguntasRespondidas($preguntas, $id) as $indice => $pregunta) {
                    $elegida = $pregunta['elegida'];
                    $filas[] = [
                        'numero' => $indice + 1,
                        'pregunta' => $pregunta['enunciado'],
                        'respuesta' => self::respuestaInforme($pregunta, $elegida) ?? '-',
                        'correcta' => self::solucionInforme($pregunta),
                        'resultado' => $elegida === null ? 'Sin responder' : ($pregunta['acierto'] ? 'Correcta' : 'Incorrecta'),
                    ];
                }
                return $datos + ['participante' => $participante['nombre'], 'filas' => $filas];
            }
            $incorrectas = BaseDatos::ejecutar(
                'SELECT r.participante_id, COUNT(*) FROM respuestas_participantes r
                 JOIN participantes p ON p.id = r.participante_id
                 WHERE p.sala_id = ? AND r.indice < ? AND (r.opcion IS NOT NULL OR r.texto IS NOT NULL) AND r.correcta = 0
                 GROUP BY r.participante_id',
                [$sala['id'], $total]
            )->fetchAll(\PDO::FETCH_KEY_PAIR);

            $filas = self::ranking((int) $sala['id'], $total, $total, $ahora, null);
            foreach ($filas as &$fila) {
                $fila['incorrectas'] = (int) ($incorrectas[$fila['id']] ?? 0);
                $fila['sin_responder'] = $total - $fila['aciertos'] - $fila['incorrectas'];
            }
            if ($datos['etapa'] !== null) {
                $aciertos = BaseDatos::ejecutar(
                    'SELECT r.indice, SUM(r.correcta) FROM respuestas_participantes r
                     JOIN participantes p ON p.id = r.participante_id WHERE p.sala_id = ? GROUP BY r.indice',
                    [$sala['id']]
                )->fetchAll(\PDO::FETCH_KEY_PAIR);
                $detalle = [];
                foreach ($preguntas as $indice => $pregunta) {
                    $detalle[] = [$indice + 1, $pregunta['enunciado'], self::solucionInforme($pregunta),
                        ($aciertos[$indice] ?? 0) . ' de ' . count($filas)];
                }
                $datos['tablas'] = [['titulo'=>'Resultados por pregunta', 'columnas'=>['N.º'=>28, 'Pregunta'=>235,
                    'Respuesta correcta'=>171, 'Aciertos'=>82], 'filas'=>$detalle]];
            }
            return $datos + ['filas' => $filas];
        });
    }
    private static function opcionInforme(array $pregunta, int $indice): string {
        return chr(65 + $indice) . '. ' . $pregunta['opciones'][$indice];
    }
    public static function solucionInforme(array $pregunta): string {
        return ($pregunta['tipo'] ?? null) === Preguntas::COMPLETACION
            ? implode(' / ', $pregunta['respuestas_aceptadas'])
            : self::opcionInforme($pregunta, (int) $pregunta['correcta']);
    }
    public static function respuestaInforme(array $pregunta, int|string|null $respuesta): ?string {
        return is_int($respuesta) ? self::opcionInforme($pregunta, $respuesta) : $respuesta;
    }
    public static function historialRespuestas(string $codigo, ?array $usuario, string $token, ?int $id): array {
        return BaseDatos::transaccion(function () use ($codigo, $usuario, $token, $id): array {
            $sala = BaseDatos::bloquearSala($codigo);
            if (!$sala) throw new ErrorAplicacion('No encontramos esa sala.', 404);

            if ($id !== null && self::autorizar($sala, $usuario)) {
                $participante = self::buscarParticipante($sala, $id);
            } else {
                $participante = self::participante($sala, $token);
                if (!$participante || ($id !== null && $id !== (int) $participante['id'])) {
                    throw new ErrorAplicacion('No tienes acceso a este historial.', $usuario || $participante ? 403 : 401);
                }
            }
            $preguntas = self::contenido($sala)['preguntas'];
            if (self::fase($sala, count($preguntas))['fase'] !== 'finalizada') {
                throw new ErrorAplicacion('El historial estará disponible cuando termine la evaluación.', 409);
            }

            return ['nombre' => $participante['nombre'], 'etapa' => self::etapa($sala),
                'preguntas' => self::preguntasRespondidas($preguntas, (int) $participante['id'])];
        });
    }
    private static function buscarParticipante(array $sala, int $id): array {
        $participante = BaseDatos::ejecutar(
            'SELECT id, nombre FROM participantes WHERE sala_id = ? AND id = ?',
            [$sala['id'], $id]
        )->fetch();
        if (!$participante) throw new ErrorAplicacion('No encontramos a ese participante en la sala.', 404);
        return $participante;
    }
    private static function preguntasRespondidas(array $preguntas, int $id): array {
        $filas = BaseDatos::ejecutar(
            'SELECT indice, opcion, texto, correcta, puntos FROM respuestas_participantes WHERE participante_id = ?',
            [$id]
        )->fetchAll();
        $respuestas = array_column($filas, null, 'indice');
        foreach ($preguntas as $indice => &$pregunta) {
            $opcion = $respuestas[$indice]['opcion'] ?? null;
            $pregunta['elegida'] = $opcion === null ? ($respuestas[$indice]['texto'] ?? null) : (int) $opcion;
            $pregunta['acierto'] = (bool) ($respuestas[$indice]['correcta'] ?? false);
            $pregunta['puntos'] = (int) ($respuestas[$indice]['puntos'] ?? 0);
        }
        return $preguntas;
    }
    private static function ranking(int $sala, int $total, int $cerradas, float $ahora, ?int $revelada): array {
        $filas=BaseDatos::ejecutar(
            'SELECT p.id,p.nombre,p.avatar,p.visto_en,COALESCE(SUM(r.puntos),0) AS puntos,
             COALESCE(SUM(r.correcta),0) AS aciertos,COALESCE(SUM(r.tiempo),0) AS tiempo_total,
             MAX(CASE WHEN r.indice = ? THEN r.correcta ELSE 0 END) AS acierto_revelado
             FROM participantes p LEFT JOIN respuestas_participantes r ON r.participante_id=p.id AND r.indice < ?
             WHERE p.sala_id=? GROUP BY p.id,p.nombre,p.avatar,p.visto_en
             ORDER BY aciertos DESC,puntos DESC,tiempo_total ASC,p.id ASC',
            [$revelada,$cerradas,$sala]
        )->fetchAll();
        foreach ($filas as &$fila) {
            $fila['id']=(int)$fila['id']; $fila['avatar']=(int)$fila['avatar']; $fila['puntos']=(int)$fila['puntos']; $fila['aciertos']=(int)$fila['aciertos'];
            $fila['porcentaje']=round(100*$fila['aciertos']/$total,1); $fila['tiempo_total']=round((float)$fila['tiempo_total'],2);
            $fila['conectado']=$ahora-(float)$fila['visto_en']<12; unset($fila['visto_en']);
        }
        return $filas;
    }
    private static function resultadosEquipos(array &$ranking, int $cantidad, int $cerradas, bool $revelacion): array {
        $equipos = [];
        for ($numero = 1; $numero <= $cantidad; $numero++) {
            $equipos[$numero] = ['numero' => $numero, 'integrantes' => 0, 'aciertos' => 0];
            if ($revelacion) $equipos[$numero]['aciertos_pregunta'] = 0;
        }
        // Los ID reflejan el orden de entrada. Una vez inscrito, un participante permanece en el padrón de la sala.
        $ids = array_column($ranking, 'id');
        sort($ids, SORT_NUMERIC);
        $orden = array_flip($ids);
        foreach ($ranking as &$fila) {
            $numero = 1 + $orden[$fila['id']] % $cantidad;
            $fila['equipo'] = $numero;
            $equipos[$numero]['integrantes']++;
            $equipos[$numero]['aciertos'] += $fila['aciertos'];
            if ($revelacion) $equipos[$numero]['aciertos_pregunta'] += (int)$fila['acierto_revelado'];
        }
        unset($fila);

        foreach ($equipos as &$equipo) {
            $equipo['posibles'] = $equipo['integrantes'] * $cerradas;
            $equipo['porcentaje'] = $equipo['posibles'] ? round(100 * $equipo['aciertos'] / $equipo['posibles'], 1) : 0;
        }
        unset($equipo);
        if ($cerradas === 0) return array_values($equipos);

        $comparar = static fn(array $a, array $b): int =>
            $b['aciertos'] * $a['posibles'] <=> $a['aciertos'] * $b['posibles'];
        usort($equipos, $comparar);
        foreach ($equipos as $indice => &$equipo) {
            $anterior = $equipos[$indice - 1] ?? null;
            $equipo['posicion'] = $anterior && $comparar($equipo, $anterior) === 0 ? $anterior['posicion'] : $indice + 1;
        }
        return $equipos;
    }
    private static function racha(int $participante, int $cerradas): int {
        if ($cerradas === 0) return 0;
        $respuestas = BaseDatos::ejecutar(
            'SELECT correcta FROM respuestas_participantes WHERE participante_id = ? AND indice < ? ORDER BY indice DESC',
            [$participante, $cerradas]
        );
        $racha = 0;
        while ((int) $respuestas->fetchColumn() === 1) {
            $racha++;
        }
        return $racha;
    }
    public static function estado(string $codigo,?array $usuario,string $token): array {
        return BaseDatos::transaccion(function() use ($codigo,$usuario,$token) {
            $sala=BaseDatos::bloquearSala($codigo);
            if (!$sala) throw new ErrorAplicacion('No encontramos esa sala. Revisa el código.',404);
            ['preguntas'=>$preguntas,'cantidad_equipos'=>$cantidadEquipos] = self::contenido($sala);
            $total = count($preguntas);
            $ahora=microtime(true); $fase=self::fase($sala,$total,$ahora);
            self::sincronizar($sala,$fase);
            $participante=self::participante($sala,$token);
            $anfitrion=self::autorizar($sala,$usuario);
            if ($token!=='' && !$participante && !$anfitrion) throw new ErrorAplicacion('Tu acceso a esta sala ya no es válido. Vuelve a ingresar.',401);
            if ($participante) BaseDatos::ejecutar('UPDATE participantes SET visto_en=? WHERE id=?',[$ahora,$participante['id']]);
            $revelada = $cantidadEquipos && $fase['fase']==='revelacion' ? $fase['indice'] : null;
            $ranking=self::ranking((int)$sala['id'],$total,$fase['cerradas'],$ahora,$revelada);
            $resultado=['codigo'=>$sala['codigo'],'titulo'=>$sala['titulo'],'docente'=>$sala['docente'],'tiempo'=>(int)$sala['tiempo'],'total'=>$total,'fase'=>$fase['fase'],'indice'=>$fase['indice'],'limite'=>$fase['limite'],'ahora'=>$ahora,'cantidad'=>count($ranking),'conectados'=>count(array_filter($ranking,fn($p)=>$p['conectado'])),'anfitrion'=>$anfitrion,'cantidad_equipos'=>$cantidadEquipos];
            $resultado['etapa'] = self::etapa($sala);
            if (!$anfitrion && !$participante) return $resultado;
            if ($anfitrion && $fase['fase'] === 'finalizada' && $resultado['etapa'] !== null) {
                $resultado['codigo_entrada'] = $sala['es_entrada'] ? $sala['codigo'] :
                    BaseDatos::ejecutar('SELECT codigo FROM salas_evaluacion WHERE id = ?', [$sala['entrada_id']])->fetchColumn();
                if ($sala['es_entrada']) {
                    $salida = BaseDatos::ejecutar('SELECT codigo, estado, inicio, tiempo FROM salas_evaluacion WHERE entrada_id = ?', [$sala['id']])->fetch();
                    $resultado['codigo_salida'] = $salida['codigo'] ?? null;
                    $resultado['salida_finalizada'] = $salida && self::fase($salida, $total, $ahora)['fase'] === 'finalizada';
                } else {
                    $resultado['codigo_salida'] = $sala['codigo'];
                    $resultado['salida_finalizada'] = true;
                }
            }
            if ($cantidadEquipos) $resultado['equipos'] = self::resultadosEquipos($ranking,$cantidadEquipos,$fase['cerradas'],$revelada!==null);
            foreach ($ranking as &$fila) unset($fila['acierto_revelado']);
            unset($fila);
            $resultado['ranking']=$ranking;
            if ($participante) {
                $id=(int)$participante['id'];
                $resultado['participante']=current(array_filter($ranking,fn($p)=>$p['id']===$id)) ?: ['id'=>$id,'nombre'=>$participante['nombre']];
                $resultado['participante']['racha'] = self::racha($id, $fase['cerradas']);
                if ($sala['es_entrada']) $resultado['codigo_personal'] = self::codigoPersonal($token);
            }
            if (in_array($fase['fase'],['pregunta','revelacion'],true)) {
                $pregunta=$preguntas[$fase['indice']];
                if ($fase['fase']==='pregunta') unset($pregunta['correcta'],$pregunta['explicacion'],$pregunta['respuestas_aceptadas']);
                $resultado['pregunta']=$pregunta;
                if ($participante) {
                    $respuesta=BaseDatos::ejecutar('SELECT opcion,texto,correcta,tiempo,puntos FROM respuestas_participantes WHERE participante_id=? AND indice=?',[$participante['id'],$fase['indice']])->fetch();
                    if ($respuesta && $fase['fase']==='pregunta') unset($respuesta['correcta'],$respuesta['puntos']);
                    $resultado['respuesta']=$respuesta ?: null;
                }
                $resultado['respondieron']=(int)BaseDatos::ejecutar('SELECT COUNT(*) FROM respuestas_participantes r JOIN participantes p ON p.id=r.participante_id WHERE p.sala_id=? AND r.indice=? AND (r.opcion IS NOT NULL OR r.texto IS NOT NULL)',[$sala['id'],$fase['indice']])->fetchColumn();
            }
            return $resultado;
        });
    }
    public static function unirse(array $datos): array {
        $codigo=mb_strtoupper(Seguridad::texto($datos['codigo']??'',4,12,'Código'));
        return BaseDatos::transaccion(function() use ($codigo,$datos) {
            $sala=BaseDatos::bloquearSala($codigo);
            if (!$sala) throw new ErrorAplicacion('No encontramos esa sala.',404);
            if ($sala['estado']!=='espera') throw new ErrorAplicacion('La actividad ya comenzó o finalizó. Solicita una nueva sala al docente.',409);
            if ((int)BaseDatos::ejecutar('SELECT COUNT(*) FROM participantes WHERE sala_id=?',[$sala['id']])->fetchColumn()>=300) throw new ErrorAplicacion('La sala alcanzó su límite de 300 participantes.',409);
            $origen = null;
            $codigoPersonal = $datos['codigo_personal'] ?? '';
            if ($sala['entrada_id'] !== null && $codigoPersonal !== '') {
                $clave = strtoupper(preg_replace('/[\s-]+/', '', Seguridad::texto($codigoPersonal, 16, 32, 'Código personal')));
                if (!preg_match('/\A[A-F0-9]{16}\z/', $clave)) throw new ErrorAplicacion('Revisa el código personal de entrada.');
                $origen = BaseDatos::ejecutar(
                    'SELECT id, nombre, avatar FROM participantes WHERE sala_id = ? AND codigo_seguimiento_hash = ?',
                    [$sala['entrada_id'], hash('sha256', $clave)]
                )->fetch();
                if (!$origen) throw new ErrorAplicacion('El código personal no corresponde a este examen de entrada.');
                if (BaseDatos::ejecutar('SELECT id FROM participantes WHERE sala_id = ? AND participante_entrada_id = ?',
                    [$sala['id'], $origen['id']])->fetchColumn()) {
                    throw new ErrorAplicacion('Este código personal ya se registró en la salida. Continúa desde el dispositivo donde ingresaste.', 409);
                }
            } elseif ($sala['entrada_id'] !== null && ($datos['solo_salida'] ?? null) !== '1' && ($datos['solo_salida'] ?? null) !== 1) {
                throw new ErrorAplicacion('Escribe tu código personal o indica que participarás solo en la salida.');
            }
            $nombre = $origen['nombre'] ?? Seguridad::texto($datos['nombre'] ?? '', 2, 100, 'Nombre');
            $avatar = $origen['avatar'] ?? Seguridad::entero($datos['avatar'] ?? 0, 0, count(self::AVATARES)-1, 'avatar');
            $token=bin2hex(random_bytes(32));
            $seguimiento = $sala['es_entrada'] ? hash('sha256', self::codigoPersonal($token)) : null;
            BaseDatos::ejecutar(
                'INSERT INTO participantes (sala_id,nombre,avatar,token_hash,visto_en,creado_en,codigo_seguimiento_hash,participante_entrada_id)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$sala['id'],$nombre,$avatar,hash('sha256',$token),microtime(true),date('Y-m-d H:i:s'),$seguimiento,$origen['id'] ?? null]
            );
            return ['token'=>$token,'codigo'=>$codigo];
        });
    }
    private static function codigoPersonal(string $token): string {
        return strtoupper(substr(hash('sha256', 'seguimiento:' . $token), 0, 16));
    }
    public static function responder(array $datos,string $token): array {
        $codigo=Seguridad::texto($datos['codigo']??'',4,12,'Código');
        $indice=Seguridad::entero($datos['indice']??null,0,29,'pregunta');
        return BaseDatos::transaccion(function() use ($codigo,$indice,$datos,$token) {
            $sala=BaseDatos::bloquearSala($codigo);
            if (!$sala) throw new ErrorAplicacion('Sala no encontrada.',404);
            $p=self::participante($sala,$token);
            if (!$p) throw new ErrorAplicacion('Ingresa a la sala para responder.',401);
            $preguntas=self::contenido($sala)['preguntas'];
            $ahora=microtime(true); $fase=self::fase($sala,count($preguntas),$ahora);
            if ($fase['fase']!=='pregunta' || $fase['indice']!==$indice) throw new ErrorAplicacion('El tiempo de esta pregunta terminó.',409);
            if (BaseDatos::ejecutar('SELECT id FROM respuestas_participantes WHERE participante_id=? AND indice=?',[$p['id'],$indice])->fetchColumn()) throw new ErrorAplicacion('Tu respuesta ya quedó registrada.',409);
            $tiempo=max(0,$ahora-((float)$sala['inicio']+$indice*((int)$sala['tiempo']+4)));
            $pregunta = $preguntas[$indice];
            $opcion = null;
            $texto = null;
            if (($pregunta['tipo'] ?? null) === Preguntas::COMPLETACION) {
                $texto = Preguntas::textoRespuesta($datos['texto'] ?? null);
                $aceptadas = array_map([Preguntas::class, 'normalizarRespuesta'], $pregunta['respuestas_aceptadas']);
                $correcta = in_array(Preguntas::normalizarRespuesta($texto), $aceptadas, true);
            } else {
                $opcion = Seguridad::entero($datos['opcion'] ?? null, 0, 3, 'opción');
                if (!array_key_exists($opcion, $pregunta['opciones'])) throw new ErrorAplicacion('Selecciona una opción de la pregunta.');
                $correcta = $opcion === (int) $pregunta['correcta'];
            }
            BaseDatos::ejecutar(
                'INSERT INTO respuestas_participantes (participante_id,indice,opcion,texto,correcta,tiempo,puntos) VALUES (?,?,?,?,?,?,?)',
                [$p['id'], $indice, $opcion, $texto, (int) $correcta, $tiempo, self::puntos($correcta, $tiempo, (int) $sala['tiempo'])]
            );
            BaseDatos::ejecutar('UPDATE participantes SET visto_en=? WHERE id=?',[$ahora,$p['id']]);
            return ['mensaje'=>'Respuesta registrada. Espera a que termine el tiempo.','opcion'=>$opcion,'texto'=>$texto];
        });
    }
}
