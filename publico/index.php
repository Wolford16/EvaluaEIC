<?php
declare(strict_types=1);
require dirname(__DIR__) . '/configuracion/aplicacion.php';
use EvaluaIA\Servicios\{Seguridad, BaseDatos, Evaluacion, InteligenciaArtificial, ErrorAplicacion};
use EvaluaIA\Controladores\{Autenticacion, Materiales, Salas, Administracion};

ini_set('session.use_strict_mode','1');
$rutaSesiones=RAIZ.'/almacenamiento/sesiones';
if (!is_dir($rutaSesiones)) mkdir($rutaSesiones,0700,true);
session_save_path($rutaSesiones);
session_name('evaluaia_sesion');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','path'=>'/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');
$ruta = rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/', '/') ?: '/';
$esApi = str_starts_with($ruta,'/api/');
try {
    if ($esApi) {
        header('Content-Type: application/json; charset=utf-8');
        $accion=substr($ruta,5); $metodo=$_SERVER['REQUEST_METHOD'];
        $lecturas=['materiales','material','usuarios','motores','salas','estado'];
        $esLectura=in_array($accion,$lecturas,true);
        if ($metodo!==($esLectura?'GET':'POST')) throw new ErrorAplicacion('Método no permitido.',405);
        if (!$esLectura) Seguridad::csrf();
        $usuario=Seguridad::usuario(false);
        if (!in_array($accion,['ingresar','unirse','estado','responder','salir'],true)) $usuario=Seguridad::usuario();
        if (in_array($accion,['usuarios','crear-usuario','estado-usuario','probar-motor'],true)) $usuario=Seguridad::administrador();
        if (!in_array($accion,['ingresar','salir'],true)) session_write_close();
        $datos=[];
        if ($metodo==='POST' && str_contains($_SERVER['CONTENT_TYPE']??'','application/json')) {
            $cuerpo=file_get_contents('php://input');
            if (strlen($cuerpo)>250000) throw new ErrorAplicacion('La solicitud es demasiado grande.',413);
            try { $datos=json_decode($cuerpo,true,64,JSON_THROW_ON_ERROR); }
            catch (\Throwable) { throw new ErrorAplicacion('La solicitud no contiene un JSON válido.',400); }
            if (!is_array($datos)) throw new ErrorAplicacion('Revisa los datos enviados.',400);
        }
        $token=preg_replace('/^Bearer\s+/i','',$_SERVER['HTTP_AUTHORIZATION']??'');
        $resultado=match($accion) {
            'ingresar'=>Autenticacion::ingresar($datos), 'salir'=>Autenticacion::salir(),
            'materiales'=>Materiales::listar($usuario),
            'material'=>Materiales::detalle(Seguridad::entero($_GET['id']??null,1,PHP_INT_MAX,'material'),$usuario),
            'preparar-material'=>Materiales::preparar($usuario), 'guardar-material'=>Materiales::guardar($datos,$usuario),
            'descartar-borrador'=>Materiales::descartar($datos,$usuario),
            'eliminar-material'=>Materiales::eliminar(Seguridad::entero($datos['id']??null,1,PHP_INT_MAX,'material'),$usuario),
            'eliminar-pregunta'=>Materiales::eliminarPregunta(Seguridad::entero($datos['id']??null,1,PHP_INT_MAX,'pregunta'),$usuario),
            'crear-sala'=>Salas::crear($datos,$usuario), 'iniciar-sala'=>Salas::iniciar($datos,$usuario),
            'salas'=>Salas::listar($usuario), 'unirse'=>Evaluacion::unirse($datos), 'responder'=>Evaluacion::responder($datos,$token),
            'estado'=>Evaluacion::estado(mb_strtoupper((string)($_GET['codigo']??'')),$usuario,$token),
            'usuarios'=>Administracion::usuarios(), 'crear-usuario'=>Administracion::crearUsuario($datos),
            'estado-usuario'=>Administracion::cambiarEstado($datos,$usuario), 'motores'=>['motores'=>InteligenciaArtificial::proveedores()],
            'probar-motor'=>InteligenciaArtificial::probar((string)($datos['motor']??'openrouter')),
            default=>throw new ErrorAplicacion('Ruta no encontrada.',404)
        };
        echo json_seguro(['ok'=>true]+$resultado); exit;
    }
    $usuario=Seguridad::usuario(false);
    if ($ruta==='/') { header('Location: '.($usuario ? ($usuario['rol']==='admin'?'/admin':'/docente') : '/ingreso')); exit; }
    if (preg_match('#^/archivo/(\d+)$#',$ruta,$coincidencias)) {
        $documento=Seguridad::documento((int)$coincidencias[1],Seguridad::usuario());
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
    elseif ($ruta==='/ingreso') $vista='ingreso';
    elseif (in_array($ruta,['/admin','/docente'],true)) {
        if (!$usuario) { header('Location: /ingreso'); exit; }
        if ($ruta==='/admin' && $usuario['rol']!=='admin') throw new ErrorAplicacion('Esta página es solo para administradores.',403);
        $vista=$ruta==='/admin'?'administracion':'docente';
    } else throw new ErrorAplicacion('La página no existe.',404);
    $datosIniciales=['vista'=>$vista,'usuario'=>$usuario,'codigo'=>$codigo,'url'=>rtrim(entorno('APLICACION_URL','http://localhost:8080'),'/'),'avatares'=>Evaluacion::AVATARES];
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
    else { header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>EvaluaEIC</title><link rel="stylesheet" href="/css/estilos.css"><main class="pagina-error panel"><h1>EvaluaEIC</h1><p>'.escapar($mensaje).'</p><a class="boton primario" href="/ingreso">Volver al ingreso</a><p class="texto-suave">Si es tu primer inicio, ejecuta iniciar.ps1 desde la carpeta del proyecto.</p></main></html>'; }
}
