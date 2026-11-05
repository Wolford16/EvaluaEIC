<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
putenv('GROQ_API_KEY=clave-ficticia-para-pruebas');
putenv('GROQ_MODEL=modelo-de-prueba');
require dirname(__DIR__) . '/configuracion/aplicacion.php';
use EvaluaIA\Servicios\{ClienteGroq, GeneradorGroq, InteligenciaArtificial, ErrorAplicacion};

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
function simulador(array &$peticiones, ?callable $alterar = null, int $contexto = 8192, int $salida = 4096): Closure {
    $indice = 0; $chats = 0;
    return function(string $ruta, ?array $datos, array $cabeceras, int $tiempo) use (&$peticiones,&$indice,&$chats,$alterar,$contexto,$salida): array {
        $peticiones[] = ['ruta'=>$ruta,'datos'=>$datos,'cabeceras'=>$cabeceras,'tiempo'=>$tiempo];
        if (str_starts_with($ruta, '/models/')) return respuesta(['id'=>getenv('GROQ_MODEL'),'active'=>true,'context_window'=>$contexto,'max_completion_tokens'=>$salida]);
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
$cliente = new ClienteGroq(simulador($peticiones));
$resultado = (new GeneradorGroq($cliente))->generar($textoCorto, 5);
comprobar(count($resultado['preguntas']) === 5, 'Entrega cinco preguntas completas.');
comprobar($resultado['analisis']['resumido'] === false, 'Un documento breve se analiza completo sin resumen.');
comprobar(count($peticiones) === 3, 'Consulta el modelo y genera en dos lotes.');
comprobar($resultado['analisis']['tokens'] === 50, 'Acumula el uso reportado por Groq.');
comprobar($peticiones[1]['datos']['response_format']['json_schema']['strict'] === true, 'Solicita un esquema estricto.');
comprobar($peticiones[1]['datos']['response_format']['json_schema']['schema']['additionalProperties'] === false, 'El objeto raíz es cerrado.');
comprobar($peticiones[1]['cabeceras'][0] === 'Authorization: Bearer clave-ficticia-para-pruebas', 'Autentica mediante cabecera.');
comprobar($peticiones[1]['datos']['max_completion_tokens'] > 0, 'Define un límite de salida.');
comprobar(!array_key_exists('api_key', $peticiones[1]['datos']), 'La clave no va en el JSON.');
comprobar(!array_key_exists('reasoning_effort', $peticiones[1]['datos']), 'No impone opciones de razonamiento a modelos que no las admiten.');

// El fallo del PDF real ocurría al resumir con una salida demasiado corta.
putenv('GROQ_MODEL=openai/gpt-oss-20b');
$peticionesRazonamiento = [];
$clienteRazonamiento = new ClienteGroq(simulador($peticionesRazonamiento, static function(array $datos): ?array {
    if (str_contains($datos['messages'][0]['content'], '{"resumen":')
        && (($datos['max_completion_tokens'] ?? 0) < 1024 || ($datos['reasoning_effort'] ?? '') !== 'low')) {
        return respuesta(['error'=>['code'=>'json_validate_failed']], 400);
    }
    return null;
}));
$resultadoRazonamiento = (new GeneradorGroq($clienteRazonamiento))->generar(str_repeat($textoCorto, 25), 8);
comprobar(count($resultadoRazonamiento['preguntas']) === 8 && $resultadoRazonamiento['analisis']['resumido'], 'Genera ocho preguntas de un PDF por bloques con margen para el razonamiento.');
$chatsRazonamiento = array_values(array_filter($peticionesRazonamiento, fn($p)=>$p['datos'] !== null));
comprobar(count(array_filter($chatsRazonamiento, fn($p)=>($p['datos']['reasoning_effort'] ?? '') !== 'low')) === 0, 'GPT-OSS usa esfuerzo bajo en resúmenes y preguntas.');
putenv('GROQ_MODEL=modelo-de-prueba');

$peticionesResumen = [];
$falloResumen = false;
$clienteResumen = new ClienteGroq(simulador($peticionesResumen, static function(array $datos) use (&$falloResumen): ?array {
    if (!$falloResumen && str_contains($datos['messages'][0]['content'], '{"resumen":')) {
        $falloResumen = true;
        return respuesta(['error'=>['code'=>'json_validate_failed']], 400);
    }
    return null;
}));
$resultadoResumen = (new GeneradorGroq($clienteResumen))->generar(str_repeat($textoCorto, 25), 8);
comprobar($falloResumen && count($resultadoResumen['preguntas']) === 8, 'Un fallo transitorio de JSON al resumir se recupera sin perder la generación.');

$peticiones = [];
$textoLargo = str_repeat("Párrafo docente: el indicio se identifica, se embala y cada entrega queda documentada.\n", 650) . 'ULTIMO_TEMA_DEL_DOCUMENTO_987';
$resultado = (new GeneradorGroq(new ClienteGroq(simulador($peticiones))))->generar($textoLargo, 5);
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
    $cota=strlen(json_encode($datos['messages'],JSON_UNESCAPED_UNICODE))+strlen(json_encode($datos['response_format'],JSON_UNESCAPED_UNICODE))+$datos['max_completion_tokens'];
    comprobar($cota <= 6000, 'Reserva entrada, esquema y salida dentro del presupuesto.');
}

$peticiones = [];
$sinEstricto = function(array $datos, int $chat): ?array {
    if ($chat === 1) return respuesta(['error'=>['message'=>'response_format json_schema is not supported with this model','code'=>'unsupported_value']],400);
    return null;
};
$resultado = (new GeneradorGroq(new ClienteGroq(simulador($peticiones,$sinEstricto))))->generar($textoCorto,5);
$chats=array_values(array_filter($peticiones,fn($p)=>$p['datos']!==null));
comprobar($resultado['analisis']['formato'] === 'json_object', 'Adapta el formato en modelos sin JSON Schema.');
comprobar(count(array_filter($chats,fn($p)=>$p['datos']['response_format']['type']==='json_schema')) === 1, 'Solo prueba el esquema una vez por operación.');
comprobar(count(array_unique(array_map(fn($p)=>$p['datos']['model'],$chats))) === 1, 'Mantiene el mismo modelo durante el cambio de formato.');

foreach ([
    [401,'groq_clave',[],['message'=>'clave-ficticia-para-pruebas no es válida']],
    [403,'groq_permisos',[],[]], [404,'groq_modelo',[],[]],
    [429,'groq_limite',['retry-after'=>'7.2'],[]],
    [413,'groq_contexto',[],[]], [503,'groq_servidor',[],[]],
    [400,'groq_contexto',[],['message'=>'Request too large']],
    [400,'groq_solicitud',[],['message'=>'Invalid schema: additionalProperties must be false']],
] as [$estado,$codigo,$cabeceras,$errorProveedor]) {
    $llamadas=0;
    $cliente=new ClienteGroq(function()use($estado,$cabeceras,$errorProveedor,&$llamadas){$llamadas++;return respuesta(['error'=>$errorProveedor],$estado,$cabeceras);});
    $error=esperarError(fn()=>$cliente->metadatos(),$codigo);
    comprobar(!str_contains($error->getMessage(),'clave-ficticia-para-pruebas'),'Los errores no revelan la clave.');
    comprobar($llamadas===1,'No repite errores de autenticación, cuota o servidor.');
    if($estado===429)comprobar($error->reintentarEn===8,'Respeta Retry-After y redondea hacia arriba.');
}
foreach ([CURLE_COULDNT_CONNECT,CURLE_OPERATION_TIMEDOUT,CURLE_SSL_CACERT] as $codigoCurl) {
    $cliente=new ClienteGroq(fn()=>['estado'=>0,'cuerpo'=>'','cabeceras'=>[],'error'=>$codigoCurl]);
    esperarError(fn()=>$cliente->metadatos(),'groq_conexion');
}

$peticiones=[];
$cliente=new ClienteGroq(simulador($peticiones,fn($datos,$chat)=>$chat===1?mensaje('{'):null));
$resultado=(new GeneradorGroq($cliente))->generar($textoCorto,5);
comprobar(count($resultado['preguntas'])===5 && count($peticiones)===4,'Reintenta un JSON incompleto sin duplicar preguntas.');

$peticiones=[];
$cliente=new ClienteGroq(simulador($peticiones,fn($datos,$chat)=>$chat===2?mensaje(['preguntas'=>[pregunta(1),pregunta(2)]]):null));
$resultado=(new GeneradorGroq($cliente))->generar($textoCorto,5);
comprobar(count(array_unique(array_column($resultado['preguntas'],'enunciado')))===5,'Detecta duplicados entre lotes y reintenta.');
comprobar(count($peticiones)===4,'El reintento por duplicados es acotado.');

$peticiones=[];
$cliente=new ClienteGroq(simulador($peticiones,fn()=>mensaje('{','length')));
esperarError(fn()=>(new GeneradorGroq($cliente))->generar($textoCorto,3),'groq_formato');
comprobar(count($peticiones)===3,'Una salida truncada persistente detiene la operación tras un reintento.');

$cliente=new ClienteGroq(fn()=>respuesta(['choices'=>[['message'=>['refusal'=>'No permitido'],'finish_reason'=>'stop']]]));
esperarError(fn()=>$cliente->completar([],GeneradorGroq::esquemaPreguntas(),500,10),'groq_rechazo');

$peticiones=[];
$cliente=new ClienteGroq(simulador($peticiones,null,8192,1024));
$resultado=(new GeneradorGroq($cliente))->generar($textoCorto,5);
comprobar(count($resultado['preguntas'])===5,'Se adapta a un modelo con poca salida disponible.');
comprobar(max(array_map(fn($p)=>$p['datos']['max_completion_tokens']??0,$peticiones))<=1024,'No excede la salida informada por el modelo.');

$peticiones=[];
$resultado=(new GeneradorGroq(new ClienteGroq(simulador($peticiones))))->generar($textoCorto,30);
comprobar(count($resultado['preguntas'])===30,'Puede preparar treinta preguntas por lotes.');

$peticiones=[];
$cliente=new ClienteGroq(simulador($peticiones),['max_solicitudes'=>5]);
esperarError(fn()=>(new GeneradorGroq($cliente))->generar(str_repeat($textoCorto,200),5),'groq_documento_extenso');
comprobar(count($peticiones)===1,'Detecta demasiados bloques antes de enviar el contenido.');

$peticiones=[];
$cliente=new ClienteGroq(simulador($peticiones));
esperarError(fn()=>(new GeneradorGroq($cliente))->generar('PDF sin texto',5), null);
comprobar(count($peticiones)===0,'Un PDF sin texto se rechaza antes de contactar al proveedor.');

$bloques=GeneradorGroq::dividirTexto('Árbol, investigación, cadena de custodia y tecnología.',12);
comprobar(implode('',$bloques)==='Árbol, investigación, cadena de custodia y tecnología.','División exacta con acentos.');
$proveedores=InteligenciaArtificial::proveedores();
comprobar(count($proveedores)===1 && $proveedores[0]['id']==='groq','Groq es el único proveedor.');
comprobar(!str_contains(json_encode($proveedores),'clave-ficticia-para-pruebas'),'La configuración pública oculta la clave.');
putenv('GROQ_API_KEY=');
esperarError(fn()=>(new ClienteGroq())->validarConfiguracion(),'groq_configuracion');
echo "Correcto: {$comprobaciones} comprobaciones de Groq con transporte simulado; no se usó una clave real.\n";
