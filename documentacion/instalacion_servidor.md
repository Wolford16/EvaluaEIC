# Instalación de EvaluaEIC en el servidor

## Copiar la carpeta a cPanel o Bitnami

1. Extrae el ZIP en el directorio que sirve el subdominio o copia allí la carpeta `EvaluaEIC`. Conserva el archivo `.htaccess` de la raíz y las bibliotecas de `terceros`. Apache 2.4.13 o superior necesita `mod_rewrite` y permiso para aplicar las directivas de ese archivo en la carpeta. Con esas condiciones, la copia queda accesible como `https://dominio/EvaluaEIC`. [Configuración de Apache](https://httpd.apache.org/docs/2.4/howto/htaccess.html).
2. Comprueba que `https://dominio/EvaluaEIC/database/instalar_evaluaeic.sql` y `https://dominio/EvaluaEIC/almacenamiento/` devuelven 403 o 404. Si alguno abre o descarga contenido, el encargado debe habilitar las reglas de Apache para la carpeta antes de usar la instalación.
3. Para una instalación nueva, crea la base y asigna el usuario MySQL en el panel del alojamiento. Selecciona esa base en phpMyAdmin e importa `database/instalar_evaluaeic.sql`. Si ya tienes EvaluaEIC con sus tablas, usa esa base. [Importar en phpMyAdmin](https://docs.phpmyadmin.net/en/latest/import_export.html).
4. Edita `configuracion/servidor.php` en la copia del servidor. Ajusta `APLICACION_URL` y completa `BD_HOST`, `BD_PUERTO`, `BD_NOMBRE`, `BD_USUARIO` y `BD_CLAVE`. Usa los nombres completos asignados por cPanel. Para crear el primer administrador puedes definir allí un `CLAVE_INSTALACION` privado de al menos 24 caracteres. En el mismo archivo están la clave y el modelo de OpenRouter.
5. Abre `https://dominio/EvaluaEIC`. Si la base está vacía, introduce el código de instalación y pulsa **Primer acceso de administrador**. Escribe tu nombre, usuario y contraseña, y pulsa **Crear administrador**. La cuenta se guarda y entras al panel. El primer acceso queda cerrado.
6. En **Administración → OpenRouter**, prueba la conexión. Después carga un PDF, guarda las preguntas, crea una sala y escanea el QR desde un teléfono.

Para revisar la conexión, abre `https://dominio/EvaluaEIC/index.php?verificar_bd=1`. Usa `CLAVE_INSTALACION` o, si lo dejaste vacío, copia el código que la página genera en `almacenamiento/clave_instalacion.txt`. El resultado muestra el nombre de la base MySQL y, si falla la conexión, el código del error cuando PDO lo proporciona.

EvaluaEIC usa PHP 8.0 o superior con PDO, pdo_mysql, cURL, mbstring, fileinfo, iconv y zlib. El PHP de Apache necesita escritura en `almacenamiento`, acceso HTTPS a OpenRouter y límites de carga de 12 MB por archivo y 16 MB por solicitud. Para generación de preguntas, configura al menos 210 segundos de tiempo de ejecución.

El informe PDF de resultados usa Times New Roman cuando está disponible en el servidor. Si el servidor tiene una copia con licencia en otro directorio, indica esa carpeta en `PDF_FUENTES_TIMES` en la configuración privada y conserva los nombres `times.ttf` y `timesbd.ttf`. Para servidores sin esta fuente, la carpeta `aplicacion/Recursos/fuentes` incluye Liberation Serif en 12 puntos.

El SQL crea las tablas para una instalación nueva. Para llevar registros de SQLite, prepara por separado la migración de usuarios, materiales y evaluaciones junto con los PDF correspondientes.

## Actualizar una instalación

Extrae el ZIP y actualiza los archivos de la aplicación. Conserva la base MySQL, el directorio `almacenamiento` y los datos de tu `configuracion/servidor.php`. El archivo `servidor.php` de la raíz se usa al ejecutar el servidor integrado de PHP.

## Límites y errores de OpenRouter

`configuracion/openrouter.php` centraliza el presupuesto por solicitud, la cantidad por lote, la cantidad máxima de llamadas y el tiempo total. El modelo informa su ventana de contexto y su capacidad máxima de salida; el generador adapta los lotes y divide el texto cuando lo necesita. Las cuotas reales de la cuenta pueden ser menores y requerir ajustar ese presupuesto.

Un PDF demasiado extenso se rechaza con una indicación de dividirlo. Los errores de cuota muestran la espera indicada por OpenRouter y no provocan una cadena de reintentos. Los fallos de JSON o una salida incompleta permiten un reintento acotado. La aprobación docente sigue siendo necesaria para comprobar la exactitud de las preguntas.

[API de OpenRouter](https://openrouter.ai/docs), [formatos JSON](https://openrouter.ai/docs/guides/features/structured-outputs) y [límites de los modelos gratuitos](https://openrouter.ai/collections/free-models).
