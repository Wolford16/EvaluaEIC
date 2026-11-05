<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/configuracion/aplicacion.php';
use EvaluaIA\Servicios\{BaseDatos, DatosIniciales, Migraciones};
foreach(['pdo','mbstring','fileinfo','curl'] as $extension) if(!extension_loaded($extension)) {fwrite(STDERR,"Activa la extensión PHP: {$extension}\n");exit(1);}
try {
    $motor=entorno('BD_MOTOR','sqlite');
    BaseDatos::conexion()->exec(file_get_contents(RAIZ.'/base_datos/esquema_'.($motor==='mysql'?'mysql':'sqlite').'.sql'));
    Migraciones::actualizarUsuarios();
    if(!BaseDatos::ejecutar("SELECT nombre FROM configuraciones WHERE nombre='motor_ia'")->fetchColumn()) BaseDatos::ejecutar('INSERT INTO configuraciones (nombre,valor) VALUES (?,?)',['motor_ia','groq']);
    else BaseDatos::ejecutar("UPDATE configuraciones SET valor='groq' WHERE nombre='motor_ia' AND valor<>'groq'");
    $nuevaInstalacion=(int)BaseDatos::ejecutar('SELECT COUNT(*) FROM usuarios')->fetchColumn()===0;
    if($nuevaInstalacion) {
        BaseDatos::transaccion(function(){
            foreach([['Administrador','admin','AdminLocal2026!','admin'],['Docente / Supervisor','docente','DocenteLocal2026!','docente']] as [$nombre,$nombreUsuario,$clave,$rol]) BaseDatos::ejecutar('INSERT INTO usuarios (nombre,usuario,clave_hash,rol,creado_en) VALUES (?,?,?,?,?)',[$nombre,$nombreUsuario,password_hash($clave,PASSWORD_DEFAULT),$rol,date('Y-m-d H:i:s')]);
        });
        if(!in_array('--sin-ejemplo',$argv,true)) {
            $directorio=RAIZ.'/almacenamiento/documentos';if(!is_dir($directorio))mkdir($directorio,0700,true);
            DatosIniciales::crearPdf($directorio.'/material-demostracion.pdf');
            BaseDatos::transaccion(function()use($directorio){
                $idDocente=(int)BaseDatos::ejecutar("SELECT id FROM usuarios WHERE usuario='docente'")->fetchColumn();
                BaseDatos::ejecutar('INSERT INTO documentos (titulo,nombre_archivo,ruta_archivo,tamano,paginas,subido_por_id,creado_en) VALUES (?,?,?,?,?,?,?)',['Manual de investigación y cadena de custodia','Material_Cadena_Custodia.pdf','material-demostracion.pdf',filesize($directorio.'/material-demostracion.pdf'),1,$idDocente,date('Y-m-d H:i:s')]);
                $id=(int)BaseDatos::conexion()->lastInsertId();
                foreach(DatosIniciales::preguntas() as $p)BaseDatos::ejecutar('INSERT INTO preguntas (documento_id,enunciado,opciones_json,correcta,explicacion) VALUES (?,?,?,?,?)',[$id,$p['enunciado'],json_encode($p['opciones'],JSON_UNESCAPED_UNICODE),$p['correcta'],$p['explicacion']]);
            });
        }
    }
    // Retira únicamente los borradores vencidos y sus propios archivos.
    $vencidos=BaseDatos::ejecutar('SELECT token,ruta_archivo FROM borradores WHERE creado_en<?',[microtime(true)-86400])->fetchAll();
    foreach($vencidos as $b){$ruta=RAIZ.'/almacenamiento/documentos/'.basename($b['ruta_archivo']);if(is_file($ruta))unlink($ruta);BaseDatos::ejecutar('DELETE FROM borradores WHERE token=?',[$b['token']]);}
    echo "EvaluaEIC preparada. Base de datos: {$motor}.\n";
    if($nuevaInstalacion)echo "Cuentas locales creadas. Consulta los accesos en LEEME.md.\n";
}catch(Throwable $error){fwrite(STDERR,"No se pudo preparar la base de datos: ".$error->getMessage()."\n");exit(1);}
