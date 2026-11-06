<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/configuracion/aplicacion.php';
use EvaluaEIC\Servicios\{InteligenciaArtificial, ErrorAplicacion};

try {
    if (!extension_loaded('curl')) throw new RuntimeException('Activa la extensión cURL de PHP.');
    if (in_array('--solo-https', $argv, true)) {
        // Esta comprobación no utiliza ninguna clave ni envía documentos.
        $curl = curl_init('https://openrouter.ai/api/v1/models');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false]);
        curl_exec($curl);
        $error = curl_errno($curl); $estado = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($error) throw new RuntimeException('No se pudo completar HTTPS con OpenRouter. Código cURL: ' . $error . '. Revisa red y certificados.');
        if (!in_array($estado, [200,401], true)) throw new RuntimeException('OpenRouter respondió con HTTP ' . $estado . '.');
        echo "cURL y HTTPS operativos. OpenRouter respondió HTTP {$estado}. No se utilizó una clave ni se generó contenido.\n";
        exit;
    }
    $resultado = InteligenciaArtificial::probar();
    echo $resultado['mensaje'] . "\n";
    echo 'Modelo: ' . $resultado['modelo'] . "\n";
    echo 'Formato: ' . $resultado['formato'] . "\n";
    echo 'Tiempo: ' . $resultado['milisegundos'] . " ms\n";
    echo 'Tokens reportados por OpenRouter: ' . $resultado['tokens'] . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    if ($error instanceof ErrorAplicacion && $error->reintentarEn) fwrite(STDERR, 'Reintentar después de ' . $error->reintentarEn . " segundos.\n");
    exit(1);
}
