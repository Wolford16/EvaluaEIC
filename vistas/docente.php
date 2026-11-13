<?php require RAIZ.'/vistas/cabecera.php'; ?>
<main class="contenido-principal">
    <section class="panel encabezado-pagina">
        <div class="titulo-con-icono"><span class="icono-titulo"><svg class="icono"><use href="#i-capas"/></svg></span><h1>Material de cátedra</h1></div>
        <div class="grupo-botones">
            <button id="abrir-carga" class="boton suave"><svg class="icono"><use href="#i-ia"/></svg> Generar con IA</button>
            <button class="boton primario" data-accion="crear-manual"><svg class="icono"><use href="#i-mas"/></svg> Crear manualmente</button>
        </div>
    </section>
    <div class="etiqueta-seccion"><h2>Biblioteca de exámenes</h2><span id="cantidad-materiales" class="texto-suave"></span></div>
    <label class="selector-historial">Filtrar por curso
        <select id="filtro-cursos"><option value="">Todos los cursos</option></select>
    </label>
    <div id="lista-materiales" class="cuadricula-materiales" aria-live="polite"><div class="esqueleto panel">Cargando materiales…</div></div>
    <section id="detalle-material" class="panel detalle-material" hidden></section>
    <details class="historial-docente panel"><summary>Historial de evaluaciones</summary><div id="historial-docente"></div></details>
    <footer class="pie-panel"><span>Escuela de Investigación Criminal</span><span>EvaluaEIC</span></footer>
</main>
