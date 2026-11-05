<?php require RAIZ.'/vistas/cabecera.php'; ?>
<main class="contenido-principal">
    <section class="panel encabezado-pagina">
        <div class="titulo-con-icono"><span class="icono-titulo"><svg class="icono"><use href="#i-capas"/></svg></span><h1>Material de cátedra</h1></div>
        <button id="abrir-carga" class="boton primario"><svg class="icono"><use href="#i-subir"/></svg> Cargar PDF</button>
    </section>
    <div class="etiqueta-seccion"><h2>Tu biblioteca</h2><span id="cantidad-materiales" class="texto-suave"></span></div>
    <div id="lista-materiales" class="cuadricula-materiales" aria-live="polite"><div class="esqueleto panel">Cargando materiales…</div></div>
    <section id="detalle-material" class="panel detalle-material" hidden></section>
    <details class="historial-docente panel"><summary>Historial de evaluaciones</summary><div id="historial-docente"></div></details>
    <footer class="pie-panel"><span>Escuela de Investigación Criminal</span><span>EvaluaEIC</span></footer>
</main>
