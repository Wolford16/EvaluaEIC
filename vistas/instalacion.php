<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificar base de datos · EvaluaEIC</title>
    <link rel="stylesheet" href="<?= escapar(ruta_app('/css/estilos.css')) ?>">
</head>
<body class="vista-ingreso">
<main class="ingreso-contenedor">
    <section class="tarjeta-ingreso">
        <h1>Verificar base de datos</h1>
        <?php if ($mensaje !== ''): ?>
            <p role="status" class="<?= $correcto ? '' : 'mensaje-error' ?>"><?= escapar($mensaje) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= escapar(ruta_app('/index.php?verificar_bd=1')) ?>" class="formulario">
            <input type="hidden" name="csrf" value="<?= escapar($csrf) ?>">
            <label>Código de instalación<input name="clave_instalacion" type="password" autocomplete="off" required></label>
            <?php if (strlen(entorno('CLAVE_INSTALACION')) < 24): ?>
                <p class="texto-suave texto-pequeno">Copia el código de <code>almacenamiento/clave_instalacion.txt</code> en el servidor.</p>
            <?php endif; ?>
            <button type="submit" class="boton primario ancho">Verificar conexión</button>
        </form>
        <p><a href="<?= escapar(ruta_app('/ingreso')) ?>">Ir al inicio de sesión</a></p>
    </section>
</main>
</body>
</html>
