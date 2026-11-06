<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;
use PDO;

final class BaseDatos {
    private static ?PDO $conexion = null;
    public static function conexion(): PDO {
        if (self::$conexion) return self::$conexion;
        if (entorno('BD_MOTOR', 'sqlite') === 'mysql') {
            $dsn = 'mysql:host=' . entorno('BD_HOST', '127.0.0.1') . ';port=' . entorno('BD_PUERTO', '3306') . ';dbname=' . entorno('BD_NOMBRE', 'evaluaeic') . ';charset=utf8mb4';
            $pdo = new PDO($dsn, entorno('BD_USUARIO', 'root'), entorno('BD_CLAVE'));
        } else {
            if (!is_dir(RAIZ . '/almacenamiento')) mkdir(RAIZ . '/almacenamiento', 0700, true);
            $rutaAnterior = RAIZ . '/almacenamiento/evaluaia.sqlite';
            $rutaLocal = is_file($rutaAnterior) ? $rutaAnterior : RAIZ . '/almacenamiento/evaluaeic.sqlite';
            $pdo = new PDO('sqlite:' . entorno('BD_ARCHIVO', $rutaLocal));
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return self::$conexion = $pdo;
    }
    public static function ejecutar(string $sql, array $parametros = []): \PDOStatement {
        $consulta = self::conexion()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta;
    }
    public static function desconectar(): void { self::$conexion = null; }
    public static function transaccion(callable $operacion): mixed {
        $pdo = self::conexion();
        $pdo->beginTransaction();
        try { $resultado = $operacion(); $pdo->commit(); return $resultado; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    public static function bloquearSala(string $codigo): ?array {
        $sufijo = entorno('BD_MOTOR', 'sqlite') === 'mysql' ? ' FOR UPDATE' : '';
        // En SQLite, el UPDATE adquiere el bloqueo antes de leer el reloj de la sala.
        if ($sufijo === '') self::ejecutar('UPDATE salas_evaluacion SET codigo = codigo WHERE codigo = ?', [$codigo]);
        return self::ejecutar('SELECT * FROM salas_evaluacion WHERE codigo = ?' . $sufijo, [$codigo])->fetch() ?: null;
    }
}
