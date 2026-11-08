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
    $pdo->query('SELECT id, nombre, usuario, clave_hash, rol, estado, creado_en FROM usuarios WHERE 1 = 0');
    $pdo->query('SELECT nombre, valor FROM configuraciones WHERE 1 = 0');
    $tablas = ['documentos', 'preguntas', 'salas_evaluacion', 'participantes', 'respuestas_participantes', 'borradores', 'intentos_acceso'];
    foreach ($tablas as $tabla) {
        $pdo->query("SELECT 1 FROM {$tabla} WHERE 1 = 0");
    }
} catch (PDOException) {
    throw new ErrorAplicacion('PHP se conectó, pero el esquema de EvaluaEIC está incompleto o el usuario carece de permiso de lectura. Revisa la base asignada y la importación del SQL.', 503);
}

$motor = $mysql ? "MySQL/MariaDB, base {$base}" : 'SQLite';
// Estas sentencias comprueban INSERT en las dos tablas del alta sin insertar filas.
$pruebas = [
    'configuraciones' => "INSERT INTO configuraciones (nombre, valor) SELECT 'diagnostico', '1' WHERE 1 = 0",
    'usuarios' => "INSERT INTO usuarios (nombre, usuario, clave_hash, rol, creado_en)
                   SELECT 'Diagnóstico', 'diagnostico', '', 'admin', '' WHERE 1 = 0",
];
foreach ($pruebas as $tabla => $sql) {
    try {
        $pdo->exec($sql);
    } catch (PDOException $error) {
        error_log("EvaluaEIC no pudo comprobar la escritura en {$tabla}. SQLSTATE " . $error->getCode() . ', código ' . ($error->errorInfo[1] ?? 'sin código'));
        throw new ErrorAplicacion("Conexión correcta con {$motor}. Falló la comprobación de escritura en {$tabla}. Revisa el permiso INSERT y la estructura de esa tabla.", 503);
    }
}
return "Conexión correcta con {$motor}. Las tablas de EvaluaEIC se pueden leer y se comprobó el permiso de escritura necesario para crear el primer administrador.";
