<?php
declare(strict_types=1);
namespace EvaluaEIC\Controladores;
use EvaluaEIC\Servicios\{BaseDatos, Seguridad, ErrorAplicacion, Evaluacion, InformeResultados, ResultadosExcel, Preguntas, Comparacion};
final class Salas {
    public static function crear(array $datos,array $usuario): array {
        $url = url_publica();
        if ($url === null) throw new ErrorAplicacion('Configura APLICACION_URL con la dirección pública de EvaluaEIC para crear salas.', 503);
        $id=Seguridad::entero($datos['documento_id']??null,1,PHP_INT_MAX,'documento');
        $tiempo=Seguridad::entero($datos['tiempo']??20,15,30,'tiempo');
        if (!in_array($tiempo,[15,20,30],true)) throw new ErrorAplicacion('Elige 15, 20 o 30 segundos.');
        $modalidad=$datos['modalidad']??'individual';
        if (!in_array($modalidad,['individual','equipos'],true)) throw new ErrorAplicacion('Elige una modalidad de evaluación.');
        $equipos=$modalidad==='equipos' ? Seguridad::entero($datos['cantidad_equipos']??null,2,8,'la cantidad de equipos') : 0;
        $entrada = Seguridad::entero($datos['entrada'] ?? 0, 0, 1, 'el tipo de aplicación');
        return BaseDatos::transaccion(function () use ($id, $usuario, $url, $tiempo, $equipos, $entrada): array {
            $documento = Seguridad::documento($id, $usuario, permitirCompartido: true, bloquear: true);
            $preguntas = Materiales::preguntas($id);
            if (!$preguntas) throw new ErrorAplicacion('El material necesita al menos una pregunta.');
            shuffle($preguntas);
            $posiciones = [];
            $copia = [];
            foreach ($preguntas as $pregunta) {
                $opciones = $pregunta['opciones'];
                if ($pregunta['tipo'] !== Preguntas::OPCION_MULTIPLE) {
                    $copia[] = array_intersect_key($pregunta, array_flip([
                        'tipo', 'enunciado', 'opciones', 'correcta', 'explicacion', 'respuestas_aceptadas',
                    ]));
                    continue;
                }
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
            if ($equipos) $copia=['preguntas'=>$copia,'cantidad_equipos'=>$equipos];
            $codigo = self::guardarSala($id, $documento['titulo'], $usuario['nombre'], (int) $usuario['id'], $tiempo,
                json_encode($copia, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $entrada);
            return ['codigo' => $codigo, 'url' => $url . '/sala/' . $codigo];
        });
    }
    private static function guardarSala(?int $documento, string $titulo, string $docente, int $responsable, int $tiempo,
        string $preguntas, int $entrada = 0, ?int $entradaId = null): string {
        do {
            $codigo = 'EVAL-' . strtoupper(bin2hex(random_bytes(3)));
            try {
                BaseDatos::ejecutar(
                    'INSERT INTO salas_evaluacion
                     (documento_id,titulo,docente,codigo,creado_por_id,tiempo,preguntas_json,creado_en,es_entrada,entrada_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$documento,$titulo,$docente,$codigo,$responsable,$tiempo,$preguntas,date('Y-m-d H:i:s'),$entrada,$entradaId]
                );
                return $codigo;
            } catch (\PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) !== 1062 ||
                    !BaseDatos::ejecutar('SELECT id FROM salas_evaluacion WHERE codigo = ? FOR UPDATE', [$codigo])->fetchColumn()) {
                    throw $error;
                }
            }
        } while (true);
    }

