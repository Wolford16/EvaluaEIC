<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
putenv('OPENROUTER_API_KEY=clave-ficticia-para-pruebas');
putenv('OPENROUTER_MODEL=modelo-de-prueba');
require dirname(__DIR__) . '/configuracion/aplicacion.php';
use EvaluaEIC\Servicios\{ClienteOpenRouter, GeneradorOpenRouter, InteligenciaArtificial, ErrorAplicacion};

$comprobaciones = 0;
function comprobar(bool $condicion, string $mensaje): void {
    global $comprobaciones;
    if (!$condicion) throw new RuntimeException($mensaje);
    $comprobaciones++;
}
function esperarError(callable $operacion, ?string $codigo): ErrorAplicacion {
    try { $operacion(); }
    catch (ErrorAplicacion $error) { comprobar($error->codigo === $codigo, 'Código de error esperado: ' . $codigo . ', recibido: ' . ($error->codigo ?? 'vacío')); return $error; }
    throw new RuntimeException('Se esperaba el error ' . $codigo);
}
function respuesta(array $datos, int $estado = 200, array $cabeceras = []): array {
    return ['estado'=>$estado,'cuerpo'=>json_encode($datos, JSON_UNESCAPED_UNICODE),'cabeceras'=>$cabeceras,'error'=>0];
}
function mensaje(mixed $contenido, string $fin = 'stop'): array {
    return respuesta(['choices'=>[['message'=>['content'=>is_string($contenido) ? $contenido : json_encode($contenido, JSON_UNESCAPED_UNICODE)],'finish_reason'=>$fin]],'usage'=>['total_tokens'=>25]]);
}
function pregunta(int $indice): array {
    return ['enunciado'=>"¿Qué registro corresponde al procedimiento número {$indice}?",'opciones'=>["Registro de custodia {$indice}","Acceso sin control {$indice}","Traslado informal {$indice}","Embalaje abierto {$indice}"],'correcta'=>0,'explicacion'=>"El procedimiento {$indice} exige registrar a la persona responsable."];
}
function simulador(array &$peticiones, ?callable $alterar = null, int $contexto = 8192, int $salida = 4096, array $parametros = ['structured_outputs','response_format']): Closure {
    $indice = 0; $chats = 0;
    return function(string $ruta, ?array $datos, array $cabeceras, int $tiempo) use (&$peticiones,&$indice,&$chats,$alterar,$contexto,$salida,$parametros): array {
        $peticiones[] = ['ruta'=>$ruta,'datos'=>$datos,'cabeceras'=>$cabeceras,'tiempo'=>$tiempo];
        if ($ruta === '/models') {
            return respuesta(['data'=>[
                ['id'=>'otro/modelo'],
                ['id'=>getenv('OPENROUTER_MODEL'),'context_length'=>$contexto,
                    'top_provider'=>['max_completion_tokens'=>$salida], 'supported_parameters'=>$parametros],
            ]]);
        }
        $chats++;
        if ($alterar) { $alternativa = $alterar($datos, $chats); if ($alternativa !== null) return $alternativa; }
        $sistema = $datos['messages'][0]['content'];
        if (str_contains($sistema, '{"resumen":')) {
            return mensaje(['resumen'=>'La cadena de custodia documenta entregas, responsables y estado del indicio. Bloque ' . substr(hash('sha256',$datos['messages'][1]['content']),0,10) . '.']);
        }
        if (str_contains($sistema, '{"estado":')) return mensaje(['estado'=>'ok']);
        preg_match('/Genera exactamente (\d+) preguntas/u', $datos['messages'][1]['content'], $coincidencia);
        $cantidad = (int)($coincidencia[1] ?? 1); $preguntas = [];
        for ($n=0;$n<$cantidad;$n++) $preguntas[] = pregunta(++$indice);
        return mensaje(['preguntas'=>$preguntas]);
    };
}

