'use strict';
window.Evalua = (() => {
  const datos = JSON.parse(document.getElementById('datos-iniciales').textContent);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const ruta = relativa => datos.rutaBase + relativa;
  const escapar = valor => String(valor ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icono = nombre => `<svg class="icono" aria-hidden="true"><use href="#i-${nombre}"/></svg>`;
  async function api(accion, datosEnvio = null, token = '') {
    const opciones = {method: datosEnvio === null ? 'GET' : 'POST', headers: {'X-CSRF-Token':csrf}, credentials:'same-origin'};
    if (token) opciones.headers.Authorization = `Bearer ${token}`;
    if (datosEnvio instanceof FormData) opciones.body=datosEnvio;
    else if (datosEnvio !== null) { opciones.headers['Content-Type']='application/json'; opciones.body=JSON.stringify(datosEnvio); }
    let respuesta;
    try { respuesta=await fetch(ruta(`/api/${accion}`),opciones); }
    catch { throw new Error('No hay conexión con el servidor. Inténtalo de nuevo.'); }
    let resultado;
    try { resultado=await respuesta.json(); } catch { throw new Error('El servidor no pudo procesar la solicitud. Revisa su registro.'); }
    if (!respuesta.ok || !resultado.ok) { const error=new Error(resultado.mensaje || 'No se pudo completar la operación.'); error.estado=respuesta.status; error.codigo=resultado.codigo_error; error.reintentarEn=resultado.reintentar_en; throw error; }
    return resultado;
  }
  function aviso(mensaje, error = false) {
    const elemento=document.createElement('div'); elemento.className=`aviso ${error?'aviso-error':''}`; elemento.textContent=mensaje;
    let destino=document.getElementById('avisos');
    if(dialogo.open){
      destino=dialogo.querySelector('.avisos-dialogo');
      if(!destino){destino=document.createElement('div');destino.className='avisos-dialogo';destino.setAttribute('role','status');dialogo.append(destino);}
    }
    destino.append(elemento); setTimeout(()=>elemento.remove(),6000);
  }
  const dialogo=document.getElementById('dialogo');
  let alCerrar=null;
  function abrirDialogo(html,clase='') {
    dialogo.className=clase;
    const contenido=document.getElementById('contenido-dialogo');contenido.innerHTML=html;
    const cabecera=contenido.querySelector('.cabecera-dialogo');
    const cuerpo=document.createElement('div');cuerpo.className='cuerpo-dialogo';
    [...contenido.childNodes].filter(nodo=>nodo!==cabecera).forEach(nodo=>cuerpo.append(nodo));
    contenido.replaceChildren(...(cabecera?[cabecera,cuerpo]:[cuerpo]));
    if (!dialogo.open) dialogo.showModal();
  }
  function cerrarDialogo() { dialogo.close(); }
  function manejarCierre(funcion) { alCerrar=funcion; }
  dialogo.addEventListener('close',()=>{ const funcion=alCerrar; alCerrar=null; funcion?.(); });
  dialogo.addEventListener('click',evento=>{if (evento.target.closest('[data-cerrar]')) cerrarDialogo();});
  const confirmacion=document.getElementById('confirmacion');
  confirmacion.querySelectorAll('[data-cancelar-confirmacion]').forEach(boton=>boton.addEventListener('click',()=>confirmacion.close('cancelar')));
  document.getElementById('aceptar-confirmacion').addEventListener('click',()=>confirmacion.close('aceptar'));
  function confirmar(titulo,descripcion){
    if(confirmacion.open)return Promise.resolve(false);
    document.getElementById('titulo-confirmacion').textContent=titulo;
    document.getElementById('descripcion-confirmacion').textContent=descripcion;
    confirmacion.returnValue='';confirmacion.showModal();
    return new Promise(resolve=>confirmacion.addEventListener('close',()=>resolve(confirmacion.returnValue==='aceptar'),{once:true}));
  }
  function vacio(titulo,texto='',simbolo='documento') { return `<div class="estado-vacio">${icono(simbolo)}<h3>${escapar(titulo)}</h3>${texto?`<p>${escapar(texto)}</p>`:''}</div>`; }
  function avatar(indice) { return `<span class="avatar avatar-${Number(indice)%6}">${datos.avatares[Number(indice)] || '🔍'}</span>`; }
  function cabeceraDialogo(etiqueta,titulo) {
    return `<div class="cabecera-dialogo"><div><span class="sobrelinea">${escapar(etiqueta)}</span><h2 id="titulo-dialogo">${escapar(titulo)}</h2></div><button class="boton-icono" data-cerrar aria-label="Cerrar">${icono('cerrar')}</button></div>`;
  }
  function tarjetaPodio(puesto, indice) {
    return `<div class="puesto puesto-${indice+1}"><div class="medalla">${['🥇','🥈','🥉'][indice]}</div>${avatar(puesto.avatar)}<strong>${escapar(puesto.nombre)}</strong><b>${puesto.porcentaje}%</b><span>${puesto.puntos.toLocaleString('es')} puntos</span></div>`;
  }
  function etiquetaEquipo(numero) {
    return numero ? `<span class="insignia etiqueta-equipo" data-equipo="${Number(numero)}">Equipo ${Number(numero)}</span>` : '';
  }
  function resultadosEquipos(estado, miEquipo=null) {
    if (!estado.equipos?.length || !['revelacion','finalizada'].includes(estado.fase)) return '';
    const revelacion=estado.fase==='revelacion';
    return `<section class="resultados-equipos">
      <h3>${revelacion?'Equipos · pregunta '+(estado.indice+1):'Resultados por equipos'}</h3>
      <div class="tabla-contenedor"><table>
        <thead><tr><th>Posición</th><th>Equipo</th>${revelacion?'<th>Esta pregunta</th>':'<th>Integrantes</th>'}<th>Aciertos totales</th><th>Porcentaje</th></tr></thead>
        <tbody>${estado.equipos.map(eq=>`<tr class="${eq.numero===miEquipo?'mi-fila':''}">
          <td><span class="numero-posicion">${eq.posicion}°</span></td>
          <td>${etiquetaEquipo(eq.numero)}${eq.numero===miEquipo?' <small>(Tu equipo)</small>':''}</td>
          <td>${revelacion?`${eq.aciertos_pregunta} de ${eq.integrantes}`:eq.integrantes}</td>
          <td>${eq.aciertos} de ${eq.posibles}</td><td><span class="porcentaje">${eq.porcentaje}%</span></td>
        </tr>`).join('')}</tbody>
      </table></div>
    </section>`;
  }
  function ranking(filas,total,miId=null,accion=null) {
    if (!filas?.length) return vacio('Todavía no hay participantes','Los resultados aparecerán cuando el grupo entre a la sala.','trofeo');
    const podio=filas.slice(0,3).map(tarjetaPodio).join('');
    const porEquipos=Boolean(filas[0].equipo);
    return `<div class="podio">${podio}</div><div class="tabla-contenedor"><table><thead><tr><th>Posición</th><th>Participante</th>${porEquipos?'<th>Equipo</th>':''}<th>Aciertos</th><th>Porcentaje</th><th>Puntos</th><th>Tiempo</th>${accion?'<th>Historial</th>':''}</tr></thead><tbody>${filas.map((p,i)=>`<tr class="${p.id===miId?'mi-fila':''}"><td><span class="numero-posicion">${i+1}°</span></td><td><div class="nombre-avatar">${avatar(p.avatar)}<strong>${escapar(p.nombre)}${p.id===miId?' <small>(Tú)</small>':''}</strong></div></td>${porEquipos?`<td>${etiquetaEquipo(p.equipo)}</td>`:''}<td>${p.aciertos} de ${total}</td><td><span class="porcentaje">${p.porcentaje}%</span></td><td class="puntos">${p.puntos.toLocaleString('es')}</td><td>${p.tiempo_total}s</td>${accion?`<td>${accion(p)}</td>`:''}</tr>`).join('')}</tbody></table></div>`;
  }
  function detalleRespuestas(preguntas) {
    return `<ol class="historial-respuestas">${preguntas.map((pregunta, indice) => {
      const correcta = Number(pregunta.correcta);
      const acierto = pregunta.elegida === correcta;
      const resultado = pregunta.elegida === null ? 'Sin respuesta' : acierto ? 'Correcta' : 'Incorrecta';
      const clase = pregunta.elegida === null ? '' : acierto ? 'verde' : 'roja';
      const opcion = numero => `${'ABCD'[numero]}. ${escapar(pregunta.opciones[numero])}`;
      return `<li class="respuesta-historial">
        <div class="fila-entre"><span class="sobrelinea">PREGUNTA ${indice+1}</span><span class="insignia ${clase}">${resultado}</span></div>
        <h3>${escapar(pregunta.enunciado)}</h3>
        <dl>
          <div><dt>Respuesta elegida</dt><dd>${pregunta.elegida === null ? 'Sin respuesta' : opcion(pregunta.elegida)}</dd></div>
          <div><dt>Respuesta correcta</dt><dd>${opcion(correcta)}</dd></div>
        </dl>
        <p class="explicacion">${escapar(pregunta.explicacion)}</p>
        <small>${pregunta.puntos.toLocaleString('es')} puntos</small>
      </li>`;
    }).join('')}</ol>`;
  }

  function qr(url) {
    if (typeof qrcode!=='function') return '<p>Comparte el enlace o el código de sala.</p>';
    const codigo=qrcode(0,'M'); codigo.addData(url,'Byte'); codigo.make(); return codigo.createSvgTag({cellSize:5,margin:20,scalable:true});
  }
  return {datos,ruta,escapar,icono,api,aviso,abrirDialogo,cerrarDialogo,manejarCierre,confirmar,vacio,cabeceraDialogo,avatar,tarjetaPodio,ranking,etiquetaEquipo,resultadosEquipos,detalleRespuestas,qr};
})();
