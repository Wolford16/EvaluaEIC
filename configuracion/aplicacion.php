<?php
declare(strict_types=1);

define('RAIZ', dirname(__DIR__));
if (is_file(RAIZ . '/.env')) {
    foreach (file(RAIZ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) continue;
        [$nombre, $valor] = explode('=', $linea, 2);
        if (getenv(trim($nombre)) === false) putenv(trim($nombre) . '=' . trim($valor, " \t\n\r\0\x0B\"'"));
    }
}
date_default_timezone_set('America/Tegucigalpa');
if (is_file(RAIZ . '/terceros/autoload.php')) require RAIZ . '/terceros/autoload.php';
spl_autoload_register(function (string $clase): void {
    $prefijo = 'EvaluaIA\\';
    if (str_starts_with($clase, $prefijo)) {
        $ruta = RAIZ . '/aplicacion/' . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';
        if (is_file($ruta)) require $ruta;
    }
});
function entorno(string $nombre, string $defecto = ''): string {
    $valor = getenv($nombre);
    return $valor === false ? $defecto : $valor;
}
function escapar(mixed $valor): string { return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_seguro(mixed $valor): string { return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); }