$textoCorto = str_repeat('La cadena de custodia registra la entrega de cada indicio y a sus responsables. El embalaje se identifica y protege. ', 4);
$peticiones = [];
$cliente = new ClienteOpenRouter(simulador($peticiones, null, 65536, 8192));
$textoMedio = str_repeat($textoCorto, 75);
$resultado = (new GeneradorOpenRouter($cliente))->generar($textoMedio, 10);
comprobar(count($resultado['preguntas']) === 10 && count(array_filter($resultado['preguntas'], fn($p)=>count($p['opciones'])===4)) === 10, 'Entrega diez preguntas con cuatro opciones cada una.');
comprobar($resultado['analisis']['resumido'] === false, 'El texto de un PDF como Celulas.pdf cabe completo.');
comprobar(count($peticiones) === 2 && str_contains($peticiones[1]['datos']['messages'][1]['content'], trim($textoMedio)), 'Consulta el modelo y genera en un solo lote con todo el texto.');
comprobar($resultado['analisis']['tokens'] === 25, 'Acumula el uso reportado por OpenRouter.');
comprobar($peticiones[1]['datos']['response_format']['json_schema']['strict'] === true, 'Solicita un esquema estricto.');
comprobar($peticiones[1]['datos']['response_format']['json_schema']['schema']['additionalProperties'] === false, 'El objeto raíz es cerrado.');
comprobar($peticiones[1]['cabeceras'][0] === 'Authorization: Bearer clave-ficticia-para-pruebas', 'Autentica mediante cabecera.');
comprobar($peticiones[1]['datos']['max_tokens'] > 0, 'Define un límite de salida.');
comprobar(!array_key_exists('api_key', $peticiones[1]['datos']), 'La clave no va en el JSON.');
comprobar(!array_key_exists('reasoning', $peticiones[1]['datos']), 'No impone opciones de razonamiento a modelos que no las admiten.');

// Ultra se prueba en modo texto y Super VL con su esquema JSON.
putenv('OPENROUTER_MODEL=nvidia/nemotron-3-ultra-550b-a55b:free');
$peticionesRazonamiento = [];
$clienteRazonamiento = new ClienteOpenRouter(simulador($peticionesRazonamiento, null, 8192, 4096, ['reasoning','max_tokens']));
$resultadoRazonamiento = (new GeneradorOpenRouter($clienteRazonamiento))->generar(str_repeat($textoCorto, 25), 8);
comprobar(count($resultadoRazonamiento['preguntas']) === 8 && $resultadoRazonamiento['analisis']['formato'] === 'json_texto', 'Genera ocho preguntas con el formato que admite Nemotron.');
$chatsRazonamiento = array_values(array_filter($peticionesRazonamiento, fn($p)=>$p['datos'] !== null));
foreach ($chatsRazonamiento as $chat) {
    comprobar(!isset($chat['datos']['response_format']) && $chat['datos']['reasoning']['enabled'] === false, 'Nemotron recibe parámetros compatibles en todas las solicitudes.');
}
putenv('OPENROUTER_MODEL=nvidia/nemotron-3.5-super-vl:free');
$peticionesSuper = [];
$clienteSuper = new ClienteOpenRouter(simulador($peticionesSuper, null, 65536, 8192, ['reasoning','structured_outputs']));
(new GeneradorOpenRouter($clienteSuper))->generar($textoMedio, 10);
comprobar($peticionesSuper[1]['datos']['reasoning']['enabled'] === false && $peticionesSuper[1]['datos']['response_format']['type'] === 'json_schema', 'Super VL usa el formato probado con razonamiento desactivado.');
$peticionesBloque = [];
$clienteBloque = new ClienteOpenRouter(simulador($peticionesBloque, fn()=>mensaje("```json\n{\"estado\":\"ok\"}\n```"), 8192, 4096, []));
comprobar($clienteBloque->completar([], [], 1024, 10) === ['estado'=>'ok'], 'Lee un objeto JSON rodeado por un bloque de código.');
putenv('OPENROUTER_MODEL=modelo-de-prueba');

$peticionesResumen = [];
$falloResumen = false;
$clienteResumen = new ClienteOpenRouter(simulador($peticionesResumen, static function(array $datos) use (&$falloResumen): ?array {
    if (!$falloResumen && str_contains($datos['messages'][0]['content'], '{"resumen":')) {
        $falloResumen = true;
        return respuesta(['error'=>['code'=>'json_validate_failed']], 400);
    }
    return null;
}));
$resultadoResumen = (new GeneradorOpenRouter($clienteResumen))->generar(str_repeat($textoCorto, 25), 8);
comprobar($falloResumen && count($resultadoResumen['preguntas']) === 8, 'Un fallo transitorio de JSON al resumir se recupera sin perder la generación.');

