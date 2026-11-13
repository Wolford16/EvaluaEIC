'use strict';
(() => {
  const {api,ruta,escapar:e,icono:i,aviso,datos,abrirDialogo,cerrarDialogo,manejarCierre,confirmar,vacio,cabeceraDialogo,avatar,ranking,etiquetaEquipo,resultadosEquipos,detalleRespuestas,qr}=Evalua;
  const {prepararSonido,musica,efecto,controlSonido,presentarPodio}=Experiencia;
  let materiales=[], materialActivo=null, pestana='preguntas', seccion='documentos', borrador=null, preguntasRevision=[], salaTemporizador=null, secuenciaCarga=0, secuenciaSala=0;
  let detenerPodio=()=>{};
  let cursos = [];
  const esAdmin=datos.vista==='administracion';
  const fecha=valor=>new Date(valor.replace(' ','T')).toLocaleDateString('es-HN',{day:'2-digit',month:'short',year:'numeric'});
  const cantidad=(numero,singular,plural)=>`${numero} ${Number(numero)===1?singular:plural}`;
  const accion=(nombre,id,texto,clase='suave')=>`<button class="boton ${clase} pequeno" data-accion="${nombre}" data-id="${e(id)}">${texto}</button>`;
  function enlacesInforme(codigo, participante=null, etapa=null) {
    const parametros = new URLSearchParams({informe_pdf: codigo});
    if (participante !== null) parametros.set('participante', participante);
    const alcance = participante === null ? '' : `/${participante}`;
    const pdf = ruta(`/index.php?${parametros}`);
    const excel = ruta(`/resultados/${encodeURIComponent(codigo)}${alcance}.xlsx`);
    return [[pdf, 'PDF', ''], [excel, 'Excel', ' download']].map(([enlace, formato, atributo]) =>
      `<a class="boton suave pequeno" href="${e(enlace)}"${atributo}>${i('documento')} ${etapa ? e(etapa) : 'Descargar'} ${formato}</a>`
    ).join('');
  }
  function enlacesComparacion(codigo, participante = null) {
    return [['pdf', 'PDF'], ['xlsx', 'Excel']].map(([formato, nombre]) => {
      const parametros = new URLSearchParams({comparacion: codigo, formato});
      if (participante !== null) parametros.set('participante', participante);
      return `<a class="boton suave pequeno" href="${e(ruta(`/index.php?${parametros}`))}">${i('documento')} Comparación ${nombre}</a>`;
    }).join('');
  }
  function etapaSala(sala) {
    return sala.etapa ?? (Number(sala.es_entrada) ? 'Entrada' : sala.codigo_entrada ? 'Salida' : null);
  }
  function etiquetaEtapa(sala) {
    const etapa = etapaSala(sala);
    return etapa ? `<small class="bloque texto-suave">${e(etapa)}</small>` : '';
  }
  function accionesEtapas(sala) {
    const etapa = etapaSala(sala);
    const entrada = etapa === 'Entrada' ? sala.codigo : sala.codigo_entrada;
    if (!entrada) return '';
    const otraEtapa = etapa === 'Entrada'
      ? (sala.codigo_salida ? accion('ver-sala', sala.codigo_salida, 'Abrir salida') : accion('crear-salida', entrada, 'Aplicar salida', 'primario'))
      : accion('ver-sala', entrada, 'Abrir entrada');
    return otraEtapa + (sala.salida_finalizada || etapa === 'Salida'
      ? accion('ver-comparacion', entrada, 'Comparar resultados', 'primario') : '');
  }
  function rankingSala(s) {
    const verRespuestas = s.fase === 'finalizada' ? p => `<button type="button" class="boton suave pequeno" data-accion="ver-respuestas" data-id="${p.id}" data-codigo="${e(s.codigo)}" aria-label="Ver respuestas de ${e(p.nombre)}">Ver respuestas</button>` : null;
    const tituloIndividual=s.cantidad_equipos?'<h3 class="titulo-ranking-individual">Ranking individual</h3>':'';
    const informe=verRespuestas?`<div class="grupo-botones margen-superior" role="group" aria-label="Informe general">${enlacesInforme(s.codigo, null, s.etapa)}${accionesEtapas(s)}</div>`:'';
    return informe+resultadosEquipos(s)+tituloIndividual+ranking(s.ranking, s.total, null, verRespuestas);
  }
  async function cargarMateriales() {
    ({documentos: materiales, cursos} = await api('materiales'));
    if (esAdmin) return;
    const filtro = document.getElementById('filtro-cursos');
    const seleccionado = filtro.value;
    filtro.innerHTML = '<option value="">Todos los cursos</option><option value="sin-curso">Sin curso</option>' + opcionesCursos();
    filtro.value = [...filtro.options].some(opcion => opcion.value === seleccionado) ? seleccionado : '';
    mostrarBiblioteca();
  }
  function opcionesCursos() {
    return cursos.map(curso => `<option value="${e(curso.id)}">${e(curso.nombre)}</option>`).join('');
  }
  function mostrarBiblioteca() {
    const filtro = document.getElementById('filtro-cursos').value;
    const visibles = materiales.filter(material => !filtro || String(material.curso_id ?? 'sin-curso') === filtro);
    document.getElementById('cantidad-materiales').textContent = cantidad(visibles.length, 'examen', 'exámenes');
    document.getElementById('lista-materiales').innerHTML = visibles.length
      ? visibles.map(tarjetaMaterial).join('')
      : `<div class="panel vacio-biblioteca">${vacio('Sin exámenes', filtro ? 'Este curso todavía no tiene exámenes disponibles.' : 'Genera un examen con IA o créalo manualmente.', 'documento')}</div>`;
  }
  function tarjetaMaterial(material) {
    const seleccionada = materialActivo === Number(material.id);
    const eliminar = material.puede_gestionar
      ? `<button class="boton-icono peligro" data-accion="eliminar-material" data-id="${material.id}" aria-label="Eliminar ${e(material.titulo)}" title="Eliminar documento">${i('papelera')}</button>`
      : '';
    return `<article class="tarjeta-material ${seleccionada ? 'seleccionada' : ''}">
      <button class="abrir-tarjeta" data-accion="material" data-id="${material.id}" aria-expanded="${seleccionada}">
        <div class="fila-tarjeta"><span class="icono-pdf">${i('documento')}<small>${material.nombre_archivo ? 'PDF' : 'Manual'}</small></span><span class="insignia">${cantidad(material.cantidad, 'pregunta', 'preguntas')}</span></div>
        <h3>${e(material.titulo)}</h3><p class="archivo-nombre">${e(material.nombre_archivo ?? 'Creado en EvaluaEIC')}</p>
        <p class="texto-suave texto-pequeno">${e(material.curso ?? 'Sin curso')}${Number(material.compartido) ? ` · Compartido por ${e(material.autor)}` : ''}</p>
        <div class="pie-tarjeta"><span>${material.nombre_archivo ? cantidad(material.paginas, 'página', 'páginas') : ''}</span><span>${seleccionada ? 'Cerrar material' : 'Abrir preguntas'} ${i('flecha')}</span></div>
      </button>
      <div class="acciones-tarjeta"><span>${fecha(material.creado_en)}</span>${eliminar}</div>
    </article>`;
  }
  async function abrirMaterial(id,forzar=false) {
    materialActivo=materialActivo===id&&!forzar?null:id;
    await cargarMateriales();
    const panel=document.getElementById('detalle-material'); panel.hidden=!materialActivo;
    if (!materialActivo) return;
    const d=await api(`material?id=${id}`);
    if (materialActivo !== id) return;
    const gestiona = d.documento.puede_gestionar;
    const manual = d.documento.nombre_archivo === null;
    const ajustes = gestiona ? accion('curso-material', id, 'Curso y visibilidad') : accion('copiar-material', id, 'Guardar copia privada');
    panel.innerHTML=`<div class="cabecera-detalle"><div><span class="sobrelinea">MATERIAL DE CÁTEDRA</span><h2>${e(d.documento.titulo)}</h2><p>${manual ? 'Manual' : cantidad(d.documento.paginas, 'página', 'páginas')} · ${cantidad(d.preguntas.length,'pregunta','preguntas')}</p></div><div class="grupo-botones">${manual ? '' : `<a href="${ruta(`/archivo/${id}`)}" target="_blank" rel="noopener" class="boton suave pequeno">Ver PDF</a>`}${ajustes}${manual && gestiona ? accion('editar-manual', id, 'Editar examen') : ''}<button class="boton primario pequeno" data-accion="lanzar" data-id="${id}">${i('qr')} ${gestiona ? 'Crear evaluación' : 'Usar examen'}</button><button class="boton-icono" data-accion="cerrar-material" aria-label="Cerrar material">${i('cerrar')}</button></div></div><nav class="pestanas" aria-label="Contenido del material">${[['preguntas','documento','Preguntas'],['participantes','usuarios','Participantes'],['ranking','trofeo','Ranking y resultados']].map(([clave,icono,titulo])=>`<button class="${pestana===clave?'activa':''}" data-accion="pestana" data-id="${clave}">${i(icono)} ${titulo}</button>`).join('')}</nav><div class="cuerpo-detalle" id="contenido-material"></div>`;
    const contenido=document.getElementById('contenido-material');
    if(pestana==='preguntas') contenido.innerHTML=d.preguntas.length ? d.preguntas.map((p,n)=>`<article class="pregunta-doc"><div class="fila-entre"><span class="sobrelinea">PREGUNTA ${n+1}</span>${gestiona ? `<button class="boton-icono peligro" data-accion="eliminar-pregunta" data-id="${p.id}" aria-label="Eliminar pregunta ${n+1}">${i('papelera')}</button>` : ''}</div><h3>${e(p.enunciado)}</h3>${p.tipo === 'completacion' ? `<p class="opcion-doc correcta"><strong>Respuestas aceptadas</strong> ${e(p.respuestas_aceptadas.join(' / '))}</p>` : `<div class="opciones-doc">${p.opciones.map((o,k)=>`<div class="opcion-doc ${Number(p.correcta)===k?'correcta':''}"><b>${'ABCD'[k]}</b><span>${e(o)}</span>${Number(p.correcta)===k?i('ok'):''}</div>`).join('')}</div>`}${p.explicacion ? `<p class="explicacion">${i('ia')} ${e(p.explicacion)}</p>` : ''}</article>`).join('') : vacio('Este material no tiene preguntas','Carga otro material para preparar una evaluación.');
    else if(!d.salas.length) contenido.innerHTML=vacio('Sin evaluaciones','Crea una sala para este material.','usuarios');
    else {
      contenido.innerHTML=`<label class="selector-historial">Evaluación<select id="sala-material">${d.salas.map(s=>`<option value="${e(s.codigo)}">${e(s.codigo)} · ${fecha(s.creado_en)}</option>`).join('')}</select></label><div id="resultado-material"></div>`;
      const selector=document.getElementById('sala-material');
      const mostrar=async()=>{ try {const s=await api(`estado?codigo=${selector.value}`);const destino=document.getElementById('resultado-material');if(!destino)return;destino.innerHTML=`<div class="fila-entre margen-inferior"><span class="insignia">${s.fase==='finalizada'?'Evaluación finalizada':s.fase==='espera'?'Sala en espera':'Evaluación en curso'}</span>${accion('ver-sala',s.codigo,`${i('qr')} Abrir sala`)}</div>${pestana==='ranking'?rankingSala(s):listaParticipantes(s.ranking)}`;}catch(error){aviso(error.message,true);} };
      selector.addEventListener('change',mostrar); await mostrar();
    }
  }
  function listaParticipantes(filas) {
    return filas.length ? `<div class="lista-participantes">${filas.map(p=>`<div class="participante-fila">${avatar(p.avatar)}<strong>${e(p.nombre)}</strong>${etiquetaEquipo(p.equipo)}<span class="estado-conexion"><span class="punto ${p.conectado?'conectado':''}"></span>${p.conectado?'Conectado':'Desconectado'}</span></div>`).join('')}</div>` : vacio('Esperando participantes','','usuarios');
  }
  async function historial(destino) {
    const {salas}=await api('salas');
    destino.innerHTML=salas.length ? `<div class="tabla-contenedor"><table><thead><tr><th>Material</th><th>Sala</th><th>Fecha</th><th>Participantes</th><th>Estado</th><th></th></tr></thead><tbody>${salas.map(s=>`<tr><td><strong>${e(s.titulo)}</strong><small class="bloque texto-suave">${e(s.docente)}</small>${etiquetaEtapa(s)}</td><td><code>${e(s.codigo)}</code><small class="bloque texto-suave">${s.cantidad_equipos?`${s.cantidad_equipos} equipos`:'Individual'}</small></td><td>${fecha(s.creado_en)}</td><td>${s.participantes}</td><td><span class="insignia">${s.fase==='espera'?'En espera':s.fase==='finalizada'?'Finalizada':'En curso'}</span></td><td><div class="grupo-botones">${accion('ver-sala',s.codigo,'Ver sala y ranking')}${s.fase === 'finalizada' ? enlacesInforme(s.codigo, null, etapaSala(s)) + accionesEtapas(s) : ''}</div></td></tr>`).join('')}</tbody></table></div>` : vacio('Aún no hay evaluaciones','Las salas que crees aparecerán aquí.','trofeo');
  }
  function mostrarCuenta(destino) {
    destino.innerHTML=`<div class="titulo-seccion"><h2>Mi cuenta</h2></div>
      <form class="formulario formulario-usuario formulario-cuenta" method="post">
        <label>Usuario<input name="usuario" value="${e(datos.usuario.usuario)}" required minlength="3" maxlength="40" autocomplete="username" autocapitalize="none" spellcheck="false"></label>
        <label>Contraseña actual<input name="clave_actual" type="password" required autocomplete="current-password"></label>
        <label>Nueva contraseña <small class="texto-suave">Opcional · mínimo 10 caracteres</small><input name="clave_nueva" type="password" minlength="10" maxlength="72" autocomplete="new-password"></label>
        <label>Confirmar nueva contraseña<input name="confirmar_clave" type="password" minlength="10" maxlength="72" autocomplete="new-password"></label>
        <div class="acciones-formulario"><button class="boton primario" type="submit">${i('ok')} Guardar cambios</button></div>
      </form>`;
    const formulario=destino.querySelector('form');
    formulario.addEventListener('submit',async evento=>{
      evento.preventDefault();
      const boton=formulario.querySelector('[type="submit"]');
      boton.disabled=true;
      try {
        const resultado=await api('actualizar-cuenta',Object.fromEntries(new FormData(formulario)));
        datos.usuario.usuario=resultado.usuario;
        formulario.elements.usuario.defaultValue=resultado.usuario;
        formulario.reset();
        aviso(resultado.mensaje);
      } catch(error){aviso(error.message,true);}
      finally {boton.disabled=false;}
    });
  }
  function accionesUsuario(u) {
    if(Number(u.id)===Number(datos.usuario.id)) return '<span class="texto-suave">Tu cuenta</span>';
    return `<div class="grupo-botones acciones-usuario">${accion('estado-usuario',u.id,u.estado==='activo'?'Desactivar':'Activar')}
      <button type="button" class="boton-icono peligro" data-accion="eliminar-usuario" data-id="${u.id}" data-usuario="${e(u.usuario)}" title="Eliminar usuario" aria-label="Eliminar usuario ${e(u.usuario)}">${i('papelera')}</button></div>`;
  }
  async function mostrarAdmin(nuevaSeccion=seccion) {
    seccion=nuevaSeccion; document.querySelectorAll('[data-admin]').forEach(b=>b.classList.toggle('activa',b.dataset.admin===seccion));
    const destino=document.getElementById('seccion-admin'); destino.innerHTML='<p class="texto-suave">Cargando…</p>';
    if(seccion==='documentos') {
      await cargarMateriales();
      if(seccion!==nuevaSeccion)return;
      destino.innerHTML=`<div class="titulo-seccion"><div><h2>Materiales institucionales</h2></div><span class="insignia">${cantidad(materiales.length,'documento','documentos')}</span></div>`+(materiales.length?`<div class="tabla-contenedor"><table><thead><tr><th>Documento</th><th>Curso</th><th>Subido por</th><th>Fecha</th><th>Preguntas</th><th></th></tr></thead><tbody>${materiales.map(d=>`<tr><td><div class="nombre-avatar"><span class="icono-pdf mini">${i('documento')}</span><div><strong>${e(d.titulo)}</strong>${d.nombre_archivo ? `<a class="bloque enlace-suave" href="${ruta(`/archivo/${d.id}`)}" target="_blank" rel="noopener">${e(d.nombre_archivo)}</a>` : '<small class="bloque texto-suave">Manual</small>'}</div></div></td><td>${e(d.curso ?? 'Sin curso')}${Number(d.compartido) ? '<small class="bloque texto-suave">Compartido</small>' : ''}</td><td>${e(d.autor)}</td><td>${fecha(d.creado_en)}</td><td>${d.cantidad}</td><td><div class="grupo-botones">${accion('curso-material', d.id, 'Curso y visibilidad')}<button class="boton-icono peligro" data-accion="eliminar-material" data-id="${d.id}" aria-label="Eliminar documento">${i('papelera')}</button></div></td></tr>`).join('')}</tbody></table></div>`:vacio('Sin documentos'));
    } else if(seccion==='usuarios') {
      const {usuarios}=await api('usuarios');
      if(seccion!==nuevaSeccion)return;
      destino.innerHTML=`<div class="titulo-seccion"><h2>Usuarios institucionales</h2><span class="insignia">${cantidad(usuarios.length,'cuenta','cuentas')}</span></div>
        <form id="crear-usuario" class="formulario formulario-usuario">
          <label>Nombre completo<input name="nombre" required minlength="2" maxlength="120" placeholder="Nombre y apellido" autocomplete="name"></label>
          <label>Usuario<input name="usuario" type="text" required minlength="3" maxlength="40" placeholder="Ej. juan.perez" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
          <label>Contraseña<input name="clave" type="password" minlength="10" maxlength="72" required autocomplete="new-password" placeholder="Mínimo 10 caracteres"></label>
          <label>Rol<select name="rol"><option value="docente">Docente / supervisor</option><option value="admin">Administrador</option></select></label>
          <div class="acciones-formulario"><button class="boton primario" type="submit">${i('mas')} Crear usuario</button></div>
        </form>
        <div class="tabla-contenedor"><table><thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>${usuarios.map(u=>`<tr><td><strong>${e(u.nombre)}</strong></td><td>${e(u.usuario)}</td><td>${u.rol==='admin'?'Administrador':'Docente / supervisor'}</td><td><span class="insignia ${u.estado==='activo'?'verde':''}">${u.estado==='activo'?'Activo':'Inactivo'}</span></td><td>${accionesUsuario(u)}</td></tr>`).join('')}</tbody></table></div>`;
      document.getElementById('crear-usuario').addEventListener('submit',async evento=>{evento.preventDefault();const b=evento.currentTarget.querySelector('[type="submit"]');b.disabled=true;try{const r=await api('crear-usuario',Object.fromEntries(new FormData(evento.currentTarget)));aviso(r.mensaje);if(seccion==='usuarios')await mostrarAdmin();}catch(error){aviso(error.message,true);b.disabled=false;}});
    } else if(seccion==='evaluaciones') {destino.innerHTML='<div class="titulo-seccion"><div><h2>Historial de evaluaciones</h2></div></div><div id="historial-admin"></div>';await historial(document.getElementById('historial-admin'));}
    else if(seccion==='cuenta') mostrarCuenta(destino);
    else {
      const {motores}=await api('motores');
      if(seccion!==nuevaSeccion)return;
      const motor=motores[0];
      destino.innerHTML=`<div class="titulo-seccion"><h2>Inteligencia artificial</h2><span class="insignia ${motor.configurado?'verde':''}">${motor.configurado?'Configurada':'Pendiente de configuración'}</span></div>
        <article class="configuracion-openrouter"><div class="titulo-con-icono"><span class="icono-titulo">${i('ia')}</span><h3>OpenRouter</h3></div>
          <dl class="datos-openrouter"><div><dt>Modelo</dt><dd>${e(motor.modelo||'Sin configurar')}</dd></div><div><dt>Clave API</dt><dd>${motor.clave_configurada?'Configurada':'Sin configurar'}</dd></div></dl>
          <p class="texto-suave texto-pequeno">La clave y el modelo se configuran en el archivo privado configuracion/servidor.php.</p>
          <div class="acciones-openrouter"><button class="boton primario" data-accion="probar-motor" data-id="openrouter" ${motor.configurado?'':'disabled'}>${i('ia')} Probar conexión</button><span class="texto-suave texto-pequeno">Realiza una generación breve de prueba.</span></div>
          <p id="prueba-openrouter" class="resultado-prueba" role="status"></p>
        </article>`;
    }
  }
  function camposCurso() {
    return `<label>Curso<select name="curso" required>
        <option value="">Selecciona un curso</option>${opcionesCursos()}<option value="nuevo">+ Agregar curso</option>
      </select></label>
      <label data-curso-nuevo hidden>Nombre del curso<input name="curso_nuevo" minlength="2" maxlength="120" disabled></label>
      <label class="campo-compartir"><input type="checkbox" name="compartido" value="1"> Compartir con otros docentes</label>`;
  }
  function prepararCamposCurso(formulario, cursoId, compartido, permiteSinCurso = false) {
    const {curso, curso_nuevo, compartido: casilla} = formulario.elements;
    if (permiteSinCurso) curso.options[0].textContent = 'Sin curso';
    curso.required = !permiteSinCurso;
    curso.value = [...curso.options].some(opcion => opcion.value === String(cursoId)) ? cursoId : '';
    casilla.checked = Boolean(Number(compartido));
    const actualizar = () => {
      const nuevo = curso.value === 'nuevo';
      curso_nuevo.closest('label').hidden = !nuevo;
      curso_nuevo.required = nuevo;
      curso_nuevo.disabled = !nuevo;
      casilla.disabled = !curso.value;
      if (casilla.disabled) casilla.checked = false;
    };
    curso.addEventListener('change', actualizar);
    actualizar();
  }
  async function guardarCamposCurso(formulario) {
    const {curso, curso_nuevo, compartido} = formulario.elements;
    const compartir = compartido.checked ? 1 : 0;
    if (curso.value !== 'nuevo') return {curso_id: curso.value, compartido: compartir};
    const resultado = await api('crear-curso', {nombre: curso_nuevo.value});
    if (!cursos.some(item => Number(item.id) === Number(resultado.curso.id))) cursos.push(resultado.curso);
    if (![...curso.options].some(opcion => opcion.value === String(resultado.curso.id))) {
      curso.add(new Option(resultado.curso.nombre, resultado.curso.id), curso.options[curso.options.length - 1]);
    }
    curso.value = resultado.curso.id;
    curso.dispatchEvent(new Event('change'));
    return {curso_id: resultado.curso.id, compartido: compartir};
  }
  function editarCurso(id) {
    const material = materiales.find(item => Number(item.id) === id);
    abrirDialogo(`${cabeceraDialogo('BIBLIOTECA DE EXÁMENES', 'Curso y visibilidad')}
      <form id="formulario-curso-material" class="formulario">
        ${camposCurso()}
        <div class="acciones-dialogo"><button class="boton suave" type="button" data-cerrar>Cancelar</button><button class="boton primario" type="submit">Guardar cambios</button></div>
      </form>`, 'dialogo-configuracion');
    const formulario = document.getElementById('formulario-curso-material');
    prepararCamposCurso(formulario, material.curso_id, material.compartido, true);
    formulario.addEventListener('submit', async evento => {
      evento.preventDefault();
      const boton = formulario.querySelector('[type="submit"]');
      boton.disabled = true;
      try {
        const cambios = await guardarCamposCurso(formulario);
        const resultado = await api('actualizar-curso-material', {id, ...cambios});
        if (formulario.isConnected && formulario.closest('dialog').open) cerrarDialogo();
        aviso(resultado.mensaje);
        if (esAdmin && seccion === 'documentos') await mostrarAdmin();
        else if (!esAdmin && materialActivo === id) {
          const filtro = document.getElementById('filtro-cursos');
          if (filtro.value) filtro.value = '';
          await abrirMaterial(id, true);
        }
        else if (!esAdmin) await cargarMateriales();
      } catch (error) {
        aviso(error.message, true);
        boton.disabled = false;
      }
    });
  }
  async function abrirCarga() {
    const numeroSolicitud=++secuenciaCarga;
    await cargarMateriales();
    if (numeroSolicitud !== secuenciaCarga) return;
    borrador=null;preguntasRevision=[];
    abrirDialogo(`${cabeceraDialogo('MATERIAL DE CÁTEDRA','Cargar PDF')}
      <div class="pasos-carga"><span class="actual">1. Documento</span><span>2. Preparación</span><span>3. Revisión</span></div>
      <form id="formulario-carga" class="formulario">
        ${camposCurso()}
        <label class="zona-archivo" id="zona-archivo">${i('subir')}<strong>Selecciona o arrastra un PDF</strong><span id="nombre-pdf">Hasta 12 MB</span><input id="archivo-pdf" type="file" name="archivo" accept="application/pdf,.pdf" required></label>
        <label>Título<input name="titulo" id="titulo-material" required minlength="3" maxlength="200" placeholder="Nombre del material"></label>
        <label>Cantidad de preguntas<input name="cantidad" id="cantidad-preguntas" type="number" value="10" min="1" max="30" step="1" required></label>
        <p class="nota-motor">El texto del documento se enviará a OpenRouter.</p><p id="error-carga" class="mensaje-error" hidden role="alert"></p>
        <div class="acciones-dialogo"><button class="boton suave" type="button" data-cerrar>Cancelar</button><button class="boton primario" type="submit">${i('ia')} Preparar preguntas</button></div>
      </form>`,'dialogo-carga');
    manejarCierre(()=>{secuenciaCarga++;});
    prepararCamposCurso(document.getElementById('formulario-carga'), document.getElementById('filtro-cursos').value, false);
    const archivo=document.getElementById('archivo-pdf');
    archivo.addEventListener('change',()=>{if(archivo.files[0]) {document.getElementById('nombre-pdf').textContent=archivo.files[0].name;document.getElementById('titulo-material').value=archivo.files[0].name.replace(/\.pdf$/i,'').replace(/[_-]/g,' ');}});
    const zona=document.getElementById('zona-archivo');
    zona.addEventListener('dragover',evento=>{evento.preventDefault();zona.classList.add('arrastrando');});
    zona.addEventListener('dragleave',()=>zona.classList.remove('arrastrando'));
    zona.addEventListener('drop',evento=>{evento.preventDefault();zona.classList.remove('arrastrando');if(evento.dataTransfer.files.length){archivo.files=evento.dataTransfer.files;archivo.dispatchEvent(new Event('change'));}});
    document.getElementById('formulario-carga').addEventListener('submit',async evento=>{
      evento.preventDefault(); const formulario=evento.currentTarget, boton=formulario.querySelector('[type="submit"]'), error=document.getElementById('error-carga');
      boton.disabled=true;error.hidden=true; const contenidoOriginal=boton.innerHTML;boton.innerHTML='<span class="cargando"></span> Leyendo el PDF y preparando preguntas…';
      const carga=new FormData(formulario);formulario.classList.add('procesando');document.querySelectorAll('.pasos-carga span').forEach((s,n)=>s.classList.toggle('actual',n===1));
      // No se muestra un porcentaje ficticio: la operación dura lo que necesite el proveedor.
      try {
        const curso = await guardarCamposCurso(formulario);
        if (numeroSolicitud !== secuenciaCarga) return;
        carga.delete('curso');
        carga.delete('curso_nuevo');
        carga.set('curso_id', curso.curso_id);
        carga.set('compartido', curso.compartido);
        const preparado = await api('preparar-material', carga);
        if (numeroSolicitud !== secuenciaCarga || !document.getElementById('dialogo').open) {
          await api('descartar-borrador', {token: preparado.token});
          return;
        }
        borrador = preparado;
        preguntasRevision = borrador.preguntas;
        mostrarRevision();
      }
      catch(fallo){if(error.isConnected){error.textContent=fallo.message;error.hidden=false;boton.disabled=false;boton.innerHTML=contenidoOriginal;formulario.classList.remove('procesando');document.querySelectorAll('.pasos-carga span').forEach((s,n)=>s.classList.toggle('actual',n===0));}else aviso(fallo.message,true);}
    });
  }
  function leerRevision() {
    return [...document.querySelectorAll('.pregunta-revision')].map(tarjeta=>({enunciado:tarjeta.querySelector('[data-enunciado]').value.trim(),opciones:[...tarjeta.querySelectorAll('[data-opcion]')].map(x=>x.value.trim()),correcta:Number(tarjeta.querySelector('input[type="radio"]:checked')?.value??0),explicacion:tarjeta.querySelector('[data-explicacion]').value.trim()}));
  }
  function mostrarRevision() {
    manejarCierre(null);
    const resumen = cantidad(preguntasRevision.length, borrador.analisis?'pregunta generada':'pregunta para revisar', borrador.analisis?'preguntas generadas':'preguntas para revisar');
    abrirDialogo(`${cabeceraDialogo('MATERIAL DE CÁTEDRA','Revisar preguntas')}
      <div class="pasos-carga"><span>1. Documento</span><span>2. Preparación</span><span class="actual">3. Revisión</span></div>
      <p class="nota resumen-revision" role="status"><strong>${resumen}</strong><span>Revisa, edita o elimina preguntas antes de guardar.</span></p>
      ${borrador.analisis?.resumido?'<p class="nota-motor">El documento se analizó por bloques. Comprueba que las preguntas cubran los temas que deseas evaluar.</p>':''}
      <form id="formulario-revision" class="formulario">
        <label>Título<input name="titulo" value="${e(borrador.titulo)}" minlength="3" maxlength="200" required></label>
        <div id="preguntas-revision">${preguntasRevision.length?preguntasRevision.map((p,n)=>`
          <article class="pregunta-revision">
            <div class="fila-entre"><span class="sobrelinea">PREGUNTA ${n+1}</span><button type="button" class="boton-icono peligro" data-quitar="${n}" aria-label="Descartar pregunta ${n+1}">${i('papelera')}</button></div>
            <label>Enunciado<textarea data-enunciado minlength="5" maxlength="3000" required rows="2">${e(p.enunciado)}</textarea></label>
            <div class="opciones-revision">${p.opciones.map((o,k)=>`
              <label class="opcion-revision"><input type="radio" name="correcta-${n}" value="${k}" ${Number(p.correcta)===k?'checked':''} aria-label="Marcar ${'ABCD'[k]} como correcta"><b>${'ABCD'[k]}</b><textarea data-opcion="${k}" maxlength="1500" required rows="2" aria-label="Opción ${'ABCD'[k]}">${e(o)}</textarea></label>`).join('')}</div>
            <details class="explicacion-revision"><summary>Explicación</summary><label>Justificación de la respuesta<textarea data-explicacion required maxlength="3000" rows="2">${e(p.explicacion)}</textarea></label></details>
          </article>`).join(''):vacio('No quedan preguntas','Cierra esta ventana para preparar un nuevo material.')}</div>
        <div class="pie-revision"><span>${cantidad(preguntasRevision.length,'pregunta','preguntas')} · ${cantidad(borrador.paginas,'página','páginas')}</span><button type="submit" class="boton primario" ${preguntasRevision.length?'':'disabled'}>${i('ok')} Guardar material</button></div>
      </form>`,'dialogo-revision');
    manejarCierre(()=>{if(borrador){const token=borrador.token;borrador=null;api('descartar-borrador',{token}).catch(()=>{});}});
    document.querySelectorAll('[data-quitar]').forEach(b=>b.addEventListener('click',()=>{preguntasRevision=leerRevision();borrador.titulo=document.querySelector('#formulario-revision [name="titulo"]').value;preguntasRevision.splice(Number(b.dataset.quitar),1);mostrarRevision();}));
    document.getElementById('formulario-revision').addEventListener('submit',async evento=>{evento.preventDefault();const boton=evento.currentTarget.querySelector('[type="submit"]');boton.disabled=true;try{const r=await api('guardar-material',{token:borrador.token,titulo:evento.currentTarget.elements.titulo.value,preguntas:leerRevision(),curso_id:borrador.curso_id,compartido:borrador.compartido});borrador=null;cerrarDialogo();aviso(r.mensaje);await cargarMateriales();}catch(error){aviso(error.message,true);boton.disabled=false;}});
  }
  function camposPreguntaManual(pregunta, indice) {
    const tipo = pregunta.tipo ?? 'opcion_multiple';
    const opciones = pregunta.opciones ?? [];
    const tipos = [
      ['opcion_multiple', 'Opción múltiple'],
      ['verdadero_falso', 'Verdadero o falso'],
      ['completacion', 'Completación'],
    ];
    return `<article class="pregunta-revision pregunta-manual">
      <div class="fila-entre"><h3 class="titulo-pregunta-manual">Pregunta ${indice + 1}</h3><button type="button" class="boton-icono peligro" data-quitar-manual aria-label="Eliminar pregunta ${indice + 1}">${i('papelera')}</button></div>
      <label>Tipo de pregunta<select data-tipo-manual>${tipos.map(([valor, nombre]) =>
        `<option value="${valor}" ${tipo === valor ? 'selected' : ''}>${nombre}</option>`).join('')}</select></label>
      <label>Enunciado<textarea data-enunciado required minlength="5" maxlength="3000" rows="2">${e(pregunta.enunciado ?? '')}</textarea></label>
      <fieldset data-campos-tipo="opcion_multiple">
        <legend>Opciones y respuesta correcta</legend>
        <div class="opciones-revision">${Array.from({length: 4}, (_, n) => `<label class="opcion-revision">
          <input type="radio" name="manual-correcta-${indice}" value="${n}" ${Number(pregunta.correcta ?? 0) === n ? 'checked' : ''} aria-label="Marcar ${'ABCD'[n]} como correcta">
          <b>${'ABCD'[n]}</b><textarea data-opcion maxlength="1500" rows="2" aria-label="Opción ${'ABCD'[n]}" required>${e(opciones[n] ?? '')}</textarea>
        </label>`).join('')}</div>
      </fieldset>
      <fieldset data-campos-tipo="verdadero_falso">
        <label>Respuesta correcta<select data-verdadero-falso>
          <option value="0" ${Number(pregunta.correcta) === 0 ? 'selected' : ''}>Verdadero</option>
          <option value="1" ${Number(pregunta.correcta) === 1 ? 'selected' : ''}>Falso</option>
        </select></label>
      </fieldset>
      <fieldset data-campos-tipo="completacion">
        <label>Respuestas aceptadas<textarea data-respuestas-aceptadas rows="3" maxlength="1510" placeholder="Una variante por línea" required>${e((pregunta.respuestas_aceptadas ?? []).join('\n'))}</textarea></label>
        <small class="texto-suave">Hasta 10 variantes. La calificación ignora mayúsculas y espacios adicionales.</small>
      </fieldset>
      <label>Explicación <small class="texto-suave">(opcional)</small><textarea data-explicacion maxlength="3000" rows="2">${e(pregunta.explicacion ?? '')}</textarea></label>
    </article>`;
  }
  function leerPreguntasManuales(contenedor) {
    return [...contenedor.querySelectorAll('.pregunta-manual')].map(tarjeta => {
      const tipo = tarjeta.querySelector('[data-tipo-manual]').value;
      const pregunta = {tipo, enunciado: tarjeta.querySelector('[data-enunciado]').value, explicacion: tarjeta.querySelector('[data-explicacion]').value};
      if (tipo === 'completacion') {
        pregunta.respuestas_aceptadas = tarjeta.querySelector('[data-respuestas-aceptadas]').value.split(/\r?\n/).map(texto => texto.trim()).filter(Boolean);
      } else if (tipo === 'verdadero_falso') {
        pregunta.correcta = Number(tarjeta.querySelector('[data-verdadero-falso]').value);
      } else {
        pregunta.correcta = Number(tarjeta.querySelector('input[type="radio"]:checked').value);
        pregunta.opciones = [...tarjeta.querySelectorAll('[data-opcion]')].map(opcion => opcion.value);
      }
      return pregunta;
    });
  }
  async function abrirEditorManual(id = null) {
    await cargarMateriales();
    const detalle = id === null ? null : await api(`material?id=${id}`);
    const material = detalle?.documento;
    const token = id === null ? Array.from(crypto.getRandomValues(new Uint8Array(24)), byte => byte.toString(16).padStart(2, '0')).join('') : null;
    abrirDialogo(`${cabeceraDialogo('BIBLIOTECA DE EXÁMENES', id === null ? 'Crear examen manual' : 'Editar examen')}
      <form id="formulario-examen-manual" class="formulario">
        <label>Título<input name="titulo" value="${e(material?.titulo ?? '')}" minlength="3" maxlength="200" required></label>
        ${camposCurso()}
        <div id="preguntas-manuales"></div>
        <p class="mensaje-error" data-error-manual hidden role="alert"></p>
        <div class="pie-revision">
          <button type="button" class="boton suave" data-agregar-manual>${i('mas')} Añadir pregunta</button>
          <button type="submit" class="boton primario">${i('ok')} Guardar examen</button>
        </div>
      </form>`, 'dialogo-revision');
    const formulario = document.getElementById('formulario-examen-manual');
    const contenedor = document.getElementById('preguntas-manuales');
    const agregar = formulario.querySelector('[data-agregar-manual]');
    const botonGuardar = formulario.querySelector('[type="submit"]');
    const error = formulario.querySelector('[data-error-manual]');
    const curso = material ? material.curso_id : document.getElementById('filtro-cursos')?.value;
    prepararCamposCurso(formulario, curso, material?.compartido, Boolean(material && material.curso_id === null));
    let siguiente = 0;
    const actualizarNumeros = () => {
      const tarjetas = [...contenedor.children];
      tarjetas.forEach((tarjeta, n) => {
        tarjeta.querySelector('.titulo-pregunta-manual').textContent = `Pregunta ${n + 1}`;
        tarjeta.querySelector('[data-quitar-manual]').setAttribute('aria-label', `Eliminar pregunta ${n + 1}`);
      });
      agregar.disabled = tarjetas.length >= 30;
      botonGuardar.disabled = tarjetas.length === 0;
    };
    const insertar = (pregunta = {}) => {
      contenedor.insertAdjacentHTML('beforeend', camposPreguntaManual(pregunta, siguiente++));
      const tarjeta = contenedor.lastElementChild;
      const tipo = tarjeta.querySelector('[data-tipo-manual]');
      const actualizarTipo = () => tarjeta.querySelectorAll('[data-campos-tipo]').forEach(campo => {
        campo.hidden = campo.disabled = campo.dataset.camposTipo !== tipo.value;
      });
      tipo.addEventListener('change', actualizarTipo);
      actualizarTipo();
      tarjeta.querySelector('[data-quitar-manual]').addEventListener('click', () => {
        tarjeta.remove();
        actualizarNumeros();
      });
      actualizarNumeros();
      return tarjeta;
    };
    for (const pregunta of (detalle?.preguntas.length ? detalle.preguntas : [{}])) insertar(pregunta);
    agregar.addEventListener('click', () => insertar().querySelector('[data-enunciado]').focus());
    formulario.addEventListener('submit', async evento => {
      evento.preventDefault();
      if (botonGuardar.disabled) return;
      botonGuardar.disabled = true;
      error.hidden = true;
      try {
        const titulo = formulario.elements.titulo.value;
        const preguntas = leerPreguntasManuales(contenedor);
        const curso = await guardarCamposCurso(formulario);
        if (!formulario.isConnected || !formulario.closest('dialog').open) return;
        const datos = id === null ? {token} : {id};
        const resultado = await api(id === null ? 'crear-examen-manual' : 'editar-examen-manual', {...datos, ...curso, titulo, preguntas});
        if (formulario.isConnected && formulario.closest('dialog').open) cerrarDialogo();
        aviso(resultado.mensaje);
        if (esAdmin) {
          if (seccion === 'documentos') await mostrarAdmin();
        } else {
          document.getElementById('filtro-cursos').value = '';
          pestana = 'preguntas';
          await abrirMaterial(resultado.id, true);
        }
      } catch (fallo) {
        if (!formulario.isConnected) return;
        error.textContent = fallo.message;
        error.hidden = false;
        botonGuardar.disabled = false;
      }
    });
  }
  function configurarSala(id) {
    const material=materiales.find(m=>Number(m.id)===id);
    abrirDialogo(`${cabeceraDialogo('EVALUACIÓN','Crear sala')}
      <div class="material-dialogo">${i('documento')}<strong>${e(material?.titulo||'Material de cátedra')}</strong></div>
      <form id="configurar-sala" class="formulario">
        <label>Aplicación<select name="entrada"><option value="0">Única</option><option value="1">Entrada y salida</option></select></label>
        <label>Modalidad<select name="modalidad"><option value="individual">Individual</option><option value="equipos">Por equipos</option></select></label>
        <label id="campo-equipos" hidden>Cantidad de equipos<select name="cantidad_equipos">
          ${Array.from({length:7},(_,indice)=>`<option value="${indice+2}">${indice+2} equipos</option>`).join('')}
        </select><small class="texto-suave">La sala reparte a los participantes al entrar.</small></label>
        <label>Tiempo por pregunta<select name="tiempo"><option value="15">15 segundos</option><option value="20" selected>20 segundos</option><option value="30">30 segundos</option></select></label>
        <div class="acciones-dialogo"><button class="boton suave" type="button" data-cerrar>Cancelar</button><button class="boton primario" type="submit">${i('qr')} Crear sala</button></div>
      </form>`,'dialogo-configuracion');
    const formulario=document.getElementById('configurar-sala');
    formulario.elements.modalidad.addEventListener('change',()=>{
      formulario.querySelector('#campo-equipos').hidden=formulario.elements.modalidad.value!=='equipos';
    });
    formulario.addEventListener('submit',async evento=>{
      evento.preventDefault();
      const boton=formulario.querySelector('[type="submit"]');
      boton.disabled=true;
      try {
        const opciones=Object.fromEntries(new FormData(formulario));
        const r=await api('crear-sala',{documento_id:id,...opciones});
        if(formulario.isConnected && formulario.closest('dialog').open)await abrirSala(r.codigo);
      } catch(error){aviso(error.message,true);boton.disabled=false;}
    });
  }
  async function abrirSala(codigo) {
    const numeroSala=++secuenciaSala;
    clearTimeout(salaTemporizador); manejarCierre(null);
    detenerPodio();
    musica(null);
    const url=datos.url?`${datos.url}/sala/${codigo}`:null;
    abrirDialogo(`${cabeceraDialogo('EVALUACIÓN','Acceso a la sala')}<div class="barra-sonido">${controlSonido()}</div><div id="sala-anfitrion"><p>Cargando…</p></div>`,'dialogo-sala');
    manejarCierre(()=>{secuenciaSala++;clearTimeout(salaTemporizador);detenerPodio();musica(null);});
    let ultimaVista='',ultimoIndice=-1,iniciando=false;
    async function actualizar() {
      if(numeroSala!==secuenciaSala || !document.getElementById('dialogo').open || !document.getElementById('sala-anfitrion'))return;
      try {
        const s=await api(`estado?codigo=${codigo}`),destino=document.getElementById('sala-anfitrion'); if(!destino || numeroSala!==secuenciaSala || !document.getElementById('dialogo').open)return;
        if(ultimaVista!==s.fase || ultimoIndice!==s.indice){
          musica(s.fase==='pregunta'?'pregunta':null);
          if(ultimaVista==='pregunta' && s.fase==='revelacion' && ultimoIndice===s.indice)efecto('tiempo');
        }
        if(s.fase==='espera') {
          if (!url) {
            destino.innerHTML='<p class="mensaje-error">Configura la dirección pública de EvaluaEIC para mostrar el enlace y el QR.</p>';
            return;
          }
          if(ultimaVista!=='espera')destino.innerHTML=`<div class="sala-acceso"><div class="sala-qr"><div class="qr-codigo">${qr(url)}</div><div class="codigo-sala"><span>Código</span><strong>${e(codigo)}</strong></div></div><div class="sala-informacion"><h3>${e(s.titulo)}</h3><div class="datos-sala"><span>${i('documento')} ${cantidad(s.total,'pregunta','preguntas')}</span><span>${i('reloj')} ${s.tiempo} s por pregunta</span></div><p id="contador-sala" class="contador-conectados"></p><div id="lista-sala"></div></div></div><div class="enlace-sala"><input value="${e(url)}" readonly aria-label="Enlace para participantes"><button id="copiar-enlace" class="boton suave pequeno">Copiar enlace</button></div><div class="acciones-sala"><a class="boton suave" href="${e(ruta(`/sala/${codigo}`))}" target="_blank" rel="noopener">Vista participante</a><button id="iniciar-actividad" class="boton primario">Iniciar evaluación ${i('flecha')}</button></div>`;
          const minimo=s.cantidad_equipos||1;
          const modalidad=s.cantidad_equipos?`${s.cantidad_equipos} equipos · `:'';
          const faltan=s.cantidad<minimo&&s.cantidad_equipos?` · Mínimo ${minimo} participantes`:'';
          destino.querySelector('#contador-sala').textContent=`${modalidad}${cantidad(s.conectados,'conectado','conectados')} · ${cantidad(s.cantidad,'inscrito','inscritos')}${faltan}`;
          destino.querySelector('#lista-sala').innerHTML=listaParticipantes(s.ranking);
          const iniciar=destino.querySelector('#iniciar-actividad');
          iniciar.disabled=iniciando||s.cantidad<minimo;
          iniciar.onclick=async()=>{
            prepararSonido();
            iniciando=true;
            iniciar.disabled=true;
            try {const r=await api('iniciar-sala',{codigo});aviso(r.mensaje);await actualizar();}
            catch(error){aviso(error.message,true);}
            finally {iniciando=false;iniciar.disabled=false;}
          };
          destino.querySelector('#copiar-enlace').onclick=async()=>{try{await navigator.clipboard.writeText(url);aviso('Enlace copiado.');}catch{destino.querySelector('.enlace-sala input').select();aviso('Selecciona y copia el enlace.');}};
        } else if(s.fase==='finalizada') {
          const mostrarResultados=()=>{
            destino.innerHTML=`<div class="centrado"><span class="insignia verde">${s.etapa ? 'Examen de ' + e(s.etapa.toLowerCase()) : 'Evaluación'} finalizado</span><h3>${e(s.titulo)}</h3></div>${rankingSala(s)}`;
          };
          if (ultimaVista && ultimaVista !== 'finalizada') detenerPodio=presentarPodio(destino,s.ranking,mostrarResultados,Boolean(s.cantidad_equipos));
          else mostrarResultados();
          if(!esAdmin)historial(document.getElementById('historial-docente')).catch(()=>{});
        } else {
          destino.innerHTML=`<div class="fila-entre"><span class="insignia verde">Actividad en vivo</span><span>${s.indice+1} de ${s.total}</span></div><h3>${e(s.titulo)}</h3><div class="resumen-vivo"><span id="tiempo-anfitrion">${Math.max(0,Math.ceil(s.limite-s.ahora))}s</span><strong>${s.fase==='preparacion'?'Comenzamos en un momento':s.fase==='revelacion'?'Revelando la respuesta correcta':e(s.pregunta.enunciado)}</strong></div><p class="centrado texto-suave">${s.respondieron??0} de ${s.cantidad} participantes respondieron</p>${resultadosEquipos(s)}${listaParticipantes(s.ranking)}`;
        }
        ultimaVista=s.fase;
        ultimoIndice=s.indice;
        if(s.fase!=='finalizada') {clearTimeout(salaTemporizador);salaTemporizador=setTimeout(actualizar,1500);}
      }catch(error){aviso(error.message,true);salaTemporizador=setTimeout(actualizar,3000);}
    }
    await actualizar();
  }
  async function abrirRespuestas(boton) {
    const codigo = boton.dataset.codigo;
    const participante = Number(boton.dataset.id);
    const dialogo = boton.closest('dialog');
    boton.disabled = true;
    try {
      const r = await api(`respuestas?codigo=${encodeURIComponent(codigo)}&participante=${participante}`);
      if (!boton.isConnected || (dialogo && !dialogo.open)) return;
      abrirDialogo(`${cabeceraDialogo('HISTORIAL DE RESPUESTAS', r.nombre)}
        <div class="grupo-botones margen-inferior" role="group" aria-label="Ficha individual">${enlacesInforme(codigo, participante, r.etapa)}</div>
        ${detalleRespuestas(r.preguntas)}
        <div class="acciones-dialogo">${accion('ver-sala', codigo, 'Volver a resultados')}</div>`, 'dialogo-sala');
    } finally {
      boton.disabled = false;
    }
  }
  async function abrirComparacion(codigo) {
    const {participantes} = await api(`comparacion?codigo=${encodeURIComponent(codigo)}`);
    const porcentaje = valor => valor === null ? 'Pendiente' : `${valor} %`;
    const diferencia = valor => valor === null ? '—' : `${valor > 0 ? '+' : ''}${valor} pp`;
    abrirDialogo(`${cabeceraDialogo('RESULTADOS', 'Comparación de entrada y salida')}
      <div class="grupo-botones margen-inferior" role="group" aria-label="Descargar comparación grupal">${enlacesComparacion(codigo)}</div>
      <div class="tabla-contenedor"><table><thead><tr><th>Participante</th><th>Entrada</th><th>Salida</th><th>Cambio</th><th>Comparación individual</th></tr></thead>
      <tbody>${participantes.map(persona => `<tr><td>${e(persona.nombre)}</td><td>${porcentaje(persona.entrada)}</td>
        <td>${porcentaje(persona.salida)}</td><td>${diferencia(persona.diferencia)}</td>
        <td><div class="grupo-botones">${enlacesComparacion(codigo, persona.id)}</div></td></tr>`).join('')}</tbody></table></div>`, 'dialogo-sala');
  }
  document.addEventListener('click',async evento=>{
    const admin=evento.target.closest('[data-admin]');if(admin){try{await mostrarAdmin(admin.dataset.admin);}catch(error){aviso(error.message,true);}return;}
    const b=evento.target.closest('[data-accion]');if(!b)return; const id=Number(b.dataset.id),nombre=b.dataset.accion;
    try {
      if(nombre==='material'){pestana='preguntas';await abrirMaterial(id);}
      else if(nombre==='cerrar-material')await abrirMaterial(materialActivo);
      else if(nombre==='pestana'){pestana=b.dataset.id;await abrirMaterial(materialActivo,true);}
      else if(nombre==='cargar')await abrirCarga();
      else if (nombre === 'crear-manual') await abrirEditorManual();
      else if (nombre === 'editar-manual') await abrirEditorManual(id);
      else if (nombre === 'curso-material') editarCurso(id);
      else if (nombre === 'copiar-material') {
        b.disabled = true;
        const resultado = await api('copiar-material', {id});
        aviso(resultado.mensaje);
        if (materialActivo === id) await abrirMaterial(resultado.id, true);
        else await cargarMateriales();
      }
      else if(nombre==='lanzar')configurarSala(id);
      else if(nombre==='ver-sala'){prepararSonido();await abrirSala(b.dataset.id);}
      else if (nombre === 'crear-salida') {
        b.disabled = true;
        const resultado = await api('crear-salida', {codigo: b.dataset.id});
        await abrirSala(resultado.codigo);
        if (esAdmin && seccion === 'evaluaciones') await historial(document.getElementById('historial-admin'));
        else if (!esAdmin) await historial(document.getElementById('historial-docente'));
        if (b.isConnected) b.disabled = false;
      }
      else if (nombre === 'ver-comparacion') await abrirComparacion(b.dataset.id);
      else if(nombre==='ver-respuestas')await abrirRespuestas(b);
      else if(nombre==='eliminar-material') {if(!await confirmar('Eliminar material','Se eliminarán el examen, sus preguntas y el PDF si lo tiene. El historial de evaluaciones se conservará.'))return;const r=await api(nombre,{id});aviso(r.mensaje);if(esAdmin)await mostrarAdmin();else{if(materialActivo===id){materialActivo=null;document.getElementById('detalle-material').hidden=true;}await cargarMateriales();}}
      else if(nombre==='eliminar-pregunta'){if(!await confirmar('Eliminar pregunta','Se quitará del material. Las salas ya creadas conservarán su copia.'))return;const r=await api(nombre,{id});aviso(r.mensaje);await abrirMaterial(materialActivo,true);}
      else if(nombre==='estado-usuario'||nombre==='eliminar-usuario'){
        if(nombre==='eliminar-usuario'&&!await confirmar('Eliminar usuario',`Se retirará el acceso de ${b.dataset.usuario} de forma definitiva. Sus documentos y el historial de evaluaciones se conservarán.`))return;
        b.disabled=true;
        const r=await api(nombre,{id});
        aviso(r.mensaje);
        if(seccion==='usuarios')await mostrarAdmin();
      }
      else if(nombre==='probar-motor'){b.disabled=true;const salida=document.getElementById('prueba-openrouter');salida.textContent='Comprobando clave y modelo…';try{const r=await api(nombre,{});salida.textContent=`${r.mensaje} ${r.milisegundos} ms.`;}catch(error){salida.textContent=error.message;}finally{b.disabled=false;}}
    }catch(error){aviso(error.message,true);b.disabled=false;}
  });
  if(esAdmin)mostrarAdmin().catch(error=>aviso(error.message,true));
  else {
    document.getElementById('abrir-carga').addEventListener('click', () => abrirCarga().catch(error => aviso(error.message, true)));
    document.getElementById('filtro-cursos').addEventListener('change', () => {
      materialActivo = null;
      document.getElementById('detalle-material').hidden = true;
      mostrarBiblioteca();
    });
    cargarMateriales().catch(error => aviso(error.message, true));
    historial(document.getElementById('historial-docente')).catch(error => aviso(error.message, true));
  }
})();
