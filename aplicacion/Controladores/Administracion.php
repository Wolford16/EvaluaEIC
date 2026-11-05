<?php
declare(strict_types=1);
namespace EvaluaIA\Controladores;
use EvaluaIA\Servicios\{BaseDatos, Seguridad, ErrorAplicacion};
final class Administracion {
    public static function usuarios(): array { return ['usuarios'=>BaseDatos::ejecutar('SELECT id,nombre,usuario,rol,estado,creado_en FROM usuarios ORDER BY id')->fetchAll()]; }
    public static function crearUsuario(array $datos): array {
        $nombre=Seguridad::texto($datos['nombre']??'',2,120,'Nombre');
        $nombreUsuario=Seguridad::nombreUsuario($datos['usuario']??'');
        $clave=Seguridad::texto($datos['clave']??'',10,200,'Contraseña');
        if (strlen($clave)>72) throw new ErrorAplicacion('La contraseña es demasiado larga. Usa como máximo 72 bytes.');
        $rol=$datos['rol']??'docente';
        if (!in_array($rol,['docente','admin'],true)) throw new ErrorAplicacion('Rol inválido.');
        if (BaseDatos::ejecutar('SELECT id FROM usuarios WHERE usuario=?',[$nombreUsuario])->fetchColumn()) throw new ErrorAplicacion('Este usuario ya existe.',409);
        BaseDatos::ejecutar('INSERT INTO usuarios (nombre,usuario,clave_hash,rol,creado_en) VALUES (?,?,?,?,?)',[$nombre,$nombreUsuario,password_hash($clave,PASSWORD_DEFAULT),$rol,date('Y-m-d H:i:s')]);
        return ['mensaje'=>'Usuario creado.'];
    }
    public static function cambiarEstado(array $datos,array $usuario): array {
        $id=Seguridad::entero($datos['id']??null,1,PHP_INT_MAX,'usuario');
        if ($id===(int)$usuario['id']) throw new ErrorAplicacion('Tu propia cuenta debe permanecer activa.');
        $destino=BaseDatos::ejecutar('SELECT * FROM usuarios WHERE id=?',[$id])->fetch();
        if (!$destino) throw new ErrorAplicacion('Usuario no encontrado.',404);
        BaseDatos::ejecutar('UPDATE usuarios SET estado=? WHERE id=?',[$destino['estado']==='activo'?'inactivo':'activo',$id]);
        return ['mensaje'=>'Estado del usuario actualizado.'];
    }
}
