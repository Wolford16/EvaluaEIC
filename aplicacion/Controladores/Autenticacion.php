<?php
declare(strict_types=1);
namespace EvaluaEIC\Controladores;
use EvaluaEIC\Servicios\{BaseDatos, Seguridad, ErrorAplicacion};
final class Autenticacion {
    public static function ingresar(array $datos): array {
        $nombreUsuario = Seguridad::nombreUsuario($datos['usuario'] ?? '');
        $clave = Seguridad::texto($datos['clave'] ?? '', 1, 200, 'Contraseña');
        $identificador = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'local'));
        $usuario = BaseDatos::ejecutar('SELECT * FROM usuarios WHERE usuario = ?', [$nombreUsuario])->fetch();
        $hash = ($usuario['estado'] ?? null) === 'activo' ? $usuario['clave_hash'] : null;
        if (!self::verificarClave($clave, $hash, $identificador)) {
            throw new ErrorAplicacion('Usuario o contraseña incorrectos, o cuenta inactiva.', 401);
        }
        return self::abrirSesion($usuario);
    }

    public static function abrirSesion(array $usuario): array {
        unset($_SESSION['primer_acceso']);
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int)$usuario['id'];
        $_SESSION['credencial'] = hash('sha256', $usuario['clave_hash']);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return ['destino' => ruta_app($usuario['rol'] === 'admin' ? '/admin' : '/docente')];
    }

    public static function confirmarClave(mixed $valor, string $hash, int $id): void {
        $clave = Seguridad::texto($valor, 1, 200, 'Contraseña actual');
        if (!self::verificarClave($clave, $hash, hash('sha256', 'confirmar-cuenta:' . $id))) {
            throw new ErrorAplicacion('La contraseña actual es incorrecta.');
        }
    }

    private static function verificarClave(string $clave, ?string $hash, string $identificador): bool {
        $intento = BaseDatos::ejecutar('SELECT * FROM intentos_acceso WHERE identificador = ?', [$identificador])->fetch();
        $ahora = microtime(true);
        $cantidad = $intento && $ahora - $intento['ultimo'] < 300 ? (int)$intento['intentos'] : 0;
        if ($cantidad >= 8) throw new ErrorAplicacion('Demasiados intentos. Espera cinco minutos.', 429);
        $valida = password_verify($clave, $hash ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if ($valida && $hash !== null) {
            BaseDatos::ejecutar('DELETE FROM intentos_acceso WHERE identificador = ?', [$identificador]);
            return true;
        }
        if ($intento) BaseDatos::ejecutar('UPDATE intentos_acceso SET intentos=?, ultimo=? WHERE identificador=?', [$cantidad + 1,$ahora,$identificador]);
        else BaseDatos::ejecutar('INSERT INTO intentos_acceso VALUES (?,?,?)', [$identificador,1,$ahora]);
        return false;
    }
    public static function salir(): array {
        $_SESSION = [];
        if (!session_destroy()) throw new ErrorAplicacion('No se pudo cerrar la sesión. Vuelve a intentarlo.', 500);
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'],
        ]);
        return ['destino' => ruta_app('/ingreso')];
    }
}
