# Instalación de EvaluaEIC en el servidor

## Copiar la carpeta a Bitnami

1. Copia la carpeta completa `EvaluaEIC` en el directorio que Apache ya sirve, igual que el PEA. Conserva el archivo `.htaccess` de la raíz y las bibliotecas de `terceros`. Apache 2.4.13 o superior necesita `mod_rewrite` y permiso para aplicar las directivas de ese archivo en la carpeta. Con esas condiciones, la copia queda accesible como `https://dominio/EvaluaEIC`. [Configuración de Apache](https://httpd.apache.org/docs/2.4/howto/htaccess.html).
2. Comprueba que `https://dominio/EvaluaEIC/database/instalar_evaluaeic.sql` y `https://dominio/EvaluaEIC/almacenamiento/` devuelven 403 o 404. Si alguno abre o descarga contenido, el encargado debe habilitar las reglas de Apache para la carpeta antes de usar la instalación.
3. En phpMyAdmin, selecciona la base asignada a EvaluaEIC e importa `database/instalar_evaluaeic.sql`. Si todavía falta la base, créala primero con la cuenta de instalación. La base puede llamarse `evaluaeic` o usar el nombre que asigne la institución. [Importar en phpMyAdmin](https://docs.phpmyadmin.net/en/latest/import_export.html).
4. Edita `configuracion/servidor.php` en la **copia del servidor**. Escribe la URL base `https://dominio/EvaluaEIC` en `APLICACION_URL`, cambia `BD_MOTOR` a `mysql` y completa `BD_HOST`, `BD_PUERTO`, `BD_NOMBRE`, `BD_USUARIO` y `BD_CLAVE`. En el mismo archivo están la clave y el modelo de OpenRouter.
5. Abre `https://dominio/EvaluaEIC`. Si la base está vacía, el login muestra **Crear administrador**. Copia el código que se generó en `almacenamiento/clave_instalacion.txt` en el servidor y elige el usuario y la contraseña. Una vez creada la cuenta, el login vuelve a su pantalla habitual.
6. En **Administración → OpenRouter**, prueba la conexión. Después carga un PDF, guarda las preguntas, crea una sala y escanea el QR desde un teléfono.

Si necesitas revisar la conexión a la base, abre `https://dominio/EvaluaEIC/verificar_bd.php` y usa el mismo código. Si ya habías configurado `CLAVE_INSTALACION` con al menos 24 caracteres, ese valor tiene prioridad.

EvaluaEIC usa PHP 8.0 o superior con PDO, pdo_mysql, cURL, mbstring, fileinfo, iconv y zlib. El PHP de Apache necesita escritura en `almacenamiento`, acceso HTTPS a OpenRouter y límites de carga de 12 MB por archivo y 16 MB por solicitud. Para generación de preguntas, configura al menos 210 segundos de tiempo de ejecución.

El SQL crea las tablas para una instalación nueva. Para llevar registros de SQLite, prepara por separado la migración de usuarios, materiales y evaluaciones junto con los PDF correspondientes.

## Agregar la instalación web a una copia existente

Copia estos archivos a las mismas rutas dentro de `EvaluaEIC` en el servidor.

- `.htaccess` y `publico/index.php`
- `aplicacion/Controladores/Instalacion.php` y `aplicacion/Servicios/Seguridad.php`
- `herramientas/crear_administrador.php` y `herramientas/verificar_bd.php`
- `vistas/ingreso.php`, `vistas/instalacion.php` y `publico/js/ingreso.js`

Conserva la base que ya importaste y el `configuracion/servidor.php` del servidor. Abre la dirección habitual de EvaluaEIC y sigue el paso 5.

## Límites y errores de OpenRouter

`configuracion/openrouter.php` centraliza el presupuesto por solicitud, la cantidad por lote, la cantidad máxima de llamadas y el tiempo total. El modelo informa su ventana de contexto y su capacidad máxima de salida; el generador adapta los lotes y divide el texto cuando lo necesita. Las cuotas reales de la cuenta pueden ser menores y requerir ajustar ese presupuesto.

Un PDF demasiado extenso se rechaza con una indicación de dividirlo. Los errores de cuota muestran la espera indicada por OpenRouter y no provocan una cadena de reintentos. Los fallos de JSON o una salida incompleta permiten un reintento acotado. La aprobación docente sigue siendo necesaria para comprobar la exactitud de las preguntas.

[API de OpenRouter](https://openrouter.ai/docs), [formatos JSON](https://openrouter.ai/docs/guides/features/structured-outputs) y [límites de los modelos gratuitos](https://openrouter.ai/collections/free-models).
