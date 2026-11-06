<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;

/** Actualiza las instalaciones existentes sin recrear cuentas ni evaluaciones. */
final class Migraciones {
    public static function actualizarUsuarios(): void {
        $pdo = BaseDatos::conexion();
        $esMysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $columnas = $pdo->query($esMysql ? 'SHOW COLUMNS FROM usuarios' : 'PRAGMA table_info(usuarios)')->fetchAll();
        $nombres = array_column($columnas, $esMysql ? 'Field' : 'name');
        if (!in_array('correo', $nombres, true) || in_array('usuario', $nombres, true)) return;

        if (!$esMysql) {
            $baseActual = $pdo->query('PRAGMA database_list')->fetchAll();
            $archivoBase = array_values(array_filter($baseActual, fn($base) => $base['name'] === 'main'))[0]['file'];
            $directorio = dirname($archivoBase) . '/respaldos';
            if (!is_dir($directorio)) mkdir($directorio, 0700, true);
            $respaldo = $directorio . '/antes-de-usuarios-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
            $pdo->exec('VACUUM INTO ' . $pdo->quote($respaldo));
        }

        $cuentas = $pdo->query('SELECT id,correo FROM usuarios ORDER BY id')->fetchAll();
        $nuevos = []; $ocupados = [];
        foreach ($cuentas as $cuenta) {
            $base = explode('@', mb_strtolower($cuenta['correo']), 2)[0];
            $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: 'usuario';
            $base = trim(preg_replace('/[^a-z0-9._-]+/', '.', strtolower($base)), '._-');
            if (strlen($base) < 3) $base = 'usuario' . $cuenta['id'];
            $base = substr($base, 0, 40);
            $nombre = $base; $numero = 1;
            while (isset($ocupados[$nombre])) {
                $sufijo = '.' . $numero++;
                $nombre = substr($base, 0, 40 - strlen($sufijo)) . $sufijo;
            }
            $ocupados[$nombre] = true; $nuevos[(int)$cuenta['id']] = $nombre;
        }

        BaseDatos::transaccion(function () use ($cuentas, $nuevos, $esMysql, $pdo): void {
            // Valores temporales únicos evitan colisiones durante la conversión.
            $prefijo = 'mig.' . bin2hex(random_bytes(6)) . '.';
            foreach ($cuentas as $cuenta) BaseDatos::ejecutar('UPDATE usuarios SET correo=? WHERE id=?', [$prefijo . $cuenta['id'], $cuenta['id']]);
            foreach ($nuevos as $id => $nombre) BaseDatos::ejecutar('UPDATE usuarios SET correo=? WHERE id=?', [$nombre, $id]);
            if (!$esMysql) $pdo->exec('ALTER TABLE usuarios RENAME COLUMN correo TO usuario');
        });
        // MySQL confirma sus cambios de estructura de forma implícita: se ejecuta fuera de la transacción.
        if ($esMysql) $pdo->exec('ALTER TABLE usuarios CHANGE correo usuario VARCHAR(40) NOT NULL');
    }
}
