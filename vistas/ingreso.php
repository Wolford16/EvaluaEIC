<main class="ingreso-contenedor">
    <section class="tarjeta-ingreso">
        <img class="logo-ingreso" src="<?= escapar(ruta_app('/imagenes/logo-eic.png')) ?>" alt="Escuela de Investigación Criminal" width="112" height="112">
        <div class="centrado"><h1 class="marca-titulo">Evalua<span>EIC</span></h1><p class="institucion-ingreso">Escuela de Investigación Criminal</p></div>
        <?php if ($mensajeInstalacion !== ''): ?>
            <p role="status"><?= escapar($mensajeInstalacion) ?></p>
        <?php endif; ?>
        <?php if ($instalacionDisponible): ?>
            <?php if ($primerAcceso ?? false): ?>
            <?php $usuarioInicial = is_string($_POST['usuario'] ?? null) ? mb_substr($_POST['usuario'], 0, 40) : ''; ?>
            <form method="post" action="<?= escapar(ruta_app('/crear_administrador.php')) ?>" class="formulario">
                <div class="separador-etiqueta">PRIMER ADMINISTRADOR</div>
                <input type="hidden" name="csrf" value="<?= escapar($csrf) ?>">
                <input type="hidden" name="accion" value="guardar">
                <label>Nombre completo<input name="nombre" type="text" minlength="2" maxlength="120" autocomplete="name" required></label>
                <label>Usuario<input name="usuario" type="text" value="<?= escapar($usuarioInicial) ?>" minlength="3" maxlength="40" autocomplete="username" required></label>
                <label>Contraseña<input name="clave" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
                <button class="boton primario ancho" type="submit">Crear administrador</button>
            </form>
            <?php else: ?>
            <form method="post" action="<?= escapar(ruta_app('/crear_administrador.php')) ?>" class="formulario">
                <input type="hidden" name="csrf" value="<?= escapar($csrf) ?>">
                <input type="hidden" name="accion" value="entrar">
                <label>Código de instalación<input name="clave_instalacion" type="password" autocomplete="off" required></label>
                <?php if (strlen(entorno('CLAVE_INSTALACION')) < 24): ?>
                    <p class="texto-suave texto-pequeno">Abre Verificar base de datos y copia el código de <code>almacenamiento/clave_instalacion.txt</code> en el servidor.</p>
                <?php endif; ?>
                <button class="boton primario ancho" type="submit">Primer acceso de administrador</button>
            </form>
            <?php endif; ?>
            <p class="texto-pequeno"><a href="<?= escapar(ruta_app('/index.php?verificar_bd=1')) ?>">Verificar base de datos</a></p>
        <?php else: ?>
        <?php $usuarioIngreso = is_string($_POST['usuario'] ?? null) ? mb_substr($_POST['usuario'], 0, 40) : ''; ?>
        <form id="formulario-ingreso" method="post" action="<?= escapar(ruta_app('/index.php')) ?>" class="formulario">
            <div class="separador-etiqueta">ACCESO INSTITUCIONAL</div>
            <input type="hidden" name="csrf" value="<?= escapar($csrf) ?>">
            <label>Usuario<input type="text" name="usuario" value="<?= escapar($usuarioIngreso) ?>" placeholder="Escribe tu usuario" minlength="3" maxlength="40" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
            <label>Contraseña<div class="campo-clave"><input id="clave-ingreso" type="password" name="clave" placeholder="Escribe tu contraseña" autocomplete="current-password" required><button type="button" class="mostrar-clave" id="mostrar-clave" aria-label="Mostrar contraseña">Ver</button></div></label>
            <?php if (isset($mensajeIngreso)): ?>
                <p class="mensaje-error" role="alert"><?= escapar($mensajeIngreso) ?></p>
            <?php endif; ?>
            <button class="boton primario ancho" type="submit">Iniciar sesión <svg class="icono"><use href="#i-flecha"/></svg></button>
        </form>
        <div class="acceso-participante"><a class="boton ambar ancho" href="<?= escapar(ruta_app('/participar')) ?>"><svg class="icono"><use href="#i-qr"/></svg> Acceso de participantes</a></div>
        <?php endif; ?>
    </section>
    <p class="pie-ingreso">EIC · Ciencia, ética y tecnología</p>
</main>
