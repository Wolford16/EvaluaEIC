<?php
declare(strict_types=1);

namespace EvaluaEIC\Controladores;

use EvaluaEIC\Servicios\{BaseDatos, ErrorAplicacion, Seguridad};
use PDOException;

final class Instalacion {
    private const MARCA = 'primer_administrador_creado';

    public static function completada(): bool {
        return (bool) BaseDatos::ejecutar(
            "SELECT 1 FROM configuraciones WHERE nombre = ?
             UNION ALL SELECT 1 FROM usuarios LIMIT 1",
            [self::MARCA]
        )->fetchColumn();
    }

    public static function crearAdministrador(array $datos, bool $soloBaseVacia = false): void {
        BaseDatos::transaccion(static function () use ($datos, $soloBaseVacia): void {
            // La clave primaria reserva la instalación antes de insertar al usuario.
            try {
                BaseDatos::ejecutar('INSERT INTO configuraciones (nombre, valor) VALUES (?, ?)', [self::MARCA, '1']);
            } catch (PDOException $error) {
                if ((string) $error->getCode() !== '23000') throw $error;
                throw new ErrorAplicacion('Ya existe una instalación inicial registrada.', 404);
            }
            $filtro = $soloBaseVacia ? '' : " WHERE rol = 'admin'";
            if (BaseDatos::ejecutar('SELECT 1 FROM usuarios' . $filtro . ' LIMIT 1')->fetchColumn()) {
                throw new ErrorAplicacion('Ya existe una cuenta y la instalación inicial no está disponible.', 404);
            }
            $datos['rol'] = 'admin';
            Administracion::crearUsuario($datos);
        });
    }

    public static function prepararIngreso(): array {
        try {
            $completada = self::completada();
        } catch (PDOException) {
            return [false, 'Comprueba la conexión con la base de datos.'];
        }
        if ($completada) {
            $mensaje = $_SESSION['instalacion_completada'] ?? '';
            unset($_SESSION['instalacion_completada']);
            return [false, $mensaje];
        }
        try {
            $clave = self::clave();
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') return [true, ''];
            self::validarClave($clave);
            self::crearAdministrador(['nombre' => 'Administrador inicial'] + $_POST, true);
        } catch (ErrorAplicacion $error) {
            http_response_code($error->estado);
            return [$error->estado !== 404, $error->getMessage()];
        } catch (PDOException) {
            http_response_code(503);
            return [true, 'No se pudo guardar el administrador. Revisa los permisos de escritura de la base.'];
        }
        $_SESSION['instalacion_completada'] = 'Administrador creado. Ya puedes iniciar sesión.';
        header('Location: ' . ruta_app('/ingreso'), true, 303);
        exit;
    }

    public static function mostrarDiagnostico(): void {
        $mensaje = '';
        $correcto = false;
        try {
            $clave = self::clave();
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                self::validarClave($clave);
                $mensaje = require RAIZ . '/herramientas/verificar_bd.php';
                $correcto = true;
            }
        } catch (ErrorAplicacion $error) {
            http_response_code($error->estado);
            $mensaje = $error->getMessage();
        }
        $csrf = $_SESSION['csrf'];
        session_write_close();
        require RAIZ . '/vistas/instalacion.php';
    }

    private static function validarClave(string $clave): void {
        $csrf = $_POST['csrf'] ?? '';
        Seguridad::csrf(is_string($csrf) ? $csrf : '');
        $enviada = $_POST['clave_instalacion'] ?? '';
        if (!is_string($enviada) || !hash_equals($clave, $enviada)) {
            throw new ErrorAplicacion('El código de instalación es incorrecto.', 403);
        }
    }

    private static function clave(): string {
        $configurada = entorno('CLAVE_INSTALACION');
        if (strlen($configurada) >= 24) return $configurada;

        $ruta = RAIZ . '/almacenamiento/clave_instalacion.txt';
        $archivo = @fopen($ruta, 'c+b');
        $error = new ErrorAplicacion('No se pudo preparar el código de instalación. Revisa los permisos de almacenamiento.', 503);
        if (!$archivo) throw $error;
        try {
            if (!flock($archivo, LOCK_EX)) throw $error;
            $clave = stream_get_contents($archivo, 33);
            if (is_string($clave) && preg_match('/\A[a-f0-9]{32}\z/', $clave)) return $clave;
            $clave = bin2hex(random_bytes(16));
            if (!@chmod($ruta, 0600) || !ftruncate($archivo, 0) || !rewind($archivo)
                || fwrite($archivo, $clave) !== strlen($clave) || !fflush($archivo)) {
                throw $error;
            }
            return $clave;
        } finally {
            fclose($archivo);
        }
    }
}
