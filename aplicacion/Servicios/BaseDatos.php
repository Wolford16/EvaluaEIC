<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;
use PDO;

final class BaseDatos {
    private static ?PDO $conexion = null;
    public static function conexion(): PDO {
        if (self::$conexion) return self::$conexion;
        $dsn = 'mysql:host=' . entorno('BD_HOST', 'localhost')
            . ';port=' . entorno('BD_PUERTO', '3306')
            . ';dbname=' . entorno('BD_NOMBRE', 'evaluaeic') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, entorno('BD_USUARIO'), entorno('BD_CLAVE'));
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
        return self::ejecutar('SELECT * FROM salas_evaluacion WHERE codigo = ? FOR UPDATE', [$codigo])->fetch() ?: null;
    }
}
