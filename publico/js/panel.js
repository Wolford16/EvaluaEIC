'use strict';
(() => {
  const {api,escapar:e,icono:i,aviso,datos,abrirDialogo,cerrarDialogo,manejarCierre,confirmar,vacio,avatar,ranking,qr}=Evalua;
  let materiales=[], materialActivo=null, pestana='preguntas', seccion='documentos', borrador=null, preguntasRevision=[], salaTemporizador=null, secuenciaCarga=0, secuenciaSala=0;
  const esAdmin=datos.vista==='administracion';
  const fecha=valor=>new Date(valor.replace(' ','T')).toLocaleDateString('es-HN',{day:'2-digit',month:'short',year:'numeric'});
  const cantidad=(numero,singular,plural)=>`${numero} ${Number(numero)===1?singular:plural}`;
  const accion=(nombre,id,texto,clase='suave')=>`<button class="boton ${clase} pequeno" data-accion="${nombre}" data-id="${e(id)}">${texto}</button>`;
  document.getElementById('cerrar-sesion').addEventListener('click',async()=>{try { const r=await api('salir',{}); location.assign(r.destino); } catch(error){aviso(error.message,true);} });

  async function cargarMateriales() {
    ({documentos:materiales}=await api('materiales'));
    if(esAdmin) return;
    document.getElementById('cantidad-materiales').textContent=`${materiales.length} ${materiales.length===1?'documento':'documentos'}`;
    document.getElementById('lista-materiales').innerHTML=materiales.length ? materiales.map(d=>`<article class="tarjeta-material ${materialActivo===Number(d.id)?'seleccionada':''}"><button class="abrir-tarjeta" data-accion="material" data-id="${d.id}" aria-expanded="${materialActivo===Number(d.id)}"><div class="fila-tarjeta"><span class="icono-pdf">${i('documento')}<small>PDF</small></span><span class="insignia">${cantidad(d.cantidad,'pregunta','preguntas')}</span></div><h3>${e(d.titulo)}</h3><p class="archivo-nombre">${e(d.nombre_archivo)}</p><div class="pie-tarjeta"><span>${cantidad(d.paginas,'página','páginas')}</span><span>${materialActivo===Number(d.id)?'Cerrar material':'Abrir preguntas'} ${i('flecha')}</span></div></button><div class="acciones-tarjeta"><span>${fecha(d.creado_en)}</span><button class="boton-icono peligro" data-accion="eliminar-material" data-id="${d.id}" aria-label="Eliminar ${e(d.titulo)}" title="Eliminar documento">${i('papelera')}</button></div></article>`).join('') : `<div class="panel vacio-biblioteca">${vacio('Sin materiales','Carga un PDF para comenzar.','subir')}<button class="boton primario" data-accion="cargar">${i('subir')} Cargar PDF</button></div>`;
  }
  async function abrirMaterial(id,forzar=false) {
    materialActivo=materialActivo===id&&!forzar?null:id;
    await cargarMateriales();
    const panel=document.getElementById('detalle-material'); panel.hidden=!materialActivo;
    if (!materialActivo) return;
    const d=await api(`material?id=${id}`);
    panel.innerHTML=`<div class="cabecera-detalle"><div><span class="sobrelinea">MATERIAL DE CÁTEDRA</span><h2>${e(d.documento.titulo)}</h2><p>${cantidad(d.documento.paginas,'página','páginas')} · ${cantidad(d.preguntas.length,'pregunta','preguntas')}</p></div><div class="grupo-botones"><a href="/archivo/${id}" target="_blank" rel="noopener" class="boton suave pequeno">Ver PDF</a><button class="boton primario pequeno" data-accion="lanzar" data-id="${id}">${i('qr')} Crear evaluación</button><button class="boton-icono" data-accion="cerrar-material" aria-label="Cerrar material">${i('cerrar')}</button></div></div><nav class="pestanas" aria-label="Contenido del material">${[['preguntas','documento','Preguntas'],['participantes','usuarios','Participantes'],['ranking','trofeo','Ranking y resultados']].map(([clave,icono,titulo])=>`<button class="${pestana===clave?'activa':''}" data-accion="pestana" data-id="${clave}">${i(icono)} ${titulo}</button>`).join('')}</nav><div class="cuerpo-detalle" id="contenido-material"></div>`;
    const contenido=document.getElementById('contenido-material');
    if(pestana==='preguntas') contenido.innerHTML=d.preguntas.length ? d.preguntas.map((p,n)=>`<article class="pregunta-doc"><div class="fila-entre"><span class="sobrelinea">PREGUNTA ${n+1}</span><button class="boton-icono peligro" data-accion="eliminar-pregunta" data-id="${p.id}" aria-label="Eliminar pregunta ${n+1}">${i('papelera')}</button></div><h3>${e(p.enunciado)}</h3><div class="opciones-doc">${p.opciones.map((o,k)=>`<div class="opcion-doc ${Number(p.correcta)===k?'correcta':''}"><b>${'ABCD'[k]}</b><span>${e(o)}</span>${Number(p.correcta)===k?i('ok'):''}</div>`).join('')}</div><p class="explicacion">${i('ia')} ${e(p.explicacion)}</p></article>`).join('') : vacio('Este material no tiene preguntas','Carga otro material para preparar una evaluación.');
    else if(!d.salas.length) contenido.innerHTML=vacio('Sin evaluaciones','Crea una sala para este material.','usuarios');
    else {
      contenido.innerHTML=`<label class="selector-historial">Evaluación<select id="sala-material">${d.salas.map(s=>`<option value="${e(s.codigo)}">${e(s.codigo)} · ${fecha(s.creado_en)}</option>`).join('')}</select></label><div id="resultado-material"></div>`;
      const selector=document.getElementById('sala-material');
      const mostrar=async()=>{ try {const s=await api(`estado?codigo=${selector.value}`);const destino=document.getElementById('resultado-material');if(!destino)return;destino.innerHTML=`<div class="fila-entre margen-inferior"><span class="insignia">${s.fase==='finalizada'?'Evaluación finalizada':s.fase==='espera'?'Sala en espera':'Evaluación en curso'}</span>${accion('ver-sala',s.codigo,`${i('qr')} Abrir sala`)}</div>${pestana==='ranking'?ranking(s.ranking,s.total):listaParticipantes(s.ranking)}`;}catch(error){aviso(error.message,true);} };
      selector.addEventListener('change',mostrar); await mostrar();
    }
  }
  function listaParticipantes(filas) {
    return filas.length ? `<div class="lista-participantes">${filas.map(p=>`<div class="participante-fila">${avatar(p.avatar)}<strong>${e(p.nombre)}</strong><span class="estado-conexion"><span class="punto ${p.conectado?'conectado':''}"></span>${p.conectado?'Conectado':'Desconectado'}</span></div>`).join('')}</div>` : vacio('Esperando participantes','','usuarios');
  }
  async function historial(destino) {
    const {salas}=await api('salas');
    destino.innerHTML=salas.length ? `<div class="tabla-contenedor"><table><thead><tr><th>Material</th><th>Sala</th><th>Fecha</th><th>Participantes</th><th>Estado</th><th></th></tr></thead><tbody>${salas.map(s=>`<tr><td><strong>${e(s.titulo)}</strong><small class="bloque texto-suave">${e(s.docente)}</small></td><td><code>${e(s.codigo)}</code></td><td>${fecha(s.creado_en)}</td><td>${s.participantes}</td><td><span class="insignia">${s.fase==='espera'?'En espera':s.fase==='finalizada'?'Finalizada':'En curso'}</span></td><td>${accion('ver-sala',s.codigo,'Ver sala y ranking')}</td></tr>`).join('')}</tbody></table></div>` : vacio('Aún no hay evaluaciones','Las salas que crees aparecerán aquí.','trofeo');
  }
  async function mostrarAdmin(nuevaSeccion=seccion) {
    seccion=nuevaSeccion; document.querySelectorAll('[data-admin]').forEach(b=>b.classList.toggle('activa',b.dataset.admin===seccion));
    const destino=document.getElementById('seccion-admin'); destino.innerHTML='<p class="texto-suave">Cargando…</p>';
    if(seccion==='documentos') {
      await cargarMateriales();
      destino.innerHTML=`<div class="titulo-seccion"><div><h2>Materiales institucionales</h2></div><span class="insignia">${cantidad(materiales.length,'documento','documentos')}</span></div>`+(materiales.length?`<div class="tabla-contenedor"><table><thead><tr><th>Documento</th><th>Subido por</th><th>Fecha</th><th>Preguntas</th><th></th></tr></thead><tbody>${materiales.map(d=>`<tr><td><div class="nombre-avatar"><span class="icono-pdf mini">${i('documento')}</span><div><strong>${e(d.titulo)}</strong><a class="bloque enlace-suave" href="/archivo/${d.id}" target="_blank" rel="noopener">${e(d.nombre_archivo)}</a></div></div></td><td>${e(d.autor)}</td><td>${fecha(d.creado_en)}</td><td>${d.cantidad}</td><td><button class="boton-icono peligro" data-accion="eliminar-material" data-id="${d.id}" aria-label="Eliminar documento">${i('papelera')}</button></td></tr>`).join('')}</tbody></table></div>`:vacio('Sin documentos'));
    } else if(seccion==='usuarios') {
      const {usuarios}=await api('usuarios');
      destino.innerHTML=`<div class="titulo-seccion"><h2>Usuarios institucionales</h2><span class="insignia">${cantidad(usuarios.length,'cuenta','cuentas')}</span></div>
        <form id="crear-usuario" class="formulario formulario-usuario">
          <label>Nombre completo<input name="nombre" required minlength="2" maxlength="120" placeholder="Nombre y apellido" autocomplete="name"></label>
          <label>Usuario<input name="usuario" type="text" required minlength="3" maxlength="40" placeholder="Ej. juan.perez" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
          <label>Contraseña<input name="clave" type="password" minlength="10" maxlength="72" required autocomplete="new-password" placeholder="Mínimo 10 caracteres"></label>
          <label>Rol<select name="rol"><option value="docente">Docente / supervisor</option><option value="admin">Administrador</option></select></label>
          <div class="acciones-formulario"><button class="boton primario" type="submit">${i('mas')} Crear usuario</button></div>
        </form>
        <div class="tabla-contenedor"><table><thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Estado</th><th></th></tr></thead><tbody>${usuarios.map(u=>`<tr><td><strong>${e(u.nombre)}</strong></td><td>${e(u.usuario)}</td><td>${u.rol==='admin'?'Administrador':'Docente / supervisor'}</td><td><span class="insignia ${u.estado==='activo'?'verde':''}">${u.estado==='activo'?'Activo':'Inactivo'}</span></td><td>${Number(u.id)===Number(datos.usuario.id)?'<span class="texto-suave">Tu cuenta</span>':accion('estado-usuario',u.id,u.estado==='activo'?'Desactivar':'Activar')}</td></tr>`).join('')}</tbody></table></div>`;
      document.getElementById('crear-usuario').addEventListener('submit',async evento=>{evento.preventDefault();const b=evento.currentTarget.querySelector('[type="submit"]');b.disabled=true;try{const r=await api('crear-usuario',Object.fromEntries(new FormData(evento.currentTarget)));aviso(r.mensaje);await mostrarAdmin();}catch(error){aviso(error.message,true);b.disabled=false;}});
    } else if(seccion==='evaluaciones') {destino.innerHTML='<div class="titulo-seccion"><div><h2>Historial de evaluaciones</h2></div></div><div id="historial-admin"></div>';await historial(document.getElementById('historial-admin'));}
    else {
      const {motores}=await api('motores');
      const motor=motores[0];
      destino.innerHTML=`<div class="titulo-seccion"><h2>Inteligencia artificial</h2><span class="insignia ${motor.configurado?'verde':''}">${motor.configurado?'Configurada':'Pendiente de configuración'}</span></div>
        <article class="configuracion-groq"><div class="titulo-con-icono"><span class="icono-titulo">${i('ia')}</span><h3>Groq</h3></div>
          <dl class="datos-groq"><div><dt>Modelo</dt><dd>${e(motor.modelo||'Sin configurar')}</dd></div><div><dt>Clave API</dt><dd>${motor.clave_configurada?'Configurada':'Sin configurar'}</dd></div></dl>
          <p class="texto-suave texto-pequeno">La clave y el modelo se configuran en el archivo privado .env.</p>
          <div class="acciones-groq"><button class="boton primario" data-accion="probar-motor" data-id="groq" ${motor.configurado?'':'disabled'}>${i('ia')} Probar conexión</button><span class="texto-suave texto-pequeno">Realiza una generación breve de prueba.</span></div>
          <p id="prueba-groq" class="resultado-prueba" role="status"></p>
        </article>`;
    }
  }
  function cabeceraDialogo(sobrelinea,titulo) {return `<div class="cabecera-dialogo"><div><span class="sobrelinea">${sobrelinea}</span><h2 id="titulo-dialogo">${titulo}</h2></div><button class="boton-icono" data-cerrar aria-label="Cerrar">${i('cerrar')}</button></div>`;}
  async function abrirCarga() {
    const numeroSolicitud=++secuenciaCarga;
    const {motores}=await api('motores'); borrador=null;preguntasRevision=[];
    abrirDialogo(`${cabeceraDialogo('MATERIAL DE CÁTEDRA','Cargar PDF')}
      <div class="pasos-carga"><span class="actual">1. Documento</span><span>2. Preparación</span><span>3. Revisión</span></div>
      <form id="formulario-carga" class="formulario">
        <label class="zona-archivo" id="zona-archivo">${i('subir')}<strong>Selecciona o arrastra un PDF</strong><span id="nombre-pdf">Hasta 12 MB</span><input id="archivo-pdf" type="file" name="archivo" accept="application/pdf,.pdf" required></label>
        <label>Título<input name="titulo" id="titulo-material" required minlength="3" maxlength="200" placeholder="Nombre del material"></label>
        <div class="dos-columnas"><div class="campo-grupo"><label for="cantidad-preguntas">Preguntas</label><div class="cantidad-opciones">${[3,5,8].map(n=>`<button type="button" class="boton suave pequeno ${n===5?'elegida':''}" data-cantidad="${n}">${n}</button>`).join('')}<input name="cantidad" id="cantidad-preguntas" type="number" value="5" min="1" max="30" aria-label="Cantidad de preguntas" required></div></div>
        <label>Preparación<select name="motor" id="motor-carga">${motores.map(m=>`<option value="${m.id}" ${m.activo?'selected':''}>${e(m.nombre)}${m.configurado?'':' · sin configurar'}</option>`).join('')}<option value="manual">Redacción manual</option></select></label></div>
        <p id="nota-motor" class="nota-motor"></p><p id="error-carga" class="mensaje-error" hidden role="alert"></p>
        <div class="acciones-dialogo"><button class="boton suave" type="button" data-cerrar>Cancelar</button><button class="boton primario" type="submit">${i('ia')} Preparar preguntas</button></div>
      </form>`,'dialogo-carga');
    const selectorMotor=document.getElementById('motor-carga');
    const mostrarNota=()=>{const nota=document.getElementById('nota-motor');nota.hidden=selectorMotor.value==='manual';nota.textContent='El texto del documento se enviará a Groq.';};
    selectorMotor.addEventListener('change',mostrarNota);mostrarNota();
    manejarCierre(()=>{secuenciaCarga++;});
    const archivo=document.getElementById('archivo-pdf');
    archivo.addEventListener('change',()=>{if(archivo.files[0]) {document.getElementById('nombre-pdf').textContent=archivo.files[0].name;document.getElementById('titulo-material').value=archivo.files[0].name.replace(/\.pdf$/i,'').replace(/[_-]/g,' ');}});
    const zona=document.getElementById('zona-archivo');
    zona.addEventListener('dragover',evento=>{evento.preventDefault();zona.classList.add('arrastrando');});
    zona.addEventListener('dragleave',()=>zona.classList.remove('arrastrando'));
    zona.addEventListener('drop',evento=>{evento.preventDefault();zona.classList.remove('arrastrando');if(evento.dataTransfer.files.length){archivo.files=evento.dataTransfer.files;archivo.dispatchEvent(new Event('change'));}});
    document.querySelectorAll('[data-cantidad]').forEach(b=>b.addEventListener('click',()=>{document.getElementById('cantidad-preguntas').value=b.dataset.cantidad;document.querySelectorAll('[data-cantidad]').forEach(x=>x.classList.toggle('elegida',x===b));}));
    document.getElementById('formulario-carga').addEventListener('submit',async evento=>{
      evento.preventDefault(); const formulario=evento.currentTarget, boton=formulario.querySelector('[type="submit"]'), error=document.getElementById('error-carga');
      boton.disabled=true;error.hidden=true; const contenidoOriginal=boton.innerHTML;boton.innerHTML='<span class="cargando"></span> Leyendo el PDF y preparando preguntas…';
      const carga=new FormData(formulario);formulario.classList.add('procesando');document.querySelectorAll('.pasos-carga span').forEach((s,n)=>s.classList.toggle('actual',n===1));
      // No se muestra un porcentaje ficticio: la operación dura lo que necesite el proveedor.
      try {const preparado=await api('preparar-material',carga);if(numeroSolicitud!==secuenciaCarga || !document.getElementById('dialogo').open){await api('descartar-borrador',{token:preparado.token});return;}borrador=preparado;preguntasRevision=borrador.preguntas;mostrarRevision();}
      catch(fallo){if(error.isConnected){error.textContent=fallo.message;error.hidden=false;boton.disabled=false;boton.innerHTML=contenidoOriginal;formulario.classList.remove('procesando');document.querySelectorAll('.pasos-carga span').forEach((s,n)=>s.classList.toggle('actual',n===0));}else aviso(fallo.message,true);}
    });
  }
  function leerRevision() {
    return [...document.querySelectorAll('.pregunta-revision')].map(tarjeta=>({enunciado:tarjeta.querySelector('[data-enunciado]').value.trim(),opciones:[...tarjeta.querySelectorAll('[data-opcion]')].map(x=>x.value.trim()),correcta:Number(tarjeta.querySelector('input[type="radio"]:checked')?.value??0),explicacion:tarjeta.querySelector('[data-explicacion]').value.trim()}));
  }
  function mostrarRevision() {
    manejarCierre(null);
    abrirDialogo(`${cabeceraDialogo('MATERIAL DE CÁTEDRA','Revisar preguntas')}<div class="pasos-carga"><span>1. Documento</span><span>2. Preparación</span><span class="actual">3. Revisión</span></div>${borrador.analisis?.resumido?'<p class="nota">El documento se analizó por bloques. Revisa que las preguntas cubran los temas que deseas evaluar.</p>':''}<form id="formulario-revision" class="formulario"><label>Título<input name="titulo" value="${e(borrador.titulo)}" minlength="3" maxlength="200" required></label><div id="preguntas-revision">${preguntasRevision.map((p,n)=>`<article class="pregunta-revision"><div class="fila-entre"><span class="sobrelinea">PREGUNTA ${n+1}</span><button type="button" class="boton-icono peligro" data-quitar="${n}" aria-label="Descartar pregunta ${n+1}">${i('papelera')}</button></div><label>Enunciado<textarea data-enunciado minlength="5" maxlength="3000" required rows="2">${e(p.enunciado)}</textarea></label><div class="opciones-revision">${p.opciones.map((o,k)=>`<label class="opcion-revision"><input type="radio" name="correcta-${n}" value="${k}" ${Number(p.correcta)===k?'checked':''} aria-label="Marcar ${'ABCD'[k]} como correcta"><b>${'ABCD'[k]}</b><input data-opcion="${k}" value="${e(o)}" maxlength="1500" required aria-label="Opción ${'ABCD'[k]}"></label>`).join('')}</div><label>Explicación<textarea data-explicacion required maxlength="3000" rows="2">${e(p.explicacion)}</textarea></label></article>`).join('')}</div><div class="pie-revision"><span>${cantidad(preguntasRevision.length,'pregunta','preguntas')} · ${cantidad(borrador.paginas,'página','páginas')}</span><button type="submit" class="boton primario" ${preguntasRevision.length?'':'disabled'}>${i('ok')} Guardar material</button></div></form>`,'dialogo-revision');
    manejarCierre(()=>{if(borrador){const token=borrador.token;borrador=null;api('descartar-borrador',{token}).catch(()=>{});}});
    document.querySelectorAll('[data-quitar]').forEach(b=>b.addEventListener('click',()=>{preguntasRevision=leerRevision();borrador.titulo=document.querySelector('#formulario-revision [name="titulo"]').value;preguntasRevision.splice(Number(b.dataset.quitar),1);mostrarRevision();}));
    document.getElementById('formulario-revision').addEventListener('submit',async evento=>{evento.preventDefault();const boton=evento.currentTarget.querySelector('[type="submit"]');boton.disabled=true;try{const r=await api('guardar-material',{token:borrador.token,titulo:evento.currentTarget.elements.titulo.value,preguntas:leerRevision()});borrador=null;cerrarDialogo();aviso(r.mensaje);await cargarMateriales();}catch(error){aviso(error.message,true);boton.disabled=false;}});
  }
  function configurarSala(id) {
    const material=materiales.find(m=>Number(m.id)===id);
    abrirDialogo(`${cabeceraDialogo('EVALUACIÓN','Crear sala')}<div class="material-dialogo">${i('documento')}<strong>${e(material?.titulo||'Material de cátedra')}</strong></div><form id="configurar-sala" class="formulario"><label>Tiempo por pregunta<select name="tiempo"><option value="15">15 segundos</option><option value="20" selected>20 segundos</option><option value="30">30 segundos</option></select></label><p class="nota-motor">Cada pregunta incluye 4 segundos de revisión.</p><div class="acciones-dialogo"><button class="boton suave" type="button" data-cerrar>Cancelar</button><button class="boton primario" type="submit">${i('qr')} Crear sala</button></div></form>`,'dialogo-configuracion');
    document.getElementById('configurar-sala').addEventListener('submit',async evento=>{evento.preventDefault();const boton=evento.currentTarget.querySelector('[type="submit"]');boton.disabled=true;try{const r=await api('crear-sala',{documento_id:id,tiempo:Number(evento.currentTarget.elements.tiempo.value)});await abrirSala(r.codigo);}catch(error){aviso(error.message,true);boton.disabled=false;}});
  }
  async function abrirSala(codigo) {
    const numeroSala=++secuenciaSala;
    clearTimeout(salaTemporizador); manejarCierre(null);
    const url=`${datos.url}/sala/${codigo}`;
    abrirDialogo(`${cabeceraDialogo('EVALUACIÓN EN VIVO','Acceso a la sala')}<div id="sala-anfitrion"><p>Cargando…</p></div>`,'dialogo-sala');
    manejarCierre(()=>{secuenciaSala++;clearTimeout(salaTemporizador);});
    let ultimaVista='';
    async function actualizar() {
      if(numeroSala!==secuenciaSala || !document.getElementById('dialogo').open || !document.getElementById('sala-anfitrion'))return;
      try {
        const s=await api(`estado?codigo=${codigo}`),destino=document.getElementById('sala-anfitrion'); if(!destino || numeroSala!==secuenciaSala || !document.getElementById('dialogo').open)return;
        if(s.fase==='espera') {
          if(ultimaVista!=='espera')destino.innerHTML=`<div class="sala-acceso"><div class="sala-qr"><div class="qr-codigo">${qr(url)}</div><div class="codigo-sala"><span>Código</span><strong>${e(codigo)}</strong></div></div><div class="sala-informacion"><h3>${e(s.titulo)}</h3><div class="datos-sala"><span>${i('documento')} ${cantidad(s.total,'pregunta','preguntas')}</span><span>${i('reloj')} ${s.tiempo} s por pregunta</span></div><p id="contador-sala" class="contador-conectados"></p><div id="lista-sala"></div></div></div><div class="enlace-sala"><input value="${e(url)}" readonly aria-label="Enlace para participantes"><button id="copiar-enlace" class="boton suave pequeno">Copiar enlace</button></div><div class="acciones-sala"><a class="boton suave" href="/sala/${e(codigo)}" target="_blank" rel="noopener">Vista participante</a><button id="iniciar-actividad" class="boton primario">Iniciar evaluación ${i('flecha')}</button></div>`;
          destino.querySelector('#contador-sala').textContent=`${cantidad(s.conectados,'conectado','conectados')} · ${cantidad(s.cantidad,'inscrito','inscritos')}`;
          destino.querySelector('#lista-sala').innerHTML=listaParticipantes(s.ranking);
          destino.querySelector('#iniciar-actividad').disabled=s.cantidad===0;
          destino.querySelector('#iniciar-actividad').onclick=async evento=>{evento.currentTarget.disabled=true;try{const r=await api('iniciar-sala',{codigo});aviso(r.mensaje);ultimaVista='';await actualizar();}catch(error){aviso(error.message,true);}};
          destino.querySelector('#copiar-enlace').onclick=async()=>{try{await navigator.clipboard.writeText(url);aviso('Enlace copiado.');}catch{destino.querySelector('.enlace-sala input').select();aviso('Selecciona y copia el enlace.');}};
        } else if(s.fase==='finalizada') {
          destino.innerHTML=`<div class="centrado"><span class="insignia verde">Evaluación finalizada</span><h3>${e(s.titulo)}</h3></div>${ranking(s.ranking,s.total)}`;
          if(!esAdmin)historial(document.getElementById('historial-docente')).catch(()=>{});
        } else {
          destino.innerHTML=`<div class="fila-entre"><span class="insignia verde">Actividad en vivo</span><span>${s.indice+1} de ${s.total}</span></div><h3>${e(s.titulo)}</h3><div class="resumen-vivo"><span id="tiempo-anfitrion">${Math.max(0,Math.ceil(s.limite-s.ahora))}s</span><strong>${s.fase==='preparacion'?'Comenzamos en un momento':s.fase==='revelacion'?'Revelando la respuesta correcta':e(s.pregunta.enunciado)}</strong></div><p class="centrado texto-suave">${s.respondieron??0} de ${s.cantidad} participantes respondieron</p>${listaParticipantes(s.ranking)}`;
        }
        ultimaVista=s.fase;
        if(s.fase!=='finalizada') {clearTimeout(salaTemporizador);salaTemporizador=setTimeout(actualizar,1500);}
      }catch(error){aviso(error.message,true);salaTemporizador=setTimeout(actualizar,3000);}
    }
    await actualizar();
  }
  document.addEventListener('click',async evento=>{
    const admin=evento.target.closest('[data-admin]');if(admin){try{await mostrarAdmin(admin.dataset.admin);}catch(error){aviso(error.message,true);}return;}
    const b=evento.target.closest('[data-accion]');if(!b)return; const id=Number(b.dataset.id),nombre=b.dataset.accion;
    try {
      if(nombre==='material'){pestana='preguntas';await abrirMaterial(id);}
      else if(nombre==='cerrar-material')await abrirMaterial(materialActivo);
      else if(nombre==='pestana'){pestana=b.dataset.id;await abrirMaterial(materialActivo,true);}
      else if(nombre==='cargar')await abrirCarga();
      else if(nombre==='lanzar')configurarSala(id);
      else if(nombre==='ver-sala')await abrirSala(b.dataset.id);
      else if(nombre==='eliminar-material') {if(!await confirmar('Eliminar material','Se eliminarán el PDF y sus preguntas. El historial de evaluaciones se conservará.'))return;const r=await api(nombre,{id});aviso(r.mensaje);if(esAdmin)await mostrarAdmin();else{if(materialActivo===id){materialActivo=null;document.getElementById('detalle-material').hidden=true;}await cargarMateriales();}}
      else if(nombre==='eliminar-pregunta'){if(!await confirmar('Eliminar pregunta','Se quitará del material. Las salas ya creadas conservarán su copia.'))return;const r=await api(nombre,{id});aviso(r.mensaje);await abrirMaterial(materialActivo,true);}
      else if(nombre==='estado-usuario'){b.disabled=true;const r=await api(nombre,{id});aviso(r.mensaje);await mostrarAdmin();}
      else if(nombre==='probar-motor'){b.disabled=true;const salida=document.getElementById('prueba-groq');salida.textContent='Comprobando clave y modelo…';try{const r=await api(nombre,{});salida.textContent=`${r.mensaje} ${r.milisegundos} ms.`;}catch(error){salida.textContent=error.message;}finally{b.disabled=false;}}
    }catch(error){aviso(error.message,true);b.disabled=false;}
  });
  if(esAdmin)mostrarAdmin().catch(error=>aviso(error.message,true));
  else {document.getElementById('abrir-carga').addEventListener('click',()=>abrirCarga().catch(error=>aviso(error.message,true)));cargarMateriales().catch(error=>aviso(error.message,true));historial(document.getElementById('historial-docente')).catch(error=>aviso(error.message,true));}
})();
