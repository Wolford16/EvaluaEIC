<?php
declare(strict_types=1);
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
// Solo se sirven archivos estáticos desde la carpeta pública.
$archivo = realpath(__DIR__ . '/publico' . rawurldecode($ruta));
$publico = realpath(__DIR__ . '/publico') . DIRECTORY_SEPARATOR;
if ($ruta !== '/' && $archivo && str_starts_with($archivo, $publico) && is_file($archivo)) return false;
require __DIR__ . '/publico/index.php';
