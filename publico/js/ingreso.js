'use strict';
document.querySelector('form[data-instalacion]')?.addEventListener('submit', async evento => {
  evento.preventDefault();
  const formulario = evento.currentTarget;
  const boton = formulario.querySelector('[type="submit"]');
  const salida = document.querySelector('[data-resultado-instalacion]');
  boton.disabled = true;
  salida.hidden = true;
  try {
    const respuesta = await fetch(formulario.action, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'manual',
      headers: {'Accept': 'application/json'},
      body: new URLSearchParams(new FormData(formulario)),
    });
    if (respuesta.type === 'opaqueredirect') {
      throw new Error('El alojamiento redirigió el formulario antes de confirmar la operación. Pide que revisen las redirecciones de EvaluaEIC.');
    }
    if (!respuesta.headers.get('Content-Type')?.includes('application/json')) {
      throw new Error(`El servidor devolvió una respuesta inesperada (HTTP ${respuesta.status}). Revisa el registro del alojamiento.`);
    }
    const resultado = await respuesta.json();
    if (!respuesta.ok || !resultado.ok) throw new Error(resultado.mensaje || 'No se pudo completar la operación.');
    if (resultado.destino) {
      location.assign(resultado.destino);
      return;
    }
    salida.classList.remove('mensaje-error');
    salida.textContent = resultado.mensaje;
    salida.hidden = false;
    formulario.reset();
    boton.disabled = false;
  } catch (fallo) {
    salida.classList.add('mensaje-error');
    salida.textContent = fallo instanceof TypeError ? 'No se pudo enviar el formulario. Comprueba la conexión e inténtalo otra vez.' : fallo.message;
    salida.hidden = false;
    boton.disabled = false;
  }
});
document.getElementById('formulario-ingreso')?.addEventListener('submit',async evento=>{
  evento.preventDefault(); const formulario=evento.currentTarget; const boton=formulario.querySelector('[type="submit"]'); const error=document.getElementById('error-ingreso');
  boton.disabled=true; error.hidden=true;
  try { const resultado=await Evalua.api('ingresar',Object.fromEntries(new FormData(formulario))); location.assign(resultado.destino); }
  catch (fallo) { error.textContent=fallo.message; error.hidden=false; boton.disabled=false; }
});
document.getElementById('mostrar-clave')?.addEventListener('click',evento=>{
  const campo=document.getElementById('clave-ingreso'); const visible=campo.type==='password'; campo.type=visible?'text':'password'; evento.target.textContent=visible?'Ocultar':'Ver'; evento.target.setAttribute('aria-label',visible?'Ocultar contraseña':'Mostrar contraseña');
});
