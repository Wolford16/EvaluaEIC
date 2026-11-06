# EvaluaEIC en PHP

Plataforma local de evaluaciones de la Escuela de Investigación Criminal. Usa PHP 8.0 o superior, HTML, CSS y JavaScript, con el logo original y la identidad visual de la EIC.

Para instalar en Bitnami, copia esta carpeta y sigue [la guía de instalación](documentacion/instalacion_servidor.md).

## Iniciar en este equipo

Las bibliotecas ya están descargadas y la base de datos local está preparada. Abre PowerShell en la carpeta del proyecto y ejecuta:

```powershell
.\iniciar.ps1
```

Abre [http://localhost:8080](http://localhost:8080). Mantén la consola abierta; `Ctrl+C` detiene el servidor. Si la política del equipo impide ejecutar el archivo, puedes iniciarlo sin cambiar la política permanente:

```powershell
powershell -ExecutionPolicy Bypass -File .\iniciar.ps1
```

El iniciador local usa el PHP de este equipo. Puedes elegir otro ejecutable mediante `EVALUAEIC_PHP`. La base local utiliza SQLite.

En una base nueva, crea el primer administrador desde una consola en la raíz del proyecto.

```powershell
C:\xampp\php\php.exe herramientas/crear_administrador.php
```

El comando muestra el usuario `admin-inicial` y una contraseña aleatoria. Entra con esa cuenta y crea las cuentas definitivas desde **Administración → Usuarios**. Para retirar el acceso inicial, entra con el administrador definitivo y desactiva `admin-inicial`. Los nombres de usuario son únicos y tienen de 3 a 40 caracteres.

Al iniciar una instalación anterior, la actualización convierte el correo en un nombre de usuario tomando su parte anterior a `@`. Si hay coincidencias, añade un sufijo como `.1`. Conserva los identificadores, contraseñas, materiales y evaluaciones. En SQLite crea un respaldo completo previo dentro de `almacenamiento/respaldos`.

## Probar una evaluación

1. Ingresa como docente, carga un PDF con texto seleccionable y guarda las preguntas revisadas.
2. Selecciona su tarjeta y pulsa **Crear evaluación**.
3. Elige 15, 20 o 30 segundos por pregunta y crea la sala.
4. Pulsa **Vista participante** para abrir otra pestaña. Escribe un nombre y elige un avatar.
5. Vuelve a la pestaña del docente y pulsa **Iniciar evaluación**.
6. Responde desde la pestaña del alumno. La opción queda bloqueada y el tiempo sigue corriendo.
7. Al cerrar cada pregunta, se revela la solución durante cuatro segundos. Después, el grupo avanza al mismo tiempo.
8. Al terminar, se muestra el podio y el ranking. Los resultados también quedan en el historial del docente y del administrador.

## Cargar tus propios PDF

En **Cargar PDF**, selecciona un documento de hasta 12 MB e indica el título. El formulario propone 10 preguntas y permite elegir una cantidad de 1 a 30. OpenRouter genera las preguntas automáticamente.

Después de preparar el material, puedes editar el enunciado, las cuatro opciones, la respuesta correcta y la explicación, o eliminar preguntas. El documento entra en la biblioteca al pulsar **Guardar material**. Cerrar la revisión descarta ese borrador. Los borradores abandonados vencen después de 24 horas y se limpian al preparar el sistema.

La lectura de PDF usa Smalot/PdfParser. Los archivos deben contener texto seleccionable. OpenRouter analiza el texto con un presupuesto de entrada y salida. Los documentos extensos se dividen en bloques y se condensan antes de formular las preguntas. La revisión avisa cuando se utilizaron bloques. Si el documento supera el número de solicitudes o el tiempo permitido, se pide dividirlo.

## Configurar OpenRouter

EvaluaEIC lee `OPENROUTER_API_KEY` y `OPENROUTER_MODEL` del archivo privado `configuracion/servidor.php`.

Para usar otro modelo de texto de OpenRouter, modifica `OPENROUTER_MODEL` y reinicia PHP. Usa el identificador exacto que figura en el [catálogo de OpenRouter](https://openrouter.ai/models). El sufijo `:free` identifica la variante gratuita.

Las variables del entorno del servidor tienen prioridad sobre el archivo privado. Git excluye `configuracion/servidor.php`.

Primero comprueba PHP y HTTPS sin usar una clave:

```powershell
C:\xampp\php\php.exe herramientas/probar_openrouter.php --solo-https
```

La primera comprobación consulta el catálogo público. Después de completar las dos variables, comprueba la clave y el modelo con un texto breve.

```powershell
C:\xampp\php\php.exe herramientas/probar_openrouter.php
```

También puedes utilizar **Administración → OpenRouter → Probar conexión**. Esta prueba genera una respuesta breve y verifica la autenticación y el formato.

La integración usa Chat Completions y solicita un esquema JSON cuando el modelo lo admite. PHP comprueba la cantidad de preguntas, las cuatro opciones, el índice de la respuesta correcta, la explicación y los enunciados repetidos. [Formatos de respuesta de OpenRouter](https://openrouter.ai/docs/guides/features/structured-outputs).

Los límites de tamaño, lotes y tiempo se configuran en `configuracion/openrouter.php`. El generador ajusta su presupuesto según la ventana y el máximo de salida del modelo. Agrupa hasta 10 preguntas por lote y permite un reintento acotado si el JSON es inválido o la salida queda incompleta.

Las variantes `:free` tienen límites de uso. Si se alcanza la cuota, la interfaz muestra la espera cuando OpenRouter la informa. [Cuotas de los modelos gratuitos](https://openrouter.ai/collections/free-models).

El documento y sus preguntas se guardan como material definitivo únicamente cuando el docente aprueba el borrador. Reenviar el guardado del mismo borrador devuelve el material existente, sin duplicarlo.

Para publicar en Bitnami bajo una ruta como `https://nombredelservidor/EvaluaEIC`, sigue `documentacion/instalacion_servidor.md`.

## Participar desde teléfonos en la misma red

1. Conecta el equipo y los teléfonos a la misma red local.
2. En `configuracion/servidor.php`, cambia `APLICACION_URL` por la dirección del equipo, por ejemplo `http://192.168.1.20:8080`.
3. Inicia con `.\iniciar.ps1 -RedLocal`.
4. Permite el puerto elegido para la red privada si Windows lo solicita y crea una sala nueva.

El QR contiene ese enlace. Un teléfono no puede acceder al `localhost` de otro equipo. Para cambiar de puerto, usa `.\iniciar.ps1 -Puerto 8081` y ajusta también `APLICACION_URL`.

## Base de datos

La instalación local usa SQLite y conserva la base anterior de `almacenamiento`. Las nuevas bases locales usan el nombre `evaluaeic.sqlite`.

Para MySQL 8 o MariaDB compatible:

1. Selecciona la base asignada e importa `database/instalar_evaluaeic.sql`.
2. Cambia `BD_MOTOR` a `mysql` en `configuracion/servidor.php` y completa host, puerto, nombre, usuario y clave.
3. En una base nueva, ejecuta `php herramientas/crear_administrador.php`.

Cambiar de motor inicia una base separada. Si necesitas llevar los registros locales, planifica la migración de SQLite y sus PDF antes de la puesta en marcha.

## Organización

```text
EvaluaEIC/
├── aplicacion/
│   ├── Controladores/       Acceso, materiales, salas y administración
│   └── Servicios/           Datos, seguridad, PDF, IA y evaluación
├── database/                SQL de instalación para MySQL y MariaDB
├── configuracion/           Ajustes privados, incluido servidor.php
├── documentacion/           Arquitectura y decisiones de esta versión
├── herramientas/            Preparación inicial y Composer
├── almacenamiento/          Base de datos, sesiones, PDF y temporales privados
├── publico/                 Entrada de la aplicación y recursos del navegador
│   ├── css/                 Estilos locales
│   ├── imagenes/            Marca del proyecto
│   └── js/                  Panel, ingreso, participantes y biblioteca QR
├── pruebas/                 Recorrido HTTP con datos aislados
├── referencia/              Demo y descripción originales
├── terceros/                Bibliotecas administradas por Composer
├── vistas/                  Plantillas PHP en español
├── .htaccess                Rutas y protección de archivos en Apache
├── index.php                Entrada de Apache al copiar la carpeta
├── iniciar.ps1              Inicio local en Windows
└── servidor.php             Enrutamiento del servidor integrado
```

Los nombres de nuestro código están en español. Los nombres estándar de Composer y los archivos de bibliotecas externas se conservan para mantener la compatibilidad.

## Instalar bibliotecas en otro equipo

Con Composer instalado y las extensiones PHP PDO, pdo_sqlite (o pdo_mysql), mbstring, fileinfo, cURL, iconv y zlib activadas:

```powershell
composer install
C:\xampp\php\php.exe herramientas/preparar.php
C:\xampp\php\php.exe herramientas/crear_administrador.php
.\iniciar.ps1
```

`composer.lock` fija las versiones del lector PDF. El generador QR está incluido en `publico/js/terceros` junto con su licencia; no depende de un CDN.

## Verificación

```powershell
node pruebas/recorrido.mjs
```

Requiere Node.js 20 o superior y PHP. Inicia su propio servidor en el puerto 8091 y usa una base SQLite aislada dentro de una carpeta temporal de `almacenamiento`. Elimina únicamente esa carpeta al terminar. Comprueba 56 condiciones del recorrido real por HTTP, incluyendo el acceso por usuario y contraseña, unicidad de usuarios, roles, PDF, salas, respuesta única, ocultación de soluciones, tiempos, puntaje y conservación del historial.

Las pruebas específicas de OpenRouter funcionan con un transporte simulado.

```powershell
C:\xampp\php\php.exe pruebas/openrouter.php
```

La actualización de cuentas anteriores tiene una prueba independiente con 14 comprobaciones:

```powershell
C:\xampp\php\php.exe pruebas/migracion_usuarios.php
```

## Identidad visual

El logo se conserva sin alteraciones en `publico/imagenes/logo-eic.png`. El manual original está en `referencia/manual-identidad-eic.pdf`. La interfaz utiliza Arial y esta paleta: azul oscuro `#042B60`, amarillo `#FFED00`, azul claro `#009FE3` y turquesa `#49BED8`. Para el turquesa se sigue el RGB y la muestra del manual; su tabla repite el hexadecimal del azul claro.

Los colores se centralizan en variables al inicio de `publico/css/estilos.css`. Las ventanas comparten cabecera, márgenes y botones alineados; las confirmaciones de eliminación también utilizan el diseño de la aplicación.

Los errores del servidor integrado quedan en `almacenamiento/servidor.log`. Los cambios de PHP, CSS o JavaScript se ven al recargar la página.
