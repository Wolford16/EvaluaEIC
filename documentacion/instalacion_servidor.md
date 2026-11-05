# EvaluaEIC: preparación local y traslado al servidor

## Configuración ya definida

La aplicación funciona localmente con PHP y SQLite. La IA utiliza únicamente Groq. La clave y el identificador del modelo son configuración privada; no están escritos en los controladores ni en JavaScript.

Para activar la IA basta con completar `GROQ_API_KEY` y `GROQ_MODEL` en `.env` y reiniciar PHP. Para cambiar de modelo se edita `GROQ_MODEL`. Debe ser un modelo de generación de texto que admita JSON; la comprobación breve confirma su compatibilidad antes de usar un PDF.

Las llamadas van a `https://api.groq.com/openai/v1/chat/completions`. El servidor envía el texto extraído, no la clave desde el navegador. Los participantes utilizan las preguntas ya aprobadas: una evaluación en curso no necesita llamar a Groq.

## Verificar primero en la computadora personal

1. Ejecuta `herramientas/probar_groq.php --solo-https` con el PHP de XAMPP. No utiliza credenciales ni genera contenido.
2. Completa las dos variables privadas y ejecuta `herramientas/probar_groq.php` o usa Administración → Groq → Probar conexión. Esta prueba usa un texto corto y consume algunos tokens.
3. Carga un PDF corto con texto seleccionable y prepara cinco preguntas con Groq.
4. Revisa, modifica y guarda el borrador. Crea una sala y completa la evaluación desde otra pestaña o teléfono.
5. Confirma los resultados y el historial. Una solicitud de guardado repetida debe devolver el mismo material.
6. Prueba los casos de fallo sin utilizar documentos personales. Las pruebas automatizadas simulan clave inválida, cuota, desconexión, JSON inválido y salida incompleta sin contactar a Groq.

Los nombres de los modelos y sus límites pertenecen a Groq y pueden cambiar. Si un modelo deja de estar disponible, copia otro identificador válido en `GROQ_MODEL` y repite primero la prueba breve. Los límites por organización o cuenta pueden continuar vigentes tras ese cambio; no se promete que cambiar de modelo restablezca una cuota.

## Requisitos del servidor

- PHP 8.2 o superior con PDO, pdo_sqlite o pdo_mysql, cURL, mbstring, fileinfo, iconv y zlib.
- Bibliotecas de Composer instaladas desde `composer.lock`, preferiblemente con `composer install --no-dev`.
- Conexión HTTPS saliente a `api.groq.com` y certificados raíz de confianza configurados en el PHP que atiende la web.
- Un dominio o subdominio dedicado cuyo directorio público apunte a la carpeta `publico`.
- Acceso de lectura y escritura del proceso PHP a `almacenamiento` para documentos, base SQLite, sesiones y temporales.
- Límites de PHP compatibles con PDF de 12 MB, solicitudes de 16 MB y tiempo de ejecución de al menos 210 segundos.

El servidor integrado de PHP se reserva para desarrollo. Para varios usuarios, utiliza un servidor web con PHP configurado para atender peticiones concurrentes, como Apache con PHP o PHP-FPM. En Apache se requiere `mod_rewrite` y `AllowOverride All` para las reglas incluidas en `publico/.htaccess`.

## Secuencia del traslado

1. Detén temporalmente las cargas y evaluaciones durante la copia final. Conserva un respaldo completo privado del código, la base y los PDF.
2. Copia el código y las bibliotecas, o instala estas últimas desde Composer. No expongas la raíz del proyecto como directorio web.
3. Crea `.env` en la raíz privada o define sus variables en el entorno del servicio PHP. Completa `APLICACION_URL` con el dominio HTTPS definitivo, la configuración de la base y las dos variables de Groq.
4. Copia los PDF y la base elegida. Si conservas SQLite, realiza una copia consistente de la base y de sus datos; no copies únicamente el archivo principal mientras esté recibiendo escrituras.
5. Ejecuta `herramientas/preparar.php` con el PHP del servidor. Es idempotente y no reemplaza cuentas existentes.
6. Configura el directorio público, los permisos de almacenamiento y el registro privado de errores. Si hay un proxy inverso, el servicio PHP debe reconocer correctamente las peticiones HTTPS para las cookies seguras.
7. Comprueba HTTPS y ejecuta la prueba corta de Groq en el servidor. Después repite la carga de un PDF y una evaluación completa desde el dominio final.
8. Comprueba que los enlaces y QR contienen el dominio definitivo, y conserva el respaldo previo fuera de la carpeta pública.

Las carpetas privadas y los respaldos no deben quedar accesibles desde la web. `.env`, claves, base de datos, PDF, sesiones y respaldos están excluidos de Git por la configuración actual.

## Base de datos

SQLite ya está validado localmente. El esquema y la conexión de MySQL están implementados, pero requieren una prueba con el servidor y sus credenciales. Cambiar `BD_MOTOR` no convierte automáticamente los datos de SQLite en MySQL. Si se decide usar MySQL, la migración de los datos existentes debe hacerse de forma controlada y comprobar los usuarios, materiales, salas y respuestas antes de abrir el sistema al grupo.

## Límites y errores de Groq

`configuracion/groq.php` centraliza el presupuesto por solicitud, la cantidad por lote, la cantidad máxima de llamadas y el tiempo total. El modelo informa su ventana de contexto y su capacidad máxima de salida; el generador adapta los lotes y divide el texto cuando lo necesita. Las cuotas reales de la cuenta pueden ser menores y requerir ajustar ese presupuesto.

Un PDF demasiado extenso se rechaza con una indicación de dividirlo. Los errores de cuota muestran la espera indicada por Groq y no provocan una cadena de reintentos. Los fallos de JSON o una salida incompleta permiten un reintento acotado. La aprobación docente sigue siendo necesaria para comprobar la exactitud de las preguntas.

Referencias técnicas: [API de Groq](https://console.groq.com/docs/api-reference), [formatos JSON](https://console.groq.com/docs/structured-outputs) y [límites de uso](https://console.groq.com/docs/rate-limits).
