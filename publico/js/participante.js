'use strict';
(() => {
  const {api,ruta,datos,escapar:e,icono:i,aviso,avatar,ranking,vacio}=Evalua;
  const raiz=document.getElementById('experiencia-participante');
  const colores=['marino','celeste','amarilla','turquesa'];
  let codigo=datos.codigo,token='',estado=null,firma='',elegida=null,enviando=false,desfase=0,temporizador=null;
  const leerToken=()=>{
    try{return localStorage.getItem(`evaluaeic-${codigo}`)||localStorage.getItem(`evaluaia-${codigo}`)||'';}
    catch{return '';}
  };
  const guardarToken=valor=>{
    try{
      if(valor)localStorage.setItem(`evaluaeic-${codigo}`,valor);
      else localStorage.removeItem(`evaluaeic-${codigo}`);
      localStorage.removeItem(`evaluaia-${codigo}`);
    }catch{/* La sesión sigue disponible en memoria. */}
  };
  function formularioCodigo(mensaje='') {
    raiz.className='experiencia-participante';
    raiz.innerHTML=`<section class="panel tarjeta-participante centrado"><div class="icono-bienvenida">${i('qr')}</div><h1>Entrar a una evaluación</h1><form id="formulario-codigo" class="formulario"><label class="alinear-izquierda">Código de sala<input class="entrada-codigo" name="codigo" placeholder="EVAL-A1B2C3" maxlength="12" minlength="4" autocapitalize="characters" autocomplete="off" required value="${e(codigo)}"></label>${mensaje?`<p class="mensaje-error" role="alert">${e(mensaje)}</p>`:''}<button class="boton primario ancho" type="submit">Entrar a la sala ${i('flecha')}</button></form></section>`;
    document.getElementById('formulario-codigo').addEventListener('submit',evento=>{evento.preventDefault();codigo=evento.currentTarget.elements.codigo.value.trim().toUpperCase();location.assign(ruta(`/sala/${encodeURIComponent(codigo)}`));});
  }
  function registro(s) {
    if(s.fase!=='espera') {
      raiz.innerHTML=`<section class="panel tarjeta-participante centrado">${vacio(s.fase==='finalizada'?'Esta evaluación ya terminó':'La evaluación ya comenzó','Solicita al docente un nuevo código para participar.','reloj')}<a class="boton primario" href="${ruta('/participar')}">Buscar otra sala</a></section>`;return;
    }
    raiz.innerHTML=`<section class="panel tarjeta-participante"><div class="centrado"><span class="insignia verde"><span class="punto conectado"></span> Sala abierta · ${e(codigo)}</span><h1>Registro de participante</h1><p class="texto-suave">${e(s.titulo)}</p></div><form id="formulario-registro" class="formulario"><label>Nombre completo<input name="nombre" placeholder="Escribe tu nombre y apellido" minlength="2" maxlength="100" required autocomplete="name"></label><fieldset class="campo-avatares"><legend>Elige tu avatar</legend><div class="galeria-avatares">${datos.avatares.map((a,n)=>`<label class="opcion-avatar"><input type="radio" name="avatar" value="${n}" ${n===0?'checked':''}><span class="avatar avatar-${n%6}" aria-hidden="true">${a}</span><span class="solo-lector">Avatar ${n+1}</span></label>`).join('')}</div></fieldset><p class="texto-suave texto-pequeno">Tu nombre y resultado serán visibles para el grupo.</p><p id="error-registro" class="mensaje-error" hidden role="alert"></p><button class="boton primario ancho" type="submit">Entrar a la sala ${i('flecha')}</button></form><p class="centrado texto-suave texto-pequeno">${i('reloj')} ${s.total} preguntas · ${s.tiempo} segundos por pregunta</p></section>`;
    document.getElementById('formulario-registro').addEventListener('submit',async evento=>{
      evento.preventDefault();const boton=evento.currentTarget.querySelector('[type="submit"]');boton.disabled=true;
      try{const valores=Object.fromEntries(new FormData(evento.currentTarget));const r=await api('unirse',{codigo,nombre:valores.nombre,avatar:Number(valores.avatar)});token=r.token;guardarToken(token);firma='';await consultar();}
      catch(error){const salida=document.getElementById('error-registro');salida.textContent=error.message;salida.hidden=false;boton.disabled=false;}
    });
  }
  function mostrar(s) {
    estado=s;
    const nuevaFirma=`${s.fase}:${s.indice}`;
    const cambio=firma!==nuevaFirma;
    if(cambio){firma=nuevaFirma;elegida=s.respuesta?.opcion??null;}
    else if(s.respuesta) elegida=s.respuesta.opcion;
    if(s.fase==='espera') {
      if(cambio)raiz.innerHTML=`<section class="panel tarjeta-participante centrado"><span class="insignia verde"><span class="punto conectado"></span> ESTÁS DENTRO</span><div class="mi-avatar-grande">${avatar(s.participante.avatar)}</div><h1>¡Hola, ${e(s.participante.nombre)}!</h1><p class="texto-suave">Espera el inicio de la evaluación.</p><div class="ficha-espera"><span class="sobrelinea">TU EVALUACIÓN</span><h2>${e(s.titulo)}</h2><p>${e(s.docente)}</p><div class="datos-espera"><span>${s.total} preguntas</span><span>${s.tiempo}s por pregunta</span></div></div><div class="indicador-espera"><span class="punto conectado"></span><strong id="conectados-espera"></strong></div><div id="grupo-espera" class="grupo-espera"></div></section>`;
      document.getElementById('conectados-espera').textContent=`${s.conectados} ${s.conectados===1?'participante conectado':'participantes conectados'}`;
      document.getElementById('grupo-espera').innerHTML=s.ranking.map(p=>`<span title="${e(p.nombre)}">${avatar(p.avatar)}</span>`).join('');
    } else if(s.fase==='preparacion') {
      if(cambio)raiz.innerHTML=`<section class="panel tarjeta-participante centrado"><span class="sobrelinea">TODO LISTO</span><h1>¡Vamos a comenzar!</h1><div class="cuenta-inicio" id="tiempo-pregunta">3</div><p class="texto-suave">Lee con atención y elige una sola respuesta.</p></section>`;
    } else if(s.fase==='finalizada') {
      if(cambio)raiz.innerHTML=`<section class="panel resultados-finales"><div class="centrado"><div class="icono-bienvenida dorado">${i('trofeo')}</div><span class="sobrelinea">ACTIVIDAD COMPLETADA</span><h1>Evaluación finalizada</h1><p class="texto-suave">${e(s.titulo)}</p><div class="mi-resultado"><strong>${s.participante.porcentaje}%</strong><span>${s.participante.aciertos} de ${s.total} respuestas correctas · ${s.participante.puntos.toLocaleString('es')} puntos</span></div></div><h2 class="centrado">Resultados del grupo</h2>${ranking(s.ranking,s.total,s.participante.id)}<div class="centrado margen-superior"><a class="boton primario" href="${ruta('/participar')}">Entrar a otra sala</a></div></section>`;
    } else {
      raiz.className='experiencia-participante modo-examen';
      if(cambio){
        const revelacion=s.fase==='revelacion'; const acertada=revelacion && Boolean(Number(s.respuesta?.correcta));
        raiz.innerHTML=`<section class="examen"><div class="barra-examen"><span class="etiqueta-pregunta">PREGUNTA <b>${s.indice+1}</b> / ${s.total}</span><span class="reloj-examen ${revelacion?'revelando':''}">${i('reloj')} <b id="tiempo-pregunta"></b></span><span class="puntaje-examen">${i('trofeo')} <b id="puntos-participante">${s.participante.puntos}</b> puntos</span></div><div class="barra-tiempo"><div id="progreso-pregunta"></div></div><div class="enunciado-examen"><span class="sobrelinea">${e(s.titulo)}</span><h1>${e(s.pregunta.enunciado)}</h1></div><div class="opciones-examen">${s.pregunta.opciones.map((opcion,n)=>`<button class="respuesta-examen ${colores[n]} ${revelacion?(Number(s.pregunta.correcta)===n?'es-correcta':'es-incorrecta'):''}" data-respuesta="${n}" ${revelacion?'disabled':''}><span class="letra-opcion">${'ABCD'[n]}</span><span>${e(opcion)}</span><span class="marca-respuesta">${revelacion && Number(s.pregunta.correcta)===n?i('ok'):''}</span></button>`).join('')}</div><div class="estado-respuesta" id="estado-respuesta">Selecciona una respuesta.</div>${revelacion?`<div class="retroalimentacion ${acertada?'acertada':'fallada'}">${acertada?`<div class="confeti" aria-hidden="true">${Array.from({length:16},(_,n)=>`<span style="--posicion:${n*6.6}%;--demora:${-(n%5)*.16}s;--giro:${n*37}deg"></span>`).join('')}</div>`:''}<strong>${acertada?`${i('ok')} ¡Respuesta correcta! +${s.respuesta.puntos} puntos`:s.respuesta?.opcion===null?'Se agotó el tiempo. Esta es la respuesta correcta.':'Respuesta incorrecta. La opción correcta está marcada.'}</strong><p>${e(s.pregunta.explicacion)}</p><span>${s.indice+1===s.total?'Los resultados aparecen':'La siguiente pregunta comienza'} en <b id="tiempo-revelacion">4</b>s</span></div>`:''}<p class="pie-examen">${avatar(s.participante.avatar)} ${e(s.participante.nombre)} <span>·</span> ${e(codigo)}</p></section>`;
        document.querySelectorAll('[data-respuesta]').forEach(b=>b.addEventListener('click',()=>responder(Number(b.dataset.respuesta))));
      }
      document.getElementById('puntos-participante').textContent=s.participante.puntos;
      actualizarSeleccion();
    }
    reloj();
  }
  function actualizarSeleccion() {
    if(!estado || !['pregunta','revelacion'].includes(estado.fase))return;
    const cerrada=estado.fase==='revelacion' || Date.now()/1000+desfase>=estado.limite;
    document.querySelectorAll('[data-respuesta]').forEach(b=>{const seleccion=elegida!==null && Number(b.dataset.respuesta)===Number(elegida);b.classList.toggle('elegida',seleccion);b.disabled=cerrada || elegida!==null || enviando;b.setAttribute('aria-pressed',String(seleccion));if(seleccion && !cerrada)b.querySelector('.marca-respuesta').textContent='Elegida 🔒';});
    const salida=document.getElementById('estado-respuesta');
    if(estado.fase==='revelacion')salida.hidden=true;
    else salida.textContent=elegida!==null?'Respuesta registrada. Espera el resultado.':cerrada?'Tiempo terminado.':'Selecciona una respuesta.';
  }
  async function responder(opcion) {
    if(enviando || elegida!==null || estado?.fase!=='pregunta' || Date.now()/1000+desfase>=estado.limite)return;
    enviando=true;elegida=opcion;actualizarSeleccion();
    try{await api('responder',{codigo,indice:estado.indice,opcion},token);}
    catch(error){elegida=null;aviso(error.message,true);}
    finally{enviando=false;actualizarSeleccion();}
  }
  function reloj() {
    if(!estado?.limite)return;
    const restante=Math.max(0,estado.limite-(Date.now()/1000+desfase));
    const salida=document.getElementById('tiempo-pregunta');if(salida)salida.textContent=`${Math.ceil(restante)}${estado.fase==='preparacion'?'':'s'}`;
    const revelacion=document.getElementById('tiempo-revelacion');if(revelacion)revelacion.textContent=Math.ceil(restante);
    const progreso=document.getElementById('progreso-pregunta');if(progreso)progreso.style.width=`${Math.min(100,restante/(estado.fase==='revelacion'?4:estado.tiempo)*100)}%`;
    if(restante===0 && estado.fase==='pregunta')actualizarSeleccion();
  }
  async function consultar() {
    clearTimeout(temporizador);
    const inicio=Date.now()/1000;
    try {
      const s=await api(`estado?codigo=${encodeURIComponent(codigo)}`,null,token);
      desfase=s.ahora-(inicio+Date.now()/1000)/2;
      const avisoConexion=document.getElementById('aviso-conexion');if(avisoConexion)avisoConexion.remove();
      if(!token){registro(s);return;}
      mostrar(s);
      if(s.fase!=='finalizada')temporizador=setTimeout(consultar,1000);
    }catch(error){
      if(error.estado===401){token='';guardarToken('');firma='';formularioCodigo(error.message);return;}
      if(!estado){formularioCodigo(error.message);return;}
      if(!document.getElementById('aviso-conexion')){const aviso=document.createElement('div');aviso.id='aviso-conexion';aviso.className='aviso-conexion';aviso.textContent='Se perdió la conexión. Intentando reconectar…';raiz.prepend(aviso);}
      temporizador=setTimeout(consultar,2500);
    }
  }
  setInterval(reloj,100);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden && token && estado?.fase!=='finalizada')consultar();});
  if(codigo){token=leerToken();raiz.innerHTML='<p class="centrado texto-suave">Buscando tu sala…</p>';consultar();}else formularioCodigo();
})();
