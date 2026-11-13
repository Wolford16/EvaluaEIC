<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require dirname(__DIR__) . '/configuracion/aplicacion.php';
use EvaluaEIC\Servicios\{Seguridad, BaseDatos, Evaluacion, InteligenciaArtificial, ErrorAplicacion, Comparacion};
use EvaluaEIC\Controladores\{Autenticacion, Materiales, Salas, Administracion, Instalacion, Cursos};

function leerJsonSolicitud(string $cuerpo, int $limite = 250000): array {
    if (strlen($cuerpo)>$limite) throw new ErrorAplicacion('La solicitud es demasiado grande.',413);
    try { $datos=json_decode($cuerpo,true,64,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ErrorAplicacion('La solicitud no contiene un JSON válido.',400); }
    if (!is_array($datos)) throw new ErrorAplicacion('Revisa los datos enviados.',400);
    return $datos;
}

ini_set('session.use_strict_mode','1');
$rutaSesiones=RAIZ.'/almacenamiento/sesiones';
if (!is_dir($rutaSesiones)) mkdir($rutaSesiones,0700,true);
session_save_path($rutaSesiones);
session_name('evaluaeic_sesion');
$urlPublica = url_publica();
$rutaBase = ruta_base();
$usaHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || str_starts_with(strtolower($urlPublica ?? ''), 'https://');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>$usaHttps,'path'=>$rutaBase ?: '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');
$solicitada = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/');
$dentroDeLaApp = $rutaBase === '' || $solicitada === $rutaBase || str_starts_with($solicitada, $rutaBase . '/');
$ruta = $dentroDeLaApp ? (substr($solicitada, strlen($rutaBase)) ?: '/') : '';
if ($ruta === '/index.php') {
    if (isset($_GET['api'])) $ruta = '/api/' . (is_string($_GET['api']) ? $_GET['api'] : '');
    elseif (($_GET['verificar_bd'] ?? null) === '1') $ruta = '/verificar_bd.php';
    elseif (($_GET['salir'] ?? null) === '1') $ruta = '/salir';
    elseif (isset($_GET['comparacion'])) $ruta = '/comparacion';
    elseif (isset($_GET['informe_pdf'])) {
        $codigoInforme = $_GET['informe_pdf'];
        $participanteInforme = $_GET['participante'] ?? null;
        $ruta = '/resultados/' . (is_string($codigoInforme) ? rawurlencode($codigoInforme) : '');
        if ($participanteInforme !== null) {
            $ruta .= '/' . (is_string($participanteInforme) ? rawurlencode($participanteInforme) : '');
        }
        $ruta .= '.pdf';
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') $ruta = '/ingreso';
}
$esApi = str_starts_with($ruta,'/api/');
try {
    if ($ruta === '/verificar_bd.php') {
        Instalacion::mostrarDiagnostico();
        exit;
    }
    if ($ruta === '/salir') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ErrorAplicacion('Método no permitido.', 405);
        $csrfFormulario = $_POST['csrf'] ?? '';
        Seguridad::csrf(is_string($csrfFormulario) ? $csrfFormulario : '');
        $resultado = Autenticacion::salir();
        header('Location: ' . $resultado['destino'], true, 303);
        exit;
    }
    if ($esApi) {
        header('Content-Type: application/json; charset=utf-8');
        $accion=substr($ruta,5); $metodo=$_SERVER['REQUEST_METHOD'];
        $lecturas=['materiales','material','usuarios','motores','salas','estado','respuestas','comparacion'];
        $esLectura=in_array($accion,$lecturas,true);
        if ($metodo!==($esLectura?'GET':'POST')) throw new ErrorAplicacion('Método no permitido.',405);
        if (!$esLectura) {
            $csrfFormulario = $_POST['csrf'] ?? null;
            Seguridad::csrf(is_string($csrfFormulario) ? $csrfFormulario : null);
        }
        $usuario=Seguridad::usuario(false);
        if (!in_array($accion,['ingresar','unirse','estado','responder','respuestas','salir'],true)) $usuario=Seguridad::usuario();
        if (in_array($accion,['usuarios','crear-usuario','estado-usuario','eliminar-usuario','actualizar-cuenta','probar-motor'],true)) $usuario=Seguridad::administrador();
        if (!in_array($accion,['ingresar','salir','actualizar-cuenta'],true)) session_write_close();
        $datos=$metodo==='POST' ? $_POST : [];
        unset($datos['csrf']);
        $esExamenManual = in_array($accion, ['crear-examen-manual', 'editar-examen-manual'], true);
        $limiteJson = $esExamenManual ? 2400000 : 250000;
        if ($metodo==='POST' && str_contains($_SERVER['CONTENT_TYPE']??'','application/json')) {
            $datos=leerJsonSolicitud(file_get_contents('php://input'), $limiteJson);
        } elseif (($esExamenManual || $accion === 'guardar-material') && is_string($datos['preguntas'] ?? null)) {
            $datos['preguntas']=leerJsonSolicitud($datos['preguntas'], $limiteJson);
        }
        $token=preg_replace('/^Bearer\s+/i','',$_SERVER['HTTP_AUTHORIZATION']??'');
        $resultado=match($accion) {
            'ingresar'=>Autenticacion::ingresar($datos), 'salir'=>Autenticacion::salir(),
            'materiales'=>Materiales::listar($usuario),
            'material'=>Materiales::detalle(Seguridad::entero($_GET['id']??null,1,PHP_INT_MAX,'material'),$usuario),
            'crear-curso'=>Cursos::crear($datos),
            'actualizar-curso-material'=>Materiales::actualizarCurso(Seguridad::entero($datos['id']??null,1,PHP_INT_MAX,'material'),$datos,$usuario),
            'copiar-material'=>Materiales::copiar(Seguridad::entero($datos['id']??null,1,PHP_INT_MAX,'material'),$usuario),
            'crear-examen-manual'=>Materiales::crearManual($datos,$usuario),
            'editar-examen-manual'=>Materiales::editarManual(Seguridad::entero($datos['id']??null,1,PHP_INT_MAX,'material'),$datos,$usuario),
            'preparar-material'=>Materiales::preparar($usuario), 'guardar-material'=>Materiales::guardar($datos,$usuario),
            'descartar-borrador'=>Materiales::descartar($datos,$usuario),
            'eliminar-material'=>Materiales::eliminar(Seguridad::entero($datos['id']??null,1,PHP_INT_MAX,'material'),$usuario),
            'eliminar-pregunta'=>Materiales::eliminarPregunta(Seguridad::entero($datos['id']??null,1,PHP_INT_MAX,'pregunta'),$usuario),
            'crear-sala'=>Salas::crear($datos,$usuario), 'iniciar-sala'=>Salas::iniciar($datos,$usuario),
            'crear-salida'=>Salas::crearSalida($datos,$usuario),
            'comparacion'=>['participantes'=>Comparacion::resultados(
                mb_strtoupper(Seguridad::texto($_GET['codigo']??'',4,12,'Código de entrada')), $usuario
            )['participantes']],
            'salas'=>Salas::listar($usuario), 'unirse'=>Evaluacion::unirse($datos), 'responder'=>Evaluacion::responder($datos,$token),
            'estado'=>Evaluacion::estado(mb_strtoupper((string)($_GET['codigo']??'')),$usuario,$token),
            'respuestas'=>Salas::respuestas($_GET,$usuario,$token),
            'usuarios'=>Administracion::usuarios(), 'crear-usuario'=>Administracion::crearUsuario($datos),
            'estado-usuario'=>Administracion::gestionarUsuario($datos,$usuario),
            'eliminar-usuario'=>Administracion::gestionarUsuario($datos,$usuario,true),
            'actualizar-cuenta'=>Administracion::actualizarCuenta($datos,$usuario),
            'motores'=>['motores'=>InteligenciaArtificial::proveedores()],
            'probar-motor'=>InteligenciaArtificial::probar((string)($datos['motor']??'openrouter')),
            default=>throw new ErrorAplicacion('Ruta no encontrada.',404)
        };
        echo json_seguro(['ok'=>true]+$resultado); exit;
    }
    $usuario=Seguridad::usuario(false);
    if ($ruta === '/comparacion') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new ErrorAplicacion('Método no permitido.', 405);
        $responsable = $usuario ?? Seguridad::usuario();
        $codigoEntrada = mb_strtoupper(Seguridad::texto($_GET['comparacion'], 4, 12, 'Código de entrada'));
        $formato = $_GET['formato'] ?? 'pdf';
        if (!in_array($formato, ['pdf', 'xlsx'], true)) throw new ErrorAplicacion('Selecciona PDF o Excel.');
        $participante = isset($_GET['participante']) ? Seguridad::entero($_GET['participante'], 1, PHP_INT_MAX, 'Participante') : null;
        session_write_close();
        Salas::descargarComparacion($codigoEntrada, $responsable, $formato, $participante);
        exit;
    }
    if ($ruta==='/' || $ruta==='/index.php') { header('Location: '.ruta_app($usuario ? ($usuario['rol']==='admin'?'/admin':'/docente') : '/ingreso')); exit; }
    if (preg_match('#^/resultados/([A-Za-z0-9-]{4,12})(?:/([1-9][0-9]*))?\.(pdf|xlsx)$#D', $ruta, $coincidencias)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new ErrorAplicacion('Método no permitido.', 405);
        $responsable = $usuario ?? Seguridad::usuario();
        $participante = $coincidencias[2] === '' ? null : Seguridad::entero($coincidencias[2], 1, PHP_INT_MAX, 'Participante');
        session_write_close();
        Salas::descargarResultados(strtoupper($coincidencias[1]), $responsable, $coincidencias[3], $participante);
        exit;
    }
    if (preg_match('#^/archivo/(\d+)$#',$ruta,$coincidencias)) {
        $documento = Seguridad::documento((int) $coincidencias[1], Seguridad::usuario(), permitirCompartido: true);
        if ($documento['ruta_archivo'] === null) throw new ErrorAplicacion('Este examen no tiene un PDF adjunto.', 404);
        $archivo=RAIZ.'/almacenamiento/documentos/'.$documento['ruta_archivo'];
        if (!is_file($archivo)) throw new ErrorAplicacion('El archivo no está disponible.',404);
        session_write_close();
        header('Content-Type: application/pdf');
        header("Content-Disposition: inline; filename*=UTF-8''".rawurlencode($documento['nombre_archivo']));
        header('Content-Length: '.filesize($archivo)); readfile($archivo); exit;
    }
    $codigo='';
    if (preg_match('#^/sala/([A-Za-z0-9-]{4,12})$#',$ruta,$coincidencias)) { $codigo=strtoupper($coincidencias[1]); $vista='participante'; }
    elseif ($ruta==='/participar') $vista='participante';
    elseif ($ruta==='/ingreso') {
        $vista='ingreso';
        [$instalacionDisponible, $mensajeInstalacion] = Instalacion::prepararIngreso();
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$instalacionDisponible && http_response_code() !== 503) {
            try {
                $csrfFormulario = $_POST['csrf'] ?? '';
                Seguridad::csrf(is_string($csrfFormulario) ? $csrfFormulario : '');
                $resultado = Autenticacion::ingresar($_POST);
                header('Location: ' . $resultado['destino'], true, 303);
                exit;
            } catch (ErrorAplicacion $error) {
                http_response_code($error->estado);
                $mensajeIngreso = $error->getMessage();
            }
        }
    }
    elseif ($ruta==='/crear_administrador.php') {
        $vista='ingreso';
        [$instalacionDisponible, $mensajeInstalacion] = Instalacion::prepararPrimerAcceso();
        $primerAcceso = $instalacionDisponible && !empty($_SESSION['primer_acceso']);
    }
    elseif (in_array($ruta,['/admin','/docente'],true)) {
        if (!$usuario) { header('Location: '.ruta_app('/ingreso')); exit; }
        if ($ruta==='/admin' && $usuario['rol']!=='admin') throw new ErrorAplicacion('Esta página es solo para administradores.',403);
        $vista=$ruta==='/admin'?'administracion':'docente';
    } else throw new ErrorAplicacion('La página no existe.',404);
    $datosIniciales=['vista'=>$vista,'usuario'=>$usuario,'codigo'=>$codigo,'url'=>$urlPublica,'rutaBase'=>$rutaBase,'avatares'=>Evaluacion::AVATARES];
    $csrf=$_SESSION['csrf']; session_write_close();
    require RAIZ.'/vistas/estructura.php';
} catch (\Throwable $error) {
    $estado=$error instanceof ErrorAplicacion ? $error->estado : 500;
    http_response_code($estado);
    if (!$error instanceof ErrorAplicacion) error_log($error->__toString());
    $mensaje=$error instanceof ErrorAplicacion ? $error->getMessage() : 'No se pudo completar la operación. Comprueba la instalación y consulta almacenamiento/servidor.log.';
    if ($esApi) {
        $respuestaError=['ok'=>false,'mensaje'=>$mensaje];
        if ($error instanceof ErrorAplicacion && $error->codigo) $respuestaError['codigo_error']=$error->codigo;
        if ($error instanceof ErrorAplicacion && $error->reintentarEn) {
            header('Retry-After: '.$error->reintentarEn);
            $respuestaError['reintentar_en']=$error->reintentarEn;
        }
        echo json_seguro($respuestaError);
    }
    else { header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>EvaluaEIC</title><link rel="stylesheet" href="'.escapar(ruta_app('/css/estilos.css')).'"><main class="pagina-error panel"><h1>EvaluaEIC</h1><p>'.escapar($mensaje).'</p><a class="boton primario" href="'.escapar(ruta_app('/ingreso')).'">Volver al ingreso</a></main></html>'; }
}
