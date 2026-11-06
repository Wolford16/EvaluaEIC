<?php
declare(strict_types=1);

use EvaluaEIC\Servicios\{BaseDatos, ErrorAplicacion};

if (!defined('RAIZ')) {
    http_response_code(404);
    exit;
}

try {
    $pdo = BaseDatos::conexion();
    $pdo->query('SELECT 1')->fetchColumn();
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $base = $mysql ? (string) $pdo->query('SELECT DATABASE()')->fetchColumn() : '';
} catch (PDOException) {
    throw new ErrorAplicacion('PHP no pudo conectar con la base. Revisa el motor, el host, el puerto, el nombre y las credenciales en configuracion/servidor.php.', 503);
}

try {
    $pdo->query('SELECT usuario, clave_hash, rol, estado FROM usuarios WHERE 1 = 0');
    $tablas = ['documentos', 'preguntas', 'salas_evaluacion', 'participantes', 'respuestas_participantes', 'configuraciones', 'borradores', 'intentos_acceso'];
    foreach ($tablas as $tabla) {
        $pdo->query("SELECT 1 FROM {$tabla} WHERE 1 = 0");
    }
} catch (PDOException) {
    throw new ErrorAplicacion('PHP se conectó, pero el esquema de EvaluaEIC está incompleto o el usuario carece de permiso de lectura. Revisa la base asignada y la importación del SQL.', 503);
}

$motor = $mysql ? "MySQL/MariaDB, base {$base}" : 'SQLite';
return "Conexión correcta con {$motor}. Las tablas de EvaluaEIC se pueden leer.";