$peticiones = [];
$textoLargo = str_repeat("Párrafo docente: el indicio se identifica, se embala y cada entrega queda documentada.\n", 650) . 'ULTIMO_TEMA_DEL_DOCUMENTO_987';
$resultado = (new GeneradorOpenRouter(new ClienteOpenRouter(simulador($peticiones), ['presupuesto_tokens'=>6000])))->generar($textoLargo, 5);
comprobar($resultado['analisis']['resumido'] === true && $resultado['analisis']['bloques'] > 1, 'Los PDF extensos se analizan por bloques.');
$bloques = [];
foreach ($peticiones as $p) {
    if ($p['datos'] && str_contains($p['datos']['messages'][0]['content'], '{"resumen":')) {
        preg_match('/<MATERIAL>\n(.*)\n<\/MATERIAL>/su', $p['datos']['messages'][1]['content'], $contenido);
        $bloques[] = $contenido[1];
    }
}
$originales = array_slice($bloques, 0, $resultado['analisis']['bloques']);
comprobar(implode('', $originales) === trim($textoLargo), 'No descarta el final del documento ni pierde texto entre bloques.');
comprobar(str_contains(end($originales), 'ULTIMO_TEMA_DEL_DOCUMENTO_987'), 'Incluye el último tema.');
comprobar(count(array_filter($bloques, fn($b)=>!mb_check_encoding($b,'UTF-8'))) === 0, 'Los bloques mantienen el texto UTF-8.');
foreach ($peticiones as $p) {
    if (!$p['datos']) continue;
    $datos=$p['datos'];
    $cota=strlen(json_encode($datos['messages'],JSON_UNESCAPED_UNICODE))+strlen(json_encode($datos['response_format'],JSON_UNESCAPED_UNICODE))+$datos['max_tokens'];
    comprobar($cota <= 6000, 'Reserva entrada, esquema y salida dentro del presupuesto.');
}

$peticiones = [];
$sinEstricto = function(array $datos, int $chat): ?array {
    if ($chat === 1) return respuesta(['error'=>['message'=>'response_format json_schema is not supported with this model','code'=>'unsupported_value']],400);
    return null;
};
$resultado = (new GeneradorOpenRouter(new ClienteOpenRouter(simulador($peticiones,$sinEstricto))))->generar($textoCorto,5);
$chats=array_values(array_filter($peticiones,fn($p)=>$p['datos']!==null));
comprobar($resultado['analisis']['formato'] === 'json_object', 'Adapta el formato en modelos sin JSON Schema.');
comprobar(count(array_filter($chats,fn($p)=>$p['datos']['response_format']['type']==='json_schema')) === 1, 'Solo prueba el esquema una vez por operación.');
comprobar(count(array_unique(array_map(fn($p)=>$p['datos']['model'],$chats))) === 1, 'Mantiene el mismo modelo durante el cambio de formato.');

foreach ([
    [401,'openrouter_clave',[],['message'=>'clave-ficticia-para-pruebas no es válida']],
    [402,'openrouter_saldo',[],[]], [403,'openrouter_permisos',[],[]], [404,'openrouter_modelo',[],[]],
    [429,'openrouter_limite',['retry-after'=>'7.2'],[]],
    [413,'openrouter_contexto',[],[]], [503,'openrouter_servidor',[],[]],
    [400,'openrouter_contexto',[],['message'=>'Request too large']],
    [400,'openrouter_solicitud',[],['message'=>'Invalid schema: additionalProperties must be false']],
] as [$estado,$codigo,$cabeceras,$errorProveedor]) {
    $llamadas=0;
    $cliente=new ClienteOpenRouter(function()use($estado,$cabeceras,$errorProveedor,&$llamadas){$llamadas++;return respuesta(['error'=>$errorProveedor],$estado,$cabeceras);});
    $error=esperarError(fn()=>$cliente->metadatos(),$codigo);
    comprobar(!str_contains($error->getMessage(),'clave-ficticia-para-pruebas'),'Los errores no revelan la clave.');
    comprobar($llamadas===1,'No repite errores de autenticación, cuota o servidor.');
    if($estado===429)comprobar($error->reintentarEn===8,'Respeta Retry-After y redondea hacia arriba.');
}
foreach ([CURLE_COULDNT_CONNECT,CURLE_OPERATION_TIMEDOUT,CURLE_SSL_CACERT] as $codigoCurl) {
    $cliente=new ClienteOpenRouter(fn()=>['estado'=>0,'cuerpo'=>'','cabeceras'=>[],'error'=>$codigoCurl]);
    esperarError(fn()=>$cliente->metadatos(),'openrouter_conexion');
}

