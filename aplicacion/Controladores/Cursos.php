<?php
declare(strict_types=1);

namespace EvaluaEIC\Controladores;

use EvaluaEIC\Servicios\{BaseDatos, ErrorAplicacion, Seguridad};

final class Cursos {
    public static function listar(): array {
        return ['cursos' => BaseDatos::ejecutar('SELECT id, nombre FROM cursos ORDER BY nombre')->fetchAll()];
    }

    public static function crear(array $datos): array {
        $nombre = Seguridad::texto($datos['nombre'] ?? '', 2, 120, 'Nombre del curso');
        $nombre = preg_replace('/\s+/u', ' ', $nombre);
        BaseDatos::ejecutar(
            'INSERT INTO cursos (nombre) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [$nombre]
        );
        $id = (int) BaseDatos::conexion()->lastInsertId();
        return ['curso' => self::buscar($id)];
    }

    public static function buscar(int $id): array {
        $curso = BaseDatos::ejecutar('SELECT id, nombre FROM cursos WHERE id = ?', [$id])->fetch();
        if (!$curso) throw new ErrorAplicacion('Selecciona un curso disponible.', 422);
        return $curso;
    }
}
