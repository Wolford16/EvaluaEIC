<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;

/** Actualiza las instalaciones existentes sin recrear cuentas ni evaluaciones. */
final class Migraciones {
    public static function actualizarUsuarios(): void {
        $pdo = BaseDatos::conexion();
        $nombres = array_column($pdo->query('SHOW COLUMNS FROM usuarios')->fetchAll(), 'Field');
        if (!in_array('correo', $nombres, true) || in_array('usuario', $nombres, true)) return;

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

        BaseDatos::transaccion(function () use ($cuentas, $nuevos): void {
            // Valores temporales únicos evitan colisiones durante la conversión.
            $prefijo = 'mig.' . bin2hex(random_bytes(6)) . '.';
            foreach ($cuentas as $cuenta) BaseDatos::ejecutar('UPDATE usuarios SET correo=? WHERE id=?', [$prefijo . $cuenta['id'], $cuenta['id']]);
            foreach ($nuevos as $id => $nombre) BaseDatos::ejecutar('UPDATE usuarios SET correo=? WHERE id=?', [$nombre, $id]);
        });
        // MySQL confirma sus cambios de estructura de forma implícita: se ejecuta fuera de la transacción.
        $pdo->exec('ALTER TABLE usuarios CHANGE correo usuario VARCHAR(40) NOT NULL');
    }
}
