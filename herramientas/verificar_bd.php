<?php
declare(strict_types=1);

use EvaluaEIC\Servicios\{BaseDatos, ErrorAplicacion};

if (!defined('RAIZ')) {
    http_response_code(404);
    exit;
}

if (!extension_loaded('pdo_mysql')) {
    throw new ErrorAplicacion('Activa la extensión pdo_mysql en la versión de PHP que usa este sitio.', 503);
}

try {
    $pdo = BaseDatos::conexion();
    $pdo->query('SELECT 1')->fetchColumn();
    $base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (PDOException $error) {
    $codigo = (int) ($error->errorInfo[1] ?? 0);
    error_log('EvaluaEIC no pudo conectar a MySQL. SQLSTATE ' . $error->getCode() . ', código ' . $codigo);
    $detalle = match ($codigo) {
        1044 => 'Comprueba que el usuario de MySQL esté asignado a la base en cPanel.',
        1045 => 'Comprueba el usuario y la contraseña de MySQL.',
        1049 => 'Comprueba el nombre completo de la base asignada.',
        2002, 2003 => 'Comprueba el host y el puerto de MySQL con el alojamiento.',
        default => 'Comprueba la configuración de MySQL con el alojamiento.',
    };
    $referencia = $codigo ? " (código {$codigo})" : '';
    throw new ErrorAplicacion("PHP no pudo conectar a MySQL{$referencia}. {$detalle}", 503);
}

try {
    $pdo->query('SELECT id, nombre, usuario, clave_hash, rol, estado, creado_en FROM usuarios WHERE 1 = 0');
    $pdo->query('SELECT nombre, valor FROM configuraciones WHERE 1 = 0');
    $pdo->query('SELECT curso_id, compartido, token_manual FROM documentos WHERE 1 = 0');
    $pdo->query('SELECT id, nombre FROM cursos WHERE 1 = 0');
    $pdo->query('SELECT tipo, respuestas_aceptadas_json FROM preguntas WHERE 1 = 0');
    $pdo->query('SELECT texto FROM respuestas_participantes WHERE 1 = 0');
    $pdo->query('SELECT es_entrada, entrada_id FROM salas_evaluacion WHERE 1 = 0');
    $pdo->query('SELECT codigo_seguimiento_hash, participante_entrada_id FROM participantes WHERE 1 = 0');
    $tablas = ['borradores', 'intentos_acceso'];
    foreach ($tablas as $tabla) {
        $pdo->query("SELECT 1 FROM {$tabla} WHERE 1 = 0");
    }
} catch (PDOException) {
    throw new ErrorAplicacion('PHP se conectó, pero el esquema de EvaluaEIC está incompleto o el usuario carece de permiso de lectura. Revisa la base asignada y la importación del SQL.', 503);
}

$motor = "MySQL/MariaDB, base {$base}";
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