    public static function crearSalida(array $datos, array $usuario): array {
        $codigo = mb_strtoupper(Seguridad::texto($datos['codigo'] ?? '', 4, 12, 'Código de entrada'));
        return BaseDatos::transaccion(function () use ($codigo, $usuario): array {
            $entrada = BaseDatos::bloquearSala($codigo);
            if (!$entrada || !$entrada['es_entrada']) throw new ErrorAplicacion('No encontramos el examen de entrada.', 404);
            if (!Evaluacion::autorizar($entrada, $usuario)) throw new ErrorAplicacion('No tienes acceso a esta evaluación.', 403);
            $total = count(Evaluacion::contenido($entrada)['preguntas']);
            if (Evaluacion::fase($entrada, $total)['fase'] !== 'finalizada') {
                throw new ErrorAplicacion('La salida se puede abrir cuando termine la entrada.', 409);
            }
            $salida = BaseDatos::ejecutar('SELECT codigo FROM salas_evaluacion WHERE entrada_id = ? FOR UPDATE', [$entrada['id']])->fetchColumn();
            if (!$salida) {
                $salida = self::guardarSala($entrada['documento_id'], $entrada['titulo'], $entrada['docente'],
                    (int) $entrada['creado_por_id'], (int) $entrada['tiempo'], $entrada['preguntas_json'], entradaId: (int) $entrada['id']);
            }
            return ['codigo' => $salida];
        });
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
        $salas=BaseDatos::ejecutar('SELECT s.*, e.codigo AS codigo_entrada, x.codigo AS codigo_salida, x.estado AS estado_salida,
            x.inicio AS inicio_salida, (SELECT COUNT(*) FROM participantes p WHERE p.sala_id=s.id) AS participantes
            FROM salas_evaluacion s LEFT JOIN salas_evaluacion e ON e.id=s.entrada_id
            LEFT JOIN salas_evaluacion x ON x.entrada_id=s.id'.$condicion.' ORDER BY s.id DESC',$condicion?[$usuario['id']]:[])->fetchAll();
        foreach ($salas as &$sala) {
            $contenido=Evaluacion::contenido($sala);
            $sala['fase']=Evaluacion::fase($sala,count($contenido['preguntas']))['fase'];
            $sala['cantidad_equipos']=$contenido['cantidad_equipos'];
            $sala['salida_finalizada'] = $sala['codigo_salida'] !== null && Evaluacion::fase(
                ['estado'=>$sala['estado_salida'], 'inicio'=>$sala['inicio_salida'], 'tiempo'=>$sala['tiempo']],
                count($contenido['preguntas'])
            )['fase'] === 'finalizada';
            unset($sala['preguntas_json'], $sala['estado_salida'], $sala['inicio_salida']);
        }
        return ['salas'=>$salas];
    }
    public static function respuestas(array $consulta, ?array $usuario, string $token): array {
        $codigo = mb_strtoupper(Seguridad::texto($consulta['codigo'] ?? '', 4, 12, 'Código'));
        $id = isset($consulta['participante']) ? Seguridad::entero($consulta['participante'], 1, PHP_INT_MAX, 'participante') : null;
        return Evaluacion::historialRespuestas($codigo, $usuario, $token, $id);
    }

    public static function descargarResultados(string $codigo, array $usuario, string $formato, ?int $participante): void {
        $datos = Evaluacion::resultadosInforme($codigo, $usuario, $participante);
        $nombre = $participante === null ? 'resultados-' . $codigo : 'respuestas-' . $codigo . '-' . $participante;
        self::enviarInforme($datos, $formato, $nombre);
    }
    public static function descargarComparacion(string $codigo, array $usuario, string $formato, ?int $participante): void {
        $datos = Comparacion::resultados($codigo, $usuario, $participante);
        $nombre = 'comparacion-' . $codigo . ($participante === null ? '' : '-' . $participante);
        self::enviarInforme($datos, $formato, $nombre);
    }
    private static function enviarInforme(array $datos, string $formato, string $nombre): void {
        if ($formato === 'xlsx') {
            $contenido = ResultadosExcel::generar($datos);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        } else {
            $contenido = InformeResultados::generar($datos);
            header('Content-Type: application/pdf');
        }
        header('Content-Disposition: attachment; filename="' . $nombre . '.' . $formato . '"');
        echo $contenido;
    }
}
