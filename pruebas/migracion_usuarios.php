<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$raiz = dirname(__DIR__);
$directorio = $raiz . '/almacenamiento/pruebas-migracion-' . bin2hex(random_bytes(5));
mkdir($directorio, 0700, true);
putenv('BD_MOTOR=sqlite');
putenv('BD_ARCHIVO=' . $directorio . '/datos.sqlite');
require $raiz . '/configuracion/aplicacion.php';
use EvaluaIA\Servicios\{BaseDatos, Migraciones, DatosIniciales};
$comprobaciones = 0;
function comprobar(bool $condicion, string $mensaje): void {
    global $comprobaciones;
    if (!$condicion) throw new RuntimeException($mensaje);
    $comprobaciones++;
}
try {
    $esquema = str_replace('usuario VARCHAR(40)', 'correo VARCHAR(150)', file_get_contents(RAIZ . '/base_datos/esquema_sqlite.sql'));
    BaseDatos::conexion()->exec($esquema);
    $hash = password_hash('ClaveAnterior2026!', PASSWORD_DEFAULT);
    foreach (['admin@institucion.edu', 'docente@institucion.edu', 'docente@otra.edu'] as $indice => $correo) {
        BaseDatos::ejecutar('INSERT INTO usuarios (nombre,correo,clave_hash,rol,creado_en) VALUES (?,?,?,?,?)', ['Cuenta ' . $indice, $correo, $hash, $indice === 0 ? 'admin' : 'docente', '2026-11-05']);
    }
    BaseDatos::ejecutar('INSERT INTO documentos (titulo,nombre_archivo,ruta_archivo,tamano,paginas,subido_por_id,creado_en) VALUES (?,?,?,?,?,?,?)', ['Material anterior','ejemplo.pdf','ejemplo.pdf',1000,1,2,'2026-11-05']);
    $pregunta = DatosIniciales::preguntas()[0];
    $copia = json_encode([$pregunta], JSON_UNESCAPED_UNICODE);
    BaseDatos::ejecutar('INSERT INTO preguntas (documento_id,enunciado,opciones_json,correcta,explicacion) VALUES (?,?,?,?,?)', [1,$pregunta['enunciado'],json_encode($pregunta['opciones']),0,$pregunta['explicacion']]);
    BaseDatos::ejecutar('INSERT INTO salas_evaluacion (documento_id,titulo,docente,codigo,creado_por_id,tiempo,preguntas_json,creado_en) VALUES (?,?,?,?,?,?,?,?)', [1,'Actividad anterior','Cuenta 1','EVAL-ANTER',2,20,$copia,'2026-11-05']);
    BaseDatos::ejecutar('INSERT INTO participantes (sala_id,nombre,avatar,token_hash,visto_en,creado_en) VALUES (?,?,?,?,?,?)', [1,'Participante anterior',0,hash('sha256','prueba'),100,'2026-11-05']);
    BaseDatos::ejecutar('INSERT INTO respuestas_participantes (participante_id,indice,opcion,correcta,tiempo,puntos) VALUES (1,0,0,1,5,875)');

    Migraciones::actualizarUsuarios();
    $columnas = array_column(BaseDatos::conexion()->query('PRAGMA table_info(usuarios)')->fetchAll(), 'name');
    comprobar(in_array('usuario', $columnas, true) && !in_array('correo', $columnas, true), 'La columna correo se reemplaza por usuario.');
    $usuarios = BaseDatos::ejecutar('SELECT * FROM usuarios ORDER BY id')->fetchAll();
    comprobar(count($usuarios) === 3, 'Se conservan las tres cuentas.');
    comprobar(array_column($usuarios, 'id') === [1,2,3], 'Se conservan los identificadores.');
    comprobar(array_column($usuarios, 'usuario') === ['admin','docente','docente.1'], 'Los usuarios son válidos y únicos.');
    comprobar(count(array_unique(array_column($usuarios, 'clave_hash'))) === 1 && $usuarios[0]['clave_hash'] === $hash, 'Se conservan los hashes existentes.');
    comprobar(password_verify('ClaveAnterior2026!', $usuarios[1]['clave_hash']), 'La contraseña anterior sigue siendo válida.');
    comprobar((int)BaseDatos::ejecutar('SELECT subido_por_id FROM documentos WHERE id=1')->fetchColumn() === 2, 'Se conserva el propietario del material.');
    comprobar((int)BaseDatos::ejecutar('SELECT COUNT(*) FROM preguntas')->fetchColumn() === 1, 'Se conservan las preguntas.');
    comprobar(BaseDatos::ejecutar('SELECT preguntas_json FROM salas_evaluacion WHERE id=1')->fetchColumn() === $copia, 'Se conserva la copia de la evaluación.');
    comprobar((int)BaseDatos::ejecutar('SELECT COUNT(*) FROM participantes')->fetchColumn() === 1, 'Se conservan los participantes.');
    comprobar((int)BaseDatos::ejecutar('SELECT puntos FROM respuestas_participantes WHERE id=1')->fetchColumn() === 875, 'Se conservan las respuestas y puntos.');
    $respaldos = glob($directorio . '/respaldos/*.sqlite');
    comprobar(count($respaldos) === 1, 'Se crea un respaldo antes de migrar.');
    $respaldo = new PDO('sqlite:' . $respaldos[0]);
    comprobar($respaldo->query('SELECT correo FROM usuarios WHERE id=1')->fetchColumn() === 'admin@institucion.edu', 'El respaldo conserva los datos anteriores.');
    $respaldo = null;
    Migraciones::actualizarUsuarios();
    comprobar(count(glob($directorio . '/respaldos/*.sqlite')) === 1, 'La migración es idempotente.');
    echo "Correcto: {$comprobaciones} comprobaciones de migración sin pérdida de datos.\n";
} finally {
    BaseDatos::desconectar();
    $resuelto = realpath($directorio);
    $almacenamiento = realpath(RAIZ . '/almacenamiento');
    // Solo elimina la carpeta temporal exacta creada para esta prueba.
    if ($resuelto && dirname($resuelto) === $almacenamiento && str_starts_with(basename($resuelto), 'pruebas-migracion-')) {
        $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resuelto, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($archivos as $archivo) $archivo->isDir() ? rmdir($archivo->getPathname()) : unlink($archivo->getPathname());
        rmdir($resuelto);
    }
}
