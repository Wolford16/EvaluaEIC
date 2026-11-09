<header class="cabecera">
    <a href="<?= escapar(ruta_app($usuario['rol']==='admin'?'/admin':'/docente')) ?>" class="marca"><img class="logo-cabecera" src="<?= escapar(ruta_app('/imagenes/logo-eic.png')) ?>" alt="Escuela de Investigación Criminal" width="48" height="48"><span><strong>Evalua<span class="azul">EIC</span></strong><small><?= $vista==='administracion'?'Administración':'Docencia' ?></small></span></a>
    <nav class="acciones-cabecera" aria-label="Acciones de cuenta">
        <a class="boton ambar pequeno" href="<?= escapar(ruta_app('/participar')) ?>" target="_blank" rel="noopener" aria-label="Acceso de participantes"><svg class="icono"><use href="#i-qr"/></svg><span>Participantes</span></a>
        <div class="cuenta"><span class="inicial-usuario"><?= escapar(mb_substr($usuario['nombre'],0,1)) ?></span><span><?= escapar($usuario['nombre']) ?><small><?= $usuario['rol']==='admin'?'Administrador':'Docente / supervisor' ?></small></span></div>
        <form method="post" action="<?= escapar(ruta_app('/index.php?salir=1')) ?>">
            <input type="hidden" name="csrf" value="<?= escapar($csrf) ?>">
            <button type="submit" class="boton-icono" aria-label="Cerrar sesión" title="Cerrar sesión"><svg class="icono"><use href="#i-salir"/></svg></button>
        </form>
    </nav>
</header>
