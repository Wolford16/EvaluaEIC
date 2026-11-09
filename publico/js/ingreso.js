'use strict';
document.getElementById('mostrar-clave')?.addEventListener('click', evento => {
  const campo = document.getElementById('clave-ingreso');
  const visible = campo.type === 'password';
  campo.type = visible ? 'text' : 'password';
  evento.currentTarget.textContent = visible ? 'Ocultar' : 'Ver';
  evento.currentTarget.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
});
