<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#042B60">
    <meta name="csrf-token" content="<?= escapar($csrf) ?>">
    <title>EvaluaEIC · Escuela de Investigación Criminal</title>
    <link rel="icon" href="<?= escapar(ruta_app('/imagenes/logo-eic.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= escapar(ruta_app('/css/estilos.css')) ?>">
    <script id="datos-iniciales" type="application/json"><?= json_seguro($datosIniciales) ?></script>
    <script src="<?= escapar(ruta_app('/js/terceros/codigo-qr.js')) ?>" defer></script>
    <script src="<?= escapar(ruta_app('/js/comun.js')) ?>" defer></script>
    <?php if ($vista !== 'ingreso'): ?>
        <script src="<?= escapar(ruta_app('/js/experiencia.js')) ?>" defer></script>
    <?php endif; ?>
    <?php $script = $vista === 'ingreso' ? 'ingreso' : ($vista === 'participante' ? 'participante' : 'panel'); ?>
    <script src="<?= escapar(ruta_app('/js/' . $script . '.js')) ?>" defer></script>
</head>
<body class="vista-<?= escapar($vista) ?>">
<svg class="simbolos" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs>
<symbol id="i-mas" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
<symbol id="i-documento" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5"/></symbol>
<symbol id="i-subir" viewBox="0 0 24 24"><path d="M12 16V4m-5 5 5-5 5 5M4 16v4h16v-4"/></symbol>
<symbol id="i-flecha" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
<symbol id="i-cerrar" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>
<symbol id="i-papelera" viewBox="0 0 24 24"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></symbol>
<symbol id="i-usuarios" viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M17 4a4 4 0 0 1 0 8m3 9v-3a6 6 0 0 0-3-5"/></symbol>
<symbol id="i-cuenta" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2"/></symbol>
<symbol id="i-qr" viewBox="0 0 24 24"><path d="M3 3h7v7H3zm11 0h7v7h-7zM3 14h7v7H3zm11 0h3v3h-3zm6 0v7h-6m0-1h3"/></symbol>
<symbol id="i-trofeo" viewBox="0 0 24 24"><path d="M8 3h8v7a4 4 0 0 1-8 0V3zM8 5H4v3a4 4 0 0 0 4 4m8-7h4v3a4 4 0 0 1-4 4m-4 2v6m-4 1h8"/></symbol>
<symbol id="i-reloj" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
<symbol id="i-sonido" viewBox="0 0 24 24"><path d="M11 5 6 9H3v6h3l5 4zM15 9a4 4 0 0 1 0 6m3-9a8 8 0 0 1 0 12"/></symbol>
<symbol id="i-silencio" viewBox="0 0 24 24"><path d="M11 5 6 9H3v6h3l5 4zM16 9l5 6m0-6-5 6"/></symbol>
<symbol id="i-salir" viewBox="0 0 24 24"><path d="M9 4H4v16h5m5-12 4 4-4 4m-6-4h14"/></symbol>
<symbol id="i-capas" viewBox="0 0 24 24"><path d="m12 3 10 5-10 5L2 8zm-10 9 10 5 10-5M2 16l10 5 10-5"/></symbol>
<symbol id="i-ia" viewBox="0 0 24 24"><path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5zM21 2v4m-2-2h4"/></symbol>
<symbol id="i-ok" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
<symbol id="i-escudo" viewBox="0 0 24 24"><path d="m12 3 9 4v5c0 5-9 9-9 9s-9-4-9-9V7z"/><path d="m8 12 3 3 5-6"/></symbol>
</defs></svg>
<?php require RAIZ.'/vistas/'.$vista.'.php'; ?>
<div id="avisos" class="avisos" role="status" aria-live="polite"></div>
<dialog id="dialogo" aria-labelledby="titulo-dialogo"><div id="contenido-dialogo"></div></dialog>
<dialog id="confirmacion" class="dialogo-confirmacion" aria-labelledby="titulo-confirmacion"><div class="cabecera-dialogo"><h2 id="titulo-confirmacion"></h2><button class="boton-icono" type="button" data-cancelar-confirmacion aria-label="Cerrar"><svg class="icono"><use href="#i-cerrar"/></svg></button></div><div class="cuerpo-confirmacion"><p id="descripcion-confirmacion"></p></div><div class="acciones-confirmacion"><button class="boton suave" type="button" data-cancelar-confirmacion autofocus>Cancelar</button><button class="boton peligro" type="button" id="aceptar-confirmacion">Eliminar</button></div></dialog>
</body></html>
