<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/configuracion/aplicacion.php';

use EvaluaEIC\Controladores\Administracion;
use EvaluaEIC\Servicios\{BaseDatos, ErrorAplicacion};

try {
    if (BaseDatos::ejecutar("SELECT id FROM usuarios WHERE rol='admin' LIMIT 1")->fetchColumn()) {
        throw new ErrorAplicacion('Ya existe una cuenta de administrador.');
    }

    $usuario = 'admin-inicial';
    $clave = bin2hex(random_bytes(18));
    Administracion::crearUsuario([
        'nombre' => 'Administrador inicial',
        'usuario' => $usuario,
        'clave' => $clave,
        'rol' => 'admin',
    ]);

    echo "Usuario: {$usuario}\nContraseña: {$clave}\n";
    echo "Crea tu cuenta definitiva desde Administración → Usuarios y desactiva esta cuenta desde la nueva.\n";
} catch (ErrorAplicacion $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, "No se pudo crear el administrador. Comprueba la configuración y las tablas de la base de datos.\n");
    exit(1);
}
