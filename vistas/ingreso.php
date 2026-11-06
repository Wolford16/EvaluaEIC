<main class="ingreso-contenedor">
    <section class="tarjeta-ingreso">
        <img class="logo-ingreso" src="<?= escapar(ruta_app('/imagenes/logo-eic.png')) ?>" alt="Escuela de Investigación Criminal" width="112" height="112">
        <div class="centrado"><h1 class="marca-titulo">Evalua<span>EIC</span></h1><p class="institucion-ingreso">Escuela de Investigación Criminal</p></div>
        <form id="formulario-ingreso" class="formulario">
            <div class="separador-etiqueta">ACCESO INSTITUCIONAL</div>
            <label>Usuario<input type="text" name="usuario" placeholder="Escribe tu usuario" minlength="3" maxlength="40" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
            <label>Contraseña<div class="campo-clave"><input id="clave-ingreso" type="password" name="clave" placeholder="Escribe tu contraseña" autocomplete="current-password" required><button type="button" class="mostrar-clave" id="mostrar-clave" aria-label="Mostrar contraseña">Ver</button></div></label>
            <p id="error-ingreso" class="mensaje-error" role="alert" hidden></p>
            <button class="boton primario ancho" type="submit">Iniciar sesión <svg class="icono"><use href="#i-flecha"/></svg></button>
        </form>
        <div class="acceso-participante"><a class="boton ambar ancho" href="<?= escapar(ruta_app('/participar')) ?>"><svg class="icono"><use href="#i-qr"/></svg> Acceso de participantes</a></div>
    </section>
    <p class="pie-ingreso">EIC · Ciencia, ética y tecnología</p>
</main>