$peticiones=[];
$cliente=new ClienteOpenRouter(simulador($peticiones,fn($datos,$chat)=>$chat===1?mensaje('{'):null));
$resultado=(new GeneradorOpenRouter($cliente))->generar($textoCorto,5);
comprobar(count($resultado['preguntas'])===5 && count($peticiones)===3,'Reintenta un JSON incompleto sin duplicar preguntas.');

$peticiones=[];
$cliente=new ClienteOpenRouter(simulador($peticiones,fn($datos,$chat)=>$chat===2?mensaje(['preguntas'=>[pregunta(1),pregunta(2)]]):null), ['preguntas_por_lote'=>3]);
$resultado=(new GeneradorOpenRouter($cliente))->generar($textoCorto,5);
comprobar(count(array_unique(array_column($resultado['preguntas'],'enunciado')))===5,'Detecta duplicados entre lotes y reintenta.');
comprobar(count($peticiones)===4,'El reintento por duplicados es acotado.');

$peticiones=[];
$preguntaIncompleta = pregunta(1);
$preguntaIncompleta['opciones'] = ['A','B','C','D'];
$cliente = new ClienteOpenRouter(simulador($peticiones,fn($datos,$chat)=>$chat===1?mensaje(['preguntas'=>[$preguntaIncompleta]]):null));
$resultado = (new GeneradorOpenRouter($cliente))->generar($textoCorto,1);
comprobar(count($peticiones)===3 && $resultado['preguntas'][0]['opciones']===pregunta(1)['opciones'], 'Reintenta si el modelo devuelve solo marcadores como opciones.');

$peticiones=[];
$cliente=new ClienteOpenRouter(simulador($peticiones,fn()=>mensaje('{','length')));
esperarError(fn()=>(new GeneradorOpenRouter($cliente))->generar($textoCorto,3),'openrouter_formato');
comprobar(count($peticiones)===3,'Una salida truncada persistente detiene la operación tras un reintento.');

$peticiones=[];
$cliente=new ClienteOpenRouter(simulador($peticiones, fn()=>respuesta(['choices'=>[['message'=>['refusal'=>'No permitido'],'finish_reason'=>'stop']]])));
esperarError(fn()=>$cliente->completar([],GeneradorOpenRouter::esquemaPreguntas(),500,10),'openrouter_rechazo');

$peticiones=[];
$cliente=new ClienteOpenRouter(simulador($peticiones,null,8192,1024));
$resultado=(new GeneradorOpenRouter($cliente))->generar($textoCorto,5);
comprobar(count($resultado['preguntas'])===5,'Se adapta a un modelo con poca salida disponible.');
comprobar(max(array_map(fn($p)=>$p['datos']['max_tokens']??0,$peticiones))<=1024,'No excede la salida informada por el modelo.');

$peticiones=[];
$resultado=(new GeneradorOpenRouter(new ClienteOpenRouter(simulador($peticiones))))->generar($textoCorto,30);
comprobar(count($resultado['preguntas'])===30,'Puede preparar treinta preguntas por lotes.');

$peticiones=[];
$cliente=new ClienteOpenRouter(simulador($peticiones),['max_solicitudes'=>5]);
esperarError(fn()=>(new GeneradorOpenRouter($cliente))->generar(str_repeat($textoCorto,200),5),'openrouter_documento_extenso');
comprobar(count($peticiones)===1,'Detecta demasiados bloques antes de enviar el contenido.');

$peticiones=[];
$cliente=new ClienteOpenRouter(simulador($peticiones));
esperarError(fn()=>(new GeneradorOpenRouter($cliente))->generar('PDF sin texto',5), null);
comprobar(count($peticiones)===0,'Un PDF sin texto se rechaza antes de contactar al proveedor.');

$bloques=GeneradorOpenRouter::dividirTexto('Árbol, investigación, cadena de custodia y tecnología.',12);
comprobar(implode('',$bloques)==='Árbol, investigación, cadena de custodia y tecnología.','División exacta con acentos.');
$proveedores=InteligenciaArtificial::proveedores();
comprobar(count($proveedores)===1 && $proveedores[0]['id']==='openrouter','OpenRouter es el único proveedor.');
comprobar(!str_contains(json_encode($proveedores),'clave-ficticia-para-pruebas'),'La configuración pública oculta la clave.');
putenv('OPENROUTER_API_KEY=');
esperarError(fn()=>(new ClienteOpenRouter())->validarConfiguracion(),'openrouter_configuracion');
echo "Correcto: {$comprobaciones} comprobaciones de OpenRouter con transporte simulado; no se usó una clave real.\n";
