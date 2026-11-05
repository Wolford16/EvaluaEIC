'use strict';
window.Evalua = (() => {
  const datos = JSON.parse(document.getElementById('datos-iniciales').textContent);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const escapar = valor => String(valor ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icono = nombre => `<svg class="icono" aria-hidden="true"><use href="#i-${nombre}"/></svg>`;
  async function api(accion, datosEnvio = null, token = '') {
    const opciones = {method: datosEnvio === null ? 'GET' : 'POST', headers: {'X-CSRF-Token':csrf}, credentials:'same-origin'};
    if (token) opciones.headers.Authorization = `Bearer ${token}`;
    if (datosEnvio instanceof FormData) opciones.body=datosEnvio;
    else if (datosEnvio !== null) { opciones.headers['Content-Type']='application/json'; opciones.body=JSON.stringify(datosEnvio); }
    let respuesta;
    try { respuesta=await fetch(`/api/${accion}`,opciones); }
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
  function ranking(filas,total,miId=null) {
    if (!filas?.length) return vacio('Todavía no hay participantes','Los resultados aparecerán cuando el grupo entre a la sala.','trofeo');
    const podio=filas.slice(0,3).map((p,i)=>`<div class="puesto puesto-${i+1}"><div class="medalla">${['🥇','🥈','🥉'][i]}</div>${avatar(p.avatar)}<strong>${escapar(p.nombre)}</strong><b>${p.porcentaje}%</b><span>${p.puntos.toLocaleString('es')} puntos</span></div>`).join('');
    return `<div class="podio">${podio}</div><div class="tabla-contenedor"><table><thead><tr><th>Posición</th><th>Participante</th><th>Aciertos</th><th>Porcentaje</th><th>Puntos</th><th>Tiempo</th></tr></thead><tbody>${filas.map((p,i)=>`<tr class="${p.id===miId?'mi-fila':''}"><td><span class="numero-posicion">${i+1}°</span></td><td><div class="nombre-avatar">${avatar(p.avatar)}<strong>${escapar(p.nombre)}${p.id===miId?' <small>(Tú)</small>':''}</strong></div></td><td>${p.aciertos} de ${total}</td><td><span class="porcentaje">${p.porcentaje}%</span></td><td class="puntos">${p.puntos.toLocaleString('es')}</td><td>${p.tiempo_total}s</td></tr>`).join('')}</tbody></table></div>`;
  }
  function qr(url) {
    if (typeof qrcode!=='function') return '<p>Comparte el enlace o el código de sala.</p>';
    const codigo=qrcode(0,'M'); codigo.addData(url,'Byte'); codigo.make(); return codigo.createSvgTag({cellSize:5,margin:20,scalable:true});
  }
  return {datos,escapar,icono,api,aviso,abrirDialogo,cerrarDialogo,manejarCierre,confirmar,vacio,avatar,ranking,qr};
})();
