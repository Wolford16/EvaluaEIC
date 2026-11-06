<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;
final class Seguridad {
    public static function usuario(bool $obligatorio = true): ?array {
        $id = $_SESSION['usuario_id'] ?? null;
        $usuario = $id ? BaseDatos::ejecutar('SELECT id,nombre,usuario,rol,estado FROM usuarios WHERE id = ?', [$id])->fetch() : false;
        if (!$usuario || $usuario['estado'] !== 'activo') {
            if ($obligatorio) throw new ErrorAplicacion('Inicia sesión para continuar.', 401);
            return null;
        }
        return $usuario;
    }
    public static function administrador(): array {
        $usuario = self::usuario();
        if ($usuario['rol'] !== 'admin') throw new ErrorAplicacion('Esta opción requiere una cuenta de administrador.', 403);
        return $usuario;
    }
    public static function nombreUsuario(mixed $valor): string {
        $nombre = mb_strtolower(self::texto($valor, 3, 40, 'Usuario'));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/D', $nombre)) {
            throw new ErrorAplicacion('El usuario admite letras sin tildes, números, puntos, guiones y guiones bajos.');
        }
        return $nombre;
    }
    public static function csrf(): void {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) throw new ErrorAplicacion('La sesión cambió. Recarga la página e inténtalo otra vez.', 403);
    }
    public static function texto(mixed $valor, int $minimo, int $maximo, string $campo): string {
        if (!is_string($valor)) throw new ErrorAplicacion("Revisa el campo {$campo}.");
        $valor = trim($valor);
        if (mb_strlen($valor) < $minimo || mb_strlen($valor) > $maximo) throw new ErrorAplicacion("{$campo}: usa entre {$minimo} y {$maximo} caracteres.");
        return $valor;
    }
    public static function entero(mixed $valor, int $minimo, int $maximo, string $campo): int {
        $numero = filter_var($valor, FILTER_VALIDATE_INT);
        if ($numero === false || $numero < $minimo || $numero > $maximo) throw new ErrorAplicacion("Revisa {$campo}.");
        return $numero;
    }
    public static function documento(int $id, array $usuario): array {
        $documento = BaseDatos::ejecutar('SELECT * FROM documentos WHERE id = ?', [$id])->fetch();
        if (!$documento) throw new ErrorAplicacion('El documento ya no está disponible.', 404);
        if ($usuario['rol'] !== 'admin' && (int)$documento['subido_por_id'] !== (int)$usuario['id']) throw new ErrorAplicacion('No tienes acceso a este documento.', 403);
        return $documento;
    }
}
