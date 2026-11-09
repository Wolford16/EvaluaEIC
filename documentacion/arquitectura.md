# Arquitectura de EvaluaEIC

La descripción y la demo originales son referencias del producto. Esta implementación conserva sus flujos principales y adapta los detalles técnicos para un entorno PHP local. No incorpora React, TSX, Vite ni una compilación del frontend.

## Flujo de una petición

Apache usa `.htaccess` para enviar las rutas de la aplicación al `index.php` de la raíz y limitar los archivos que sirve. El punto de entrada carga `publico/index.php`, que recibe la ruta, abre la sesión y aplica los encabezados HTTP. Las páginas cargan plantillas de `vistas`. Las rutas `/api/...` reciben o entregan JSON y delegan en los controladores. Los servicios concentran la conexión PDO, validaciones, extracción de PDF, solicitudes de IA y reglas de evaluación.

Las consultas usan parámetros preparados. Las mutaciones exigen un token CSRF. El ingreso institucional utiliza usuario y contraseña, con nombres de usuario únicos y normalizados en minúsculas. Los administradores pueden gestionar todos los materiales; los docentes acceden a los suyos. Un participante se identifica mediante un token aleatorio por sala, del cual la base solo guarda el hash. Los PDF se sirven por una ruta autenticada, fuera de la carpeta pública.

## Datos persistentes

| Tabla | Uso |
| --- | --- |
| `usuarios` | Nombre, usuario de acceso, hash de contraseña, rol y estado |
| `documentos` | Metadatos y ubicación privada del PDF |
| `preguntas` | Preguntas aprobadas del material |
| `salas_evaluacion` | Código, docente, inicio, tiempo y copia de preguntas |
| `participantes` | Nombre, avatar, token e indicador de conexión |
| `respuestas_participantes` | Una respuesta por participante y posición de pregunta |
| `configuraciones` | Configuración general con OpenRouter como proveedor de IA |
| `borradores` | PDF y preguntas pendientes de aprobación |
| `intentos_acceso` | Límite de intentos de ingreso por dirección de conexión |

Las opciones se guardan como JSON para evitar cuatro columnas repetidas. Las salas conservan una copia de las preguntas con sus opciones y soluciones. Los porcentajes y puntuaciones se calculan a partir de las respuestas; no hay totales duplicados que puedan quedar desactualizados.

## Tiempo compartido

Al iniciar, el servidor registra un instante de inicio tres segundos en el futuro. Todos ven una breve cuenta inicial. Para una pregunta de `T` segundos, cada ciclo dura `T + 4` segundos:

1. La posición actual se deriva de `floor((ahora - inicio) / (T + 4))`.
2. Los primeros `T` segundos admiten una respuesta.
3. Los siguientes cuatro segundos muestran la respuesta correcta y la explicación.
4. Al terminar el último ciclo, la sala queda finalizada.

PHP determina la fase y comprueba el plazo al recibir una respuesta. JavaScript muestra una cuenta regresiva ajustada con la hora del servidor y consulta el estado aproximadamente cada segundo. Recargar la página no reinicia el reloj ni borra una respuesta. Una pérdida de conexión no pausa la sala.

La solución y los puntos de la respuesta actual se omiten del JSON durante la fase de pregunta. Cuando el tiempo termina, el servidor registra las respuestas ausentes con cero puntos y el tiempo máximo. La restricción única de la base impide enviar dos respuestas para la misma posición. Las transacciones serializan las operaciones de cada sala.

Se mantiene la revelación sincronizada de cuatro segundos. No se incluye el botón opcional para avanzar individualmente, porque rompería la regla de tiempo compartido del producto.

## Puntaje y ranking

Respuesta incorrecta o ausente: cero puntos.

Respuesta correcta:

```text
500 + redondear((1 - tiempo_empleado / tiempo_maximo) * 500)
```

El tiempo empleado se mide al recibir la respuesta en el servidor. Por ejemplo, responder correctamente en cinco segundos de un límite de veinte da 875 puntos. El ranking ordena por aciertos (equivalente al porcentaje dentro de una sala), luego puntos, luego menor tiempo total y finalmente orden de inscripción para resolver un empate completo.

## PDF e inteligencia artificial

El flujo de carga valida tamaño, extensión, tipo real y cabecera PDF. El lector extrae texto y número de páginas. Una pregunta nueva contiene un enunciado, cuatro opciones distintas, un índice correcto de 0 a 3 y una explicación. Se comprueba la cantidad solicitada y se vuelve a validar el contenido al guardar la revisión. Los borradores y las salas anteriores de dos opciones conservan su formato.

EvaluaEIC lee `OPENROUTER_API_KEY` y `OPENROUTER_MODEL` desde `configuracion/servidor.php` o el entorno de PHP. `ClienteOpenRouter` consulta el catálogo, adapta la solicitud al formato admitido por el modelo y traduce los errores del servicio. `GeneradorOpenRouter` organiza los lotes, el análisis por bloques y los reintentos acotados. `InteligenciaArtificial` valida las preguntas al generarlas y al guardarlas.

Se solicita JSON Schema estricto. Si el modelo rechaza expresamente ese formato, la misma operación utiliza JSON Object con el mismo modelo; no cambia de modelo ni de proveedor. La validación local comprueba estructura, cantidad, opciones, índice correcto y duplicados incluso cuando se utilizó esquema estricto.

Los documentos extensos se dividen por bytes sin romper UTF-8. Se recorre todo el texto de cada nivel y se condensan los bloques cuando el presupuesto lo exige. La interfaz informa que hubo análisis por bloques. Si el documento excede el tiempo total o el número de llamadas permitidas, el sistema pide dividirlo. El presupuesto es configurable y el consumo está sujeto a la cuota de OpenRouter.

El material se delimita como contenido de referencia y el prompt indica que sus instrucciones internas no deben seguirse. La clave se transmite solamente en la cabecera Authorization por HTTPS y no se publica en la interfaz ni en los errores. Se mantiene la validación del certificado y no se siguen redirecciones. Los errores de autenticación, cuota, conexión y modelo son explícitos. No se reintentan automáticamente los errores de cuota.

Los borradores se crean después de completar toda la generación. El guardado bloquea el borrador dentro de una transacción. Repetir la misma solicitud devuelve el material ya guardado.

## Alcance y siguientes ampliaciones posibles

La aplicación usa sondeo HTTP y MySQL o MariaDB. Para grupos simultáneos se recomienda Apache y validar la carga esperada y la configuración de red antes de una actividad real.
