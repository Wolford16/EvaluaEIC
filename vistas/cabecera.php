<header class="cabecera">
    <a href="<?= $usuario['rol']==='admin'?'/admin':'/docente' ?>" class="marca"><img class="logo-cabecera" src="/imagenes/logo-eic.png" alt="Escuela de Investigación Criminal" width="48" height="48"><span><strong>Evalua<span class="azul">EIC</span></strong><small><?= $vista==='administracion'?'Administración':'Docencia' ?></small></span></a>
    <nav class="acciones-cabecera" aria-label="Acciones de cuenta">
        <a class="boton ambar pequeno" href="/participar" target="_blank" rel="noopener" aria-label="Acceso de participantes"><svg class="icono"><use href="#i-qr"/></svg><span>Participantes</span></a>
        <?php if ($usuario['rol']==='admin'): ?><a class="boton suave pequeno" href="<?= $vista==='administracion'?'/docente':'/admin' ?>"><?= $vista==='administracion'?'Vista docente':'Administración' ?></a><?php endif; ?>
        <div class="cuenta"><span class="inicial-usuario"><?= escapar(mb_substr($usuario['nombre'],0,1)) ?></span><span><?= escapar($usuario['nombre']) ?><small><?= $usuario['rol']==='admin'?'Administrador':'Docente / supervisor' ?></small></span></div>
        <button id="cerrar-sesion" class="boton-icono" aria-label="Cerrar sesión" title="Cerrar sesión"><svg class="icono"><use href="#i-salir"/></svg></button>
    </nav>
</header>
