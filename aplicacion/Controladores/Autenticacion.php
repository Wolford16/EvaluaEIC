<?php
declare(strict_types=1);
namespace EvaluaEIC\Controladores;
use EvaluaEIC\Servicios\{BaseDatos, Seguridad, ErrorAplicacion};
final class Autenticacion {
    public static function ingresar(array $datos): array {
        $nombreUsuario = Seguridad::nombreUsuario($datos['usuario'] ?? '');
        $clave = Seguridad::texto($datos['clave'] ?? '', 1, 200, 'Contraseña');
        $identificador = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'local'));
        $intento = BaseDatos::ejecutar('SELECT * FROM intentos_acceso WHERE identificador = ?', [$identificador])->fetch();
        if ($intento && $intento['intentos'] >= 8 && microtime(true) - $intento['ultimo'] < 300) throw new ErrorAplicacion('Demasiados intentos. Espera cinco minutos.', 429);
        $usuario = BaseDatos::ejecutar('SELECT * FROM usuarios WHERE usuario = ?', [$nombreUsuario])->fetch();
        $valida = password_verify($clave, $usuario['clave_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$usuario || !$valida || $usuario['estado'] !== 'activo') {
            $cantidad = $intento && microtime(true) - $intento['ultimo'] < 300 ? (int)$intento['intentos'] + 1 : 1;
            if ($intento) BaseDatos::ejecutar('UPDATE intentos_acceso SET intentos=?, ultimo=? WHERE identificador=?', [$cantidad,microtime(true),$identificador]);
            else BaseDatos::ejecutar('INSERT INTO intentos_acceso VALUES (?,?,?)', [$identificador,$cantidad,microtime(true)]);
            throw new ErrorAplicacion('Usuario o contraseña incorrectos, o cuenta inactiva.', 401);
        }
        BaseDatos::ejecutar('DELETE FROM intentos_acceso WHERE identificador = ?', [$identificador]);
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int)$usuario['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return ['destino' => ruta_app($usuario['rol'] === 'admin' ? '/admin' : '/docente')];
    }
    public static function salir(): array {
        $_SESSION = [];
        session_destroy();
        return ['destino' => ruta_app('/ingreso')];
    }
}
