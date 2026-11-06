<?php
declare(strict_types=1);
namespace EvaluaEIC\Controladores;
use EvaluaEIC\Servicios\{BaseDatos, Seguridad, ErrorAplicacion};
use PDOException;
final class Administracion {
    public static function usuarios(): array {
        return ['usuarios'=>BaseDatos::ejecutar("SELECT id,nombre,usuario,rol,estado,creado_en FROM usuarios WHERE estado <> 'eliminado' ORDER BY id")->fetchAll()];
    }
    public static function crearUsuario(array $datos): array {
        $nombre=Seguridad::texto($datos['nombre']??'',2,120,'Nombre');
        $nombreUsuario=Seguridad::nombreUsuario($datos['usuario']??'');
        $clave=self::claveNueva($datos['clave']??'');
        $rol=$datos['rol']??'docente';
        if (!in_array($rol,['docente','admin'],true)) throw new ErrorAplicacion('Rol inválido.');
        self::guardarCredenciales('INSERT INTO usuarios (nombre,usuario,clave_hash,rol,creado_en) VALUES (?,?,?,?,?)',[$nombre,$nombreUsuario,password_hash($clave,PASSWORD_DEFAULT),$rol,date('Y-m-d H:i:s')]);
        return ['mensaje'=>'Usuario creado.'];
    }

    public static function actualizarCuenta(array $datos,array $usuario): array {
        $nombreUsuario=Seguridad::nombreUsuario($datos['usuario']??'');
        $nueva=$datos['clave_nueva']??'';
        $confirmacion=$datos['confirmar_clave']??'';
        if ($nueva!==$confirmacion) throw new ErrorAplicacion('Las contraseñas nuevas no coinciden.');
        $clave=$nueva==='' ? null : self::claveNueva($nueva);
        $cuenta=BaseDatos::ejecutar("SELECT usuario,clave_hash FROM usuarios WHERE id=? AND estado='activo'",[$usuario['id']])->fetch();
        if (!$cuenta) throw new ErrorAplicacion('Inicia sesión para continuar.',401);
        Autenticacion::confirmarClave($datos['clave_actual']??'', $cuenta['clave_hash'], (int)$usuario['id']);
        if ($clave===null && $nombreUsuario===$cuenta['usuario']) throw new ErrorAplicacion('Cambia el usuario o escribe una contraseña nueva.');
        $hash=$clave===null ? $cuenta['clave_hash'] : password_hash($clave,PASSWORD_DEFAULT);
        $filas=self::guardarCredenciales(
            "UPDATE usuarios SET usuario=?, clave_hash=? WHERE id=? AND estado='activo' AND clave_hash=? AND usuario=?",
            [$nombreUsuario,$hash,$usuario['id'],$cuenta['clave_hash'],$cuenta['usuario']]
        );
        if ($filas!==1) throw new ErrorAplicacion('La cuenta cambió durante la operación. Recarga la página e inténtalo otra vez.',409);
        $_SESSION['credencial']=hash('sha256',$hash);
        session_regenerate_id(true);
        return ['mensaje'=>'Tu cuenta se actualizó.','usuario'=>$nombreUsuario];
    }

    public static function gestionarUsuario(array $datos,array $usuario,bool $eliminar=false): array {
        $id=Seguridad::entero($datos['id']??null,1,PHP_INT_MAX,'usuario');
        $administrador=(int)$usuario['id'];
        if ($id===$administrador) throw new ErrorAplicacion('Tu propia cuenta debe permanecer activa.');

        return BaseDatos::transaccion(static function () use ($id,$administrador,$eliminar): array {
            $mysql=entorno('BD_MOTOR','sqlite')==='mysql';
            // Bloquear ambas cuentas antes de validarlas evita que dos administradores se quiten el acceso entre sí.
            if (!$mysql) BaseDatos::ejecutar('UPDATE usuarios SET estado=estado WHERE id IN (?,?)',[$id,$administrador]);
            $cuentas=BaseDatos::ejecutar('SELECT id,estado FROM usuarios WHERE id IN (?,?) ORDER BY id'.($mysql?' FOR UPDATE':''),[$id,$administrador])->fetchAll(\PDO::FETCH_KEY_PAIR);
            if (($cuentas[$administrador]??null)!=='activo') throw new ErrorAplicacion('Inicia sesión para continuar.',401);
            $estado=$cuentas[$id]??'eliminado';
            if ($estado==='eliminado') throw new ErrorAplicacion('Usuario no encontrado.',404);
            if ($eliminar) {
                // La fila conserva la autoría. El identificador interno no es un usuario válido de ingreso.
                BaseDatos::ejecutar("UPDATE usuarios SET estado='eliminado', usuario=?, clave_hash='' WHERE id=?",[':eliminado:'.$id,$id]);
                return ['mensaje'=>'Usuario eliminado.'];
            }
            BaseDatos::ejecutar('UPDATE usuarios SET estado=? WHERE id=?',[$estado==='activo'?'inactivo':'activo',$id]);
            return ['mensaje'=>'Estado del usuario actualizado.'];
        });
    }

    private static function claveNueva(mixed $valor): string {
        $clave=Seguridad::texto($valor,10,200,'Contraseña nueva');
        if (strlen($clave)>72 || str_contains($clave,"\0")) throw new ErrorAplicacion('Usa una contraseña válida de hasta 72 bytes.');
        return $clave;
    }

    private static function guardarCredenciales(string $sql,array $parametros): int {
        try {
            return BaseDatos::ejecutar($sql,$parametros)->rowCount();
        } catch (PDOException $error) {
            if ((string)$error->getCode()!=='23000') throw $error;
            throw new ErrorAplicacion('Este usuario ya existe.',409);
        }
    }
}
