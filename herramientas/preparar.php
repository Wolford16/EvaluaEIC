<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/configuracion/aplicacion.php';
use EvaluaEIC\Servicios\{BaseDatos, Migraciones};
foreach(['pdo_mysql','mbstring','fileinfo','curl'] as $extension) if(!extension_loaded($extension)) {fwrite(STDERR,"Activa la extensión PHP: {$extension}\n");exit(1);}
try {
    BaseDatos::conexion()->exec(file_get_contents(RAIZ . '/database/instalar_evaluaeic.sql'));
    Migraciones::actualizarUsuarios();
    if(!BaseDatos::ejecutar("SELECT nombre FROM configuraciones WHERE nombre='motor_ia'")->fetchColumn()) BaseDatos::ejecutar('INSERT INTO configuraciones (nombre,valor) VALUES (?,?)',['motor_ia','openrouter']);
    else BaseDatos::ejecutar("UPDATE configuraciones SET valor='openrouter' WHERE nombre='motor_ia' AND valor<>'openrouter'");
    // Retira únicamente los borradores vencidos y sus propios archivos.
    $vencidos=BaseDatos::ejecutar('SELECT token,ruta_archivo FROM borradores WHERE creado_en<?',[microtime(true)-86400])->fetchAll();
    foreach($vencidos as $b){$ruta=RAIZ.'/almacenamiento/documentos/'.basename($b['ruta_archivo']);if(is_file($ruta))unlink($ruta);BaseDatos::ejecutar('DELETE FROM borradores WHERE token=?',[$b['token']]);}
    echo "EvaluaEIC preparada. Base de datos: MySQL/MariaDB.\n";
    if (!BaseDatos::ejecutar("SELECT id FROM usuarios WHERE rol='admin' LIMIT 1")->fetchColumn()) {
        echo "Abre el sistema y pulsa Primer acceso de administrador.\n";
    }
}catch(Throwable $error){fwrite(STDERR,"No se pudo preparar la base de datos: ".$error->getMessage()."\n");exit(1);}
