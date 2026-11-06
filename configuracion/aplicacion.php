<?php
declare(strict_types=1);

define('RAIZ', dirname(__DIR__));
define('AJUSTES_LOCALES', is_file(__DIR__ . '/servidor.php') ? require __DIR__ . '/servidor.php' : []);
date_default_timezone_set('America/Tegucigalpa');
if (is_file(RAIZ . '/terceros/autoload.php')) require RAIZ . '/terceros/autoload.php';
spl_autoload_register(function (string $clase): void {
    $prefijo = 'EvaluaEIC\\';
    if (str_starts_with($clase, $prefijo)) {
        $ruta = RAIZ . '/aplicacion/' . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';
        if (is_file($ruta)) require $ruta;
    }
});
function entorno(string $nombre, string $defecto = ''): string {
    $valor = getenv($nombre);
    return $valor === false ? (AJUSTES_LOCALES[$nombre] ?? $defecto) : $valor;
}
function es_lista(array $valores): bool {
    $indice = 0;
    foreach ($valores as $clave => $valor) {
        if ($clave !== $indice++) return false;
    }
    return true;
}
function url_publica(): ?string {
    $url = trim(entorno('APLICACION_URL'));
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    $partes = parse_url($url);
    $ruta = $partes['path'] ?? '';
    if (!in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true)
        || !preg_match('#^(?:/[A-Za-z0-9._~-]+)*/?$#D', $ruta)
        || array_intersect(['.', '..'], explode('/', $ruta))
        || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($partes))) {
        return null;
    }
    return rtrim($url, '/');
}
function ruta_base(): string {
    return parse_url(url_publica() ?? '', PHP_URL_PATH) ?: '';
}
function ruta_app(string $ruta): string {
    return ruta_base() . $ruta;
}
function escapar(mixed $valor): string { return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_seguro(mixed $valor): string { return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); }
