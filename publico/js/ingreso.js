'use strict';
document.getElementById('formulario-ingreso').addEventListener('submit',async evento=>{
  evento.preventDefault(); const formulario=evento.currentTarget; const boton=formulario.querySelector('[type="submit"]'); const error=document.getElementById('error-ingreso');
  boton.disabled=true; error.hidden=true;
  try { const resultado=await Evalua.api('ingresar',Object.fromEntries(new FormData(formulario))); location.assign(resultado.destino); }
  catch (fallo) { error.textContent=fallo.message; error.hidden=false; boton.disabled=false; }
});
document.getElementById('mostrar-clave').addEventListener('click',evento=>{
  const campo=document.getElementById('clave-ingreso'); const visible=campo.type==='password'; campo.type=visible?'text':'password'; evento.target.textContent=visible?'Ocultar':'Ver'; evento.target.setAttribute('aria-label',visible?'Ocultar contraseña':'Mostrar contraseña');
});
