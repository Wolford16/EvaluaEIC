'use strict';
window.Experiencia = (() => {
  const {avatar, escapar, icono, tarjetaPodio} = Evalua;
  const clavePreferencias = 'evaluaeic-sonido';
  let activo = true;
  let volumen = 35;
  try {
    const preferencias = JSON.parse(localStorage.getItem(clavePreferencias));
    if (typeof preferencias?.activo === 'boolean') activo = preferencias.activo;
    if (Number.isInteger(preferencias?.volumen)) volumen = Math.min(100, Math.max(0, preferencias.volumen));
  } catch { /* El almacenamiento puede estar bloqueado. */ }

  // Secuencias originales que se reproducen localmente con Web Audio.
  const pistas = {
    pregunta: {
      pulso: 0.31,
      bajos: [45, 41, 48, 43],
      compases: [
        [60, 64, 69, 64, 67, 64, 62, 64],
        [60, 65, 69, 65, 72, 69, 65, 64],
        [60, 64, 67, 72, 71, 67, 64, 67],
        [59, 62, 67, 62, 71, 67, 64, 62],
      ],
    },
    podio: {
      pulso: 0.19,
      bajos: [40, 43, 45, 47],
      compases: [
        [52, 59, 64, 59, 52, 59, 64, 67],
        [55, 62, 67, 62, 55, 62, 67, 71],
        [57, 64, 69, 64, 57, 64, 69, 72],
        [59, 66, 71, 66, 62, 66, 74, 75],
      ],
    },
  };
  const efectos = {
    tiempo: [65, 60],
    acierto: [72, 76, 79],
    error: [59, 55, 52],
    puesto: [67, 72],
    ganador: [72, 76, 79, 84, 83, 84],
  };
  let audio, mezcla, ambiente = null, pistaSonando, relojMusica;

  function nivel() {
    mezcla?.gain.setTargetAtTime(activo ? volumen / 100 : 0, audio.currentTime, 0.025);
  }
  function prepararSonido() {
    const Contexto = window.AudioContext || window.webkitAudioContext;
    if (!activo || !volumen || !Contexto) return;
    try {
      if (!audio) {
        audio = new Contexto();
        mezcla = audio.createGain();
        mezcla.gain.value = volumen / 100;
        mezcla.connect(audio.destination);
      }
      if (audio.state === 'running') sincronizarMusica();
      else audio.resume().then(sincronizarMusica).catch(()=>{});
    } catch { /* El juego continúa si el navegador no habilita audio. */ }
  }
  function nota(pitch, inicio, duracion, intensidad, salida, forma) {
    const voz = audio.createOscillator();
    const envolvente = audio.createGain();
    voz.type = forma;
    voz.frequency.value = 440 * 2 ** ((pitch - 69) / 12);
    envolvente.gain.setValueAtTime(0.001, inicio);
    envolvente.gain.exponentialRampToValueAtTime(intensidad, inicio + 0.025);
    envolvente.gain.exponentialRampToValueAtTime(0.001, inicio + duracion);
    voz.connect(envolvente).connect(salida);
    voz.onended = () => envolvente.disconnect();
    voz.start(inicio);
    voz.stop(inicio + duracion);
  }
  function detenerPista() {
    clearInterval(relojMusica);
    if (!pistaSonando) return;
    const salida = pistaSonando.salida;
    salida.gain.setTargetAtTime(0, audio.currentTime, 0.045);
    setTimeout(() => salida.disconnect(), 220);
    pistaSonando = null;
  }
  function sincronizarMusica() {
    if (!ambiente || !activo || !volumen || document.hidden || audio?.state !== 'running') {
      detenerPista();
      return;
    }
    if (pistaSonando?.nombre === ambiente) return;
    detenerPista();
    const nombre = ambiente;
    const pista = pistas[nombre];
    const salida = audio.createGain();
    salida.gain.value = 1;
    salida.connect(mezcla);
    pistaSonando = {nombre, salida};
    let compas = 0, pulso = 0, siguiente = audio.currentTime + 0.05;
    function programar() {
      const ahora = audio.currentTime;
      if (siguiente < ahora) siguiente = ahora + 0.035;
      while (siguiente < ahora + 0.48) {
        nota(pista.compases[compas][pulso], siguiente, pista.pulso * 1.35, 0.065, salida, 'sine');
        if (pulso % 4 === 0) nota(pista.bajos[compas], siguiente, pista.pulso * 3.3, 0.085, salida, 'triangle');
        siguiente += pista.pulso;
        pulso++;
        if (pulso === 8) {
          pulso = 0;
          compas = (compas + 1) % pista.compases.length;
        }
      }
    }
    programar();
    relojMusica = setInterval(programar, 220);
  }
  function musica(pista) {
    ambiente = pista;
    sincronizarMusica();
  }
  function efecto(nombre, demora = 0) {
    if (!activo || !volumen || document.hidden || audio?.state !== 'running') return;
    try {
      const inicio = audio.currentTime + demora;
      efectos[nombre].forEach((pitch, i) => nota(pitch, inicio + i * 0.105, 0.25, 0.21, mezcla, 'triangle'));
    } catch { /* El audio nunca bloquea la presentación. */ }
  }
  function actualizarControles() {
    document.querySelectorAll('[data-control-sonido]').forEach(control => {
      const boton = control.querySelector('[data-sonido]');
      boton.innerHTML = `${icono(activo ? 'sonido' : 'silencio')} Sonido`;
      boton.setAttribute('aria-pressed', String(activo));
      boton.title = activo ? 'Silenciar' : 'Activar sonido';
      control.querySelector('[data-volumen]').value = volumen;
      control.querySelector('output').textContent = `${volumen}%`;
    });
  }
  function controlSonido() {
    return `<div class="control-sonido" data-control-sonido>
      <button type="button" class="boton suave pequeno boton-sonido" data-sonido aria-pressed="${activo}" title="${activo ? 'Silenciar' : 'Activar sonido'}">${icono(activo ? 'sonido' : 'silencio')} Sonido</button>
      <label class="control-volumen"><span class="solo-lector">Volumen</span><input data-volumen type="range" min="0" max="100" value="${volumen}"><output>${volumen}%</output></label>
    </div>`;
  }
  function aplicarPreferencias() {
    try { localStorage.setItem(clavePreferencias, JSON.stringify({activo, volumen})); } catch {}
    nivel();
    sincronizarMusica();
    actualizarControles();
  }
  document.addEventListener('click', evento => {
    if (!evento.target.closest('[data-sonido]')) return;
    activo = !activo;
    if (activo && volumen === 0) volumen = 35;
    prepararSonido();
    aplicarPreferencias();
  });
  document.addEventListener('input', evento => {
    if (!evento.target.matches('[data-volumen]')) return;
    volumen = Number(evento.target.value);
    if (volumen > 0) activo = true;
    prepararSonido();
    aplicarPreferencias();
  });
  document.addEventListener('visibilitychange', sincronizarMusica);
  window.addEventListener('pagehide', detenerPista);

  function presentarPodio(destino, filas, mostrarResultados, porEquipos = false) {
    if (!filas.length || matchMedia('(prefers-reduced-motion: reduce)').matches) {
      if (filas.length) efecto('ganador');
      mostrarResultados();
      return () => {};
    }
    const ganadores = filas.slice(0, 3);
    const nombres = ['Primer lugar', 'Segundo lugar', 'Tercer lugar'];
    destino.innerHTML = `<section class="ceremonia-podio" aria-label="Presentación de ganadores">
      <span class="icono-ceremonia">${icono('trofeo')}</span>
      <p class="sobrelinea">${porEquipos ? 'PODIO INDIVIDUAL' : 'EVALUACIÓN FINALIZADA'}</p>
      <h2 class="anuncio-podio" aria-live="polite">Preparando el podio…</h2>
      <div class="avatares-podio" aria-hidden="true"></div>
      <div class="podio podio-revelacion">${ganadores.map((_, i) =>
        `<div class="puesto puesto-${i+1} puesto-pendiente" data-puesto="${i}"><span class="numero-podio">${i+1}°</span><strong>${nombres[i]}</strong></div>`
      ).join('')}</div>
      <button type="button" class="boton suave pequeno" data-saltar-podio>Ver resultados</button>
    </section>`;
    const ceremonia = destino.querySelector('.ceremonia-podio');
    const avatares = ceremonia.querySelector('.avatares-podio');
    const anuncio = ceremonia.querySelector('.anuncio-podio');
    const avataresVisibles = filas.slice(0, 8).map(p => p.avatar);
    function mezclarAvatares() {
      for (let i = avataresVisibles.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [avataresVisibles[i], avataresVisibles[j]] = [avataresVisibles[j], avataresVisibles[i]];
      }
      avatares.innerHTML = avataresVisibles.map(avatar).join('');
    }
    mezclarAvatares();
    const relojAvatares = setInterval(mezclarAvatares, 340);
    let temporizador, terminado = false, puesto = ganadores.length - 1;
    function finalizar(mostrar) {
      if (terminado) return;
      terminado = true;
      clearInterval(relojAvatares);
      clearTimeout(temporizador);
      musica(null);
      if (mostrar && ceremonia.isConnected) mostrarResultados();
    }
    function revelar() {
      if (!ceremonia.isConnected) return finalizar(false);
      clearInterval(relojAvatares);
      avatares.hidden = true;
      const tarjeta = ceremonia.querySelector(`[data-puesto="${puesto}"]`);
      tarjeta.outerHTML = tarjetaPodio(ganadores[puesto], puesto);
      anuncio.textContent = `${nombres[puesto]} · ${ganadores[puesto].nombre}`;
      if (puesto > 0) {
        efecto('puesto');
        puesto--;
        temporizador = setTimeout(revelar, 950);
      } else {
        musica(null);
        efecto('ganador');
        ceremonia.classList.add('podio-ganador');
        ceremonia.insertAdjacentHTML('afterbegin', `<div class="confeti confeti-podio" aria-hidden="true">${Array.from({length:14},(_, i) =>
          `<span style="--posicion:${i*7.4}%;--demora:${(i%4)*.08}s;--giro:${i*47}deg"></span>`
        ).join('')}</div>`);
        temporizador = setTimeout(() => finalizar(true), 1800);
      }
    }
    ceremonia.querySelector('[data-saltar-podio]').onclick = () => finalizar(true);
    musica('podio');
    temporizador = setTimeout(revelar, 1350);
    return () => finalizar(false);
  }

  return {prepararSonido, musica, efecto, controlSonido, presentarPodio};
})();
