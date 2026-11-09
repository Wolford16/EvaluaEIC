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

    public static function crearAdministrador(array $datos, bool $soloBaseVacia = false): int {
        return BaseDatos::transaccion(static function () use ($datos, $soloBaseVacia): int {
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
            return (int) BaseDatos::conexion()->lastInsertId();
        });
    }

    public static function prepararIngreso(): array {
        try {
            $completada = self::completada();
        } catch (PDOException $error) {
            error_log('EvaluaEIC no pudo consultar la instalación. SQLSTATE ' . $error->getCode());
            return self::falloIngreso(new ErrorAplicacion('Comprueba la conexión con la base de datos.', 503), false);
        }
        if ($completada) {
            unset($_SESSION['primer_acceso']);
            if (self::solicitaJson()) {
                self::responderJson(['ok' => false, 'mensaje' => 'La instalación inicial ya está cerrada. Abre el inicio de sesión.'], 409);
            }
            $mensaje = $_SESSION['instalacion_completada'] ?? '';
            unset($_SESSION['instalacion_completada']);
            return [false, $mensaje];
        }
        return [true, ''];
    }

    public static function prepararPrimerAcceso(): array {
        [$disponible, $mensaje] = self::prepararIngreso();
        if (!$disponible) return [false, $mensaje ?: 'El primer acceso ya está cerrado. Inicia sesión con tu cuenta.'];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return [true, ''];

        try {
            $accion = $_POST['accion'] ?? '';
            if ($accion === 'entrar') {
                self::validarClave(self::clave());
                session_regenerate_id(true);
                $_SESSION['primer_acceso'] = true;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                if (self::solicitaJson()) self::responderJson(['ok' => true, 'destino' => ruta_app('/crear_administrador.php')], 200);
                header('Location: ' . ruta_app('/crear_administrador.php'), true, 303);
                exit;
            }
            $csrf = $_POST['csrf'] ?? '';
            Seguridad::csrf(is_string($csrf) ? $csrf : '');
            if ($accion !== 'guardar' || empty($_SESSION['primer_acceso'])) {
                throw new ErrorAplicacion('Pulsa Primer acceso para crear el administrador.', 403);
            }
            $id = self::crearAdministrador($_POST, true);
        } catch (ErrorAplicacion $error) {
            return self::falloIngreso($error, $error->estado !== 404);
        } catch (PDOException $error) {
            error_log('EvaluaEIC no pudo crear el administrador inicial. SQLSTATE ' . $error->getCode() . ', código ' . ($error->errorInfo[1] ?? 'sin código'));
            return self::falloIngreso(new ErrorAplicacion('No se pudo guardar el administrador. Revisa los permisos de escritura de la base.', 503), true);
        }
        $usuario = BaseDatos::ejecutar('SELECT id,rol,clave_hash FROM usuarios WHERE id = ?', [$id])->fetch();
        $resultado = Autenticacion::abrirSesion($usuario);
        if (self::solicitaJson()) self::responderJson(['ok' => true] + $resultado, 201);
        header('Location: ' . $resultado['destino'], true, 303);
        exit;
    }

    private static function solicitaJson(): bool {
        return $_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json';
    }

    private static function responderJson(array $datos, int $estado): void {
        http_response_code($estado);
        header('Content-Type: application/json; charset=utf-8');
        echo json_seguro($datos);
        exit;
    }

    private static function falloIngreso(ErrorAplicacion $error, bool $disponible): array {
        if (self::solicitaJson()) self::responderJson(['ok' => false, 'mensaje' => $error->getMessage()], $error->estado);
        http_response_code($error->estado);
        return [$disponible, $error->getMessage()];
    }

    public static function mostrarDiagnostico(): void {
        $mensaje = '';
        $correcto = false;
        try {
            $clave = self::clave();
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                self::validarClave($clave);
                $mensaje = require RAIZ . '/herramientas/verificar_bd.php';
                if (self::solicitaJson()) self::responderJson(['ok' => true, 'mensaje' => $mensaje], 200);
                $correcto = true;
            }
        } catch (ErrorAplicacion $error) {
            if (self::solicitaJson()) self::responderJson(['ok' => false, 'mensaje' => $error->getMessage()], $error->estado);
            http_response_code($error->estado);
            $mensaje = $error->getMessage();
        }
        $csrf = $_SESSION['csrf'];
        session_write_close();
        require RAIZ . '/vistas/instalacion.php';
    }

    private static function validarClave(string $clave): void {
        if (!$_POST) throw new ErrorAplicacion('El servidor recibió el formulario vacío. Revisa la configuración de envío en el alojamiento.', 400);
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
