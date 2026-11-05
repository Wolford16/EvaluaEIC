<?php require RAIZ.'/vistas/cabecera.php'; ?>
<main class="contenido-principal">
    <section class="panel encabezado-pagina"><div class="titulo-con-icono"><span class="icono-titulo"><svg class="icono"><use href="#i-escudo"/></svg></span><h1>Administración</h1></div></section>
    <nav class="pestanas pestanas-admin" aria-label="Secciones de administración">
        <button data-admin="documentos" class="activa"><svg class="icono"><use href="#i-documento"/></svg>Documentos</button>
        <button data-admin="usuarios"><svg class="icono"><use href="#i-usuarios"/></svg>Usuarios</button>
        <button data-admin="evaluaciones"><svg class="icono"><use href="#i-trofeo"/></svg>Evaluaciones</button>
        <button data-admin="motores"><svg class="icono"><use href="#i-ia"/></svg>OpenRouter</button>
    </nav>
    <section id="seccion-admin" class="panel contenido-admin" aria-live="polite"></section>
    <footer class="pie-panel"><span>Escuela de Investigación Criminal</span><span>EvaluaEIC</span></footer>
</main>
