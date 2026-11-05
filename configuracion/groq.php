<?php
declare(strict_types=1);

// La clave y el modelo se leen de .env o del entorno del servidor.
// Estos límites son conservadores y se pueden ajustar a la cuenta elegida.
return [
    'url' => 'https://api.groq.com/openai/v1',
    'presupuesto_tokens' => 6000,
    'preguntas_por_lote' => 3,
    'tokens_por_pregunta' => 650,
    // Incluye el razonamiento del modelo y el JSON del resumen, no solo su texto visible.
    'tokens_resumen' => 1024,
    'bytes_resumen' => 800,
    'max_solicitudes' => 48,
    'tiempo_total' => 150,
    'tiempo_solicitud' => 40,
];
