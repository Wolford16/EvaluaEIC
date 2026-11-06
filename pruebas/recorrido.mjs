// Prueba HTTP completa con una base SQLite aislada. No modifica los materiales del usuario.
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {existsSync,mkdirSync,rmSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import {setTimeout as esperar} from 'node:timers/promises';

const raiz=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.EVALUAEIC_PHP || ['C:/xampp/php/php.exe','C:/php/php.exe'].find(existsSync) || 'php';
const directorio=path.join(raiz,'almacenamiento',`pruebas-${Date.now()}`);
const tokenPrueba=randomBytes(24).toString('hex');
mkdirSync(directorio,{recursive:true});
mkdirSync(path.join(raiz,'almacenamiento','documentos'),{recursive:true});
const entorno={...process.env,BD_MOTOR:'sqlite',BD_ARCHIVO:path.join(directorio,'datos.sqlite'),APLICACION_URL:'http://127.0.0.1:8091',OPENROUTER_API_KEY:'',OPENROUTER_MODEL:''};
const inicial=spawnSync(php,['herramientas/preparar.php'],{cwd:raiz,env:entorno,encoding:'utf8'});
if(inicial.status!==0)throw new Error(inicial.stderr||inicial.stdout);
const servidor=spawn(php,['-d',`upload_tmp_dir=${directorio}`,'-d','display_errors=0','-d',`error_log=${directorio}/errores.log`,'-S','127.0.0.1:8091','-t','publico','servidor.php'],{cwd:raiz,env:entorno,stdio:'ignore'});
let comprobaciones=0;
function comprobar(condicion,mensaje){assert.ok(condicion,mensaje);comprobaciones++;}
function ejecutarPhp(codigo){const r=spawnSync(php,['-r',`require 'configuracion/aplicacion.php'; ${codigo}`],{cwd:raiz,env:entorno,encoding:'utf8'});if(r.status!==0)throw new Error(r.stderr);return r.stdout;}
class Cliente {
  cookie='';csrf='';
  async pagina(ruta){const r=await fetch(`http://127.0.0.1:8091${ruta}`,{headers:{Cookie:this.cookie},redirect:'manual'});this.actualizar(r);const html=await r.text();this.csrf=html.match(/name="csrf-token" content="([a-f0-9]+)"/)?.[1]||this.csrf;return {r,html};}
  actualizar(r){const cookie=r.headers.get('set-cookie');if(cookie)this.cookie=cookie.split(';')[0];}
  async api(accion,datos=null,token=''){const headers={Cookie:this.cookie,'X-CSRF-Token':this.csrf};if(token)headers.Authorization=`Bearer ${token}`;const opciones={headers,method:datos===null?'GET':'POST'};if(datos instanceof FormData)opciones.body=datos;else if(datos!==null){headers['Content-Type']='application/json';opciones.body=JSON.stringify(datos);}const r=await fetch(`http://127.0.0.1:8091/api/${accion}`,opciones);this.actualizar(r);const valor=await r.json();return {...valor,estadoHttp:r.status};}
  async ingresar(usuario,clave){await this.pagina('/ingreso');const r=await this.api('ingresar',{usuario,clave});comprobar(r.ok,'Ingreso válido');await this.pagina(r.destino);}
}
try {
  comprobar(ejecutarPhp("echo EvaluaEIC\\Servicios\\BaseDatos::ejecutar('SELECT COUNT(*) FROM usuarios')->fetchColumn();")==='0','La instalación comienza sin cuentas de ejemplo');
  const arranque=spawnSync(php,['herramientas/crear_administrador.php'],{cwd:raiz,env:entorno,encoding:'utf8'});
  const claveInicial=arranque.stdout?.match(/Contraseña: ([a-f0-9]{36})/)?.[1];
  comprobar(arranque.status===0 && claveInicial,'El comando crea un administrador con contraseña aleatoria');
  const repetido=spawnSync(php,['herramientas/crear_administrador.php'],{cwd:raiz,env:entorno,encoding:'utf8'});
  comprobar(repetido.status===1 && repetido.stderr.includes('Ya existe'),'El acceso inicial no puede crearse dos veces');
  for(let intento=0;intento<30;intento++){try{await fetch('http://127.0.0.1:8091/ingreso');break;}catch{await esperar(100);}}
  const docente=new Cliente(),admin=new Cliente(),alumno=new Cliente(),otro=new Cliente();
  await admin.ingresar('admin-inicial',claveInicial);
  comprobar((await admin.api('crear-usuario',{nombre:'Docente de prueba',usuario:'docente',clave:'DocenteLocal2026!',rol:'docente'})).ok,'El administrador crea al primer docente');
  await docente.ingresar('docente','DocenteLocal2026!');
  comprobar(!(await admin.pagina('/admin')).html.includes('Vista docente'),'El panel no ofrece cambiar de vista');
  await alumno.pagina('/participar');
  comprobar((await docente.api('ingresar',{correo:'docente@evaluaeic.local',clave:'DocenteLocal2026!'})).estadoHttp===422,'El ingreso exige usuario, no correo');
  comprobar((await docente.api('ingresar',{usuario:'docente',clave:'incorrecta'})).estadoHttp===401,'Rechaza contraseñas incorrectas');
  const mayuscula=new Cliente();await mayuscula.ingresar('DOCENTE','DocenteLocal2026!');
  comprobar((await alumno.api('materiales')).estadoHttp===401,'Materiales requieren sesión');
  comprobar((await docente.api('usuarios')).estadoHttp===403,'Docente no administra usuarios');
  const csrfReal=docente.csrf;
  docente.csrf='invalido';
  const csrfInvalido=await docente.api('crear-sala',{});
  comprobar(csrfInvalido.estadoHttp===403 && csrfInvalido.mensaje.includes('Recarga la página'),'Protección CSRF');
  docente.csrf=csrfReal;
  const proveedores=await docente.api('motores');
  comprobar(proveedores.motores.length===1&&proveedores.motores[0].id==='openrouter','Únicamente OpenRouter disponible');
  comprobar(!('clave' in proveedores.motores[0])&&!('api_key' in proveedores.motores[0]),'La clave de OpenRouter no se entrega al navegador');
  comprobar((await docente.api('probar-motor',{})).estadoHttp===403,'Solo administración prueba OpenRouter');
  comprobar((await admin.api('probar-motor',{})).codigo_error==='openrouter_configuracion','Prueba sin credenciales solicita configuración');
  comprobar((await admin.api('activar-motor',{motor:'ollama'})).estadoHttp===404,'No permite activar otros proveedores');
  const alta=await admin.api('crear-usuario',{nombre:'Otra docente',usuario:'otra.docente',clave:'OtraDocente2026!',rol:'docente'});comprobar(alta.ok,'Crear usuario');
  comprobar((await admin.api('crear-usuario',{nombre:'Duplicada',usuario:'OTRA.DOCENTE',clave:'OtraDocente2026!',rol:'docente'})).estadoHttp===409,'Usuario único sin distinguir mayúsculas');
  comprobar((await admin.api('crear-usuario',{nombre:'Inválida',usuario:'otra@institucion.edu',clave:'OtraDocente2026!',rol:'docente'})).estadoHttp===422,'El usuario no acepta direcciones de correo');
  comprobar((await admin.api('crear-usuario',{nombre:'Sin usuario',correo:'otra@institucion.edu',clave:'OtraDocente2026!',rol:'docente'})).estadoHttp===422,'Creación exige campo usuario');
  comprobar(!(await admin.api('usuarios')).usuarios.some(u=>'clave_hash' in u),'No se exponen contraseñas');
  comprobar((await admin.api('usuarios')).usuarios.every(u=>typeof u.usuario==='string'&&!('correo' in u)),'Listado contiene usuario y elimina correo');
  await otro.ingresar('otra.docente','OtraDocente2026!');
  const sinConfiguracion=new FormData();sinConfiguracion.set('titulo','Prueba sin configuración');
  const errorOpenRouter=await docente.api('preparar-material',sinConfiguracion);
  comprobar(errorOpenRouter.estadoHttp===503&&errorOpenRouter.codigo_error==='openrouter_configuracion','Generación con OpenRouter explica la configuración pendiente');
  const otroProveedor=new FormData();otroProveedor.set('titulo','Proveedor retirado');otroProveedor.set('cantidad','5');otroProveedor.set('motor','gemini');
  comprobar((await docente.api('preparar-material',otroProveedor)).estadoHttp===422,'Carga rechaza proveedores retirados');
  otroProveedor.set('motor','manual');
  comprobar((await docente.api('preparar-material',otroProveedor)).estadoHttp===422,'La redacción manual se retiró del servidor');
  // Simula un borrador anterior de dos opciones para comprobar su compatibilidad sin llamar a OpenRouter.
  const borrador={token:tokenPrueba};
  const preguntas=JSON.parse(ejecutarPhp(`
    $preguntas = array_slice(EvaluaEIC\\Servicios\\DatosIniciales::preguntas(), 0, 2);
    foreach ($preguntas as &$pregunta) $pregunta['opciones'] = array_slice($pregunta['opciones'], 0, 2);
    unset($pregunta);
    $archivo = '${tokenPrueba}.pdf';
    $ruta = RAIZ . '/almacenamiento/documentos/' . $archivo;
    EvaluaEIC\\Servicios\\DatosIniciales::crearPdf($ruta);
    $docente = EvaluaEIC\\Servicios\\BaseDatos::ejecutar("SELECT id FROM usuarios WHERE usuario='docente'")->fetchColumn();
    EvaluaEIC\\Servicios\\BaseDatos::ejecutar(
        'INSERT INTO borradores (token,usuario_id,titulo,nombre_archivo,ruta_archivo,tamano,paginas,preguntas_json,creado_en) VALUES (?,?,?,?,?,?,?,?,?)',
        ['${tokenPrueba}',$docente,'Material de prueba','ejemplo.pdf',$archivo,filesize($ruta),1,json_encode($preguntas),microtime(true)]
    );
    echo json_encode($preguntas);
  `));
  comprobar((await otro.api('guardar-material',{token:borrador.token,preguntas})).estadoHttp===404,'Borrador privado del docente');
  const cuatroOpciones=[{...preguntas[0],opciones:[...preguntas[0].opciones,'Opción adicional C','Opción adicional D']}];
  comprobar((await docente.api('guardar-material',{token:borrador.token,preguntas:cuatroOpciones})).estadoHttp===422,'Un borrador de dos opciones conserva su formato');
  const guardado=await docente.api('guardar-material',{token:borrador.token,preguntas});comprobar(guardado.ok,'Guardar material curado');const id=guardado.id;
  const reintento=await docente.api('guardar-material',{token:borrador.token,preguntas});
  comprobar(reintento.ok&&reintento.id===id&&reintento.reutilizado,'Reintentar guardado devuelve el mismo material');
  comprobar((await docente.api('materiales')).documentos.length===1,'El reintento no duplica materiales');
  comprobar((await otro.api(`material?id=${id}`)).estadoHttp===403,'Material privado del docente');
  comprobar((await docente.pagina(`/archivo/${id}`)).r.headers.get('content-type')==='application/pdf','PDF se sirve autenticado');
  comprobar((await alumno.pagina(`/archivo/${id}`)).r.status===401,'PDF protegido para anónimos');
  // Una pregunta histórica mantiene sus cuatro opciones al pasar a una sala.
  ejecutarPhp(`
    $historica=EvaluaEIC\\Servicios\\DatosIniciales::preguntas()[3];
    EvaluaEIC\\Servicios\\BaseDatos::ejecutar('UPDATE preguntas SET enunciado=?,opciones_json=?,correcta=?,explicacion=? WHERE id=(SELECT MAX(id) FROM preguntas WHERE documento_id=?)',[$historica['enunciado'],json_encode($historica['opciones']),$historica['correcta'],$historica['explicacion'],${id}]);
  `);
  const sala=await docente.api('crear-sala',{documento_id:id,tiempo:15});comprobar(sala.ok,'Crear sala');const codigo=sala.codigo;
  const originales=(await docente.api(`material?id=${id}`)).preguntas;
  const leerCopia=codigoSala=>JSON.parse(ejecutarPhp(`echo EvaluaEIC\\Servicios\\BaseDatos::ejecutar('SELECT preguntas_json FROM salas_evaluacion WHERE codigo=?',['${codigoSala}'])->fetchColumn();`));
  const copia=leerCopia(codigo);
  comprobar(copia.length===2 && copia.every(p=>{
    const original=originales.find(o=>o.enunciado===p.enunciado);
    return original && p.opciones.length===original.opciones.length && p.opciones[p.correcta]===original.opciones[original.correcta];
  }),'La mezcla conserva las opciones y su respuesta correcta');
  comprobar(sala.url===entorno.APLICACION_URL+'/sala/'+codigo && (await alumno.pagina(new URL(sala.url).pathname)).r.status===200,'El enlace de la sala usa la dirección configurada y abre la vista participante');
  comprobar((await otro.api('iniciar-sala',{codigo})).estadoHttp===403,'Solo el docente responsable inicia');
  comprobar(!(await docente.api('iniciar-sala',{codigo})).ok,'No inicia una sala vacía');
  const entrada=await alumno.api('unirse',{codigo,nombre:'Participante de prueba',avatar:23});comprobar(entrada.ok,'Registro sin cuenta con un avatar nuevo');const token=entrada.token;
  const segundo=await alumno.api('unirse',{codigo,nombre:'Sin respuestas',avatar:2});comprobar(segundo.ok,'Segundo participante');
  const tercero=await alumno.api('unirse',{codigo,nombre:'Respuesta incorrecta',avatar:0});
  comprobar(tercero.ok,'Tercer participante');
  comprobar((await docente.api('iniciar-sala',{codigo})).ok,'Iniciar evaluación');
  comprobar((await alumno.api(`estado?codigo=${codigo}`,null,token)).fase==='preparacion','Cuenta inicial de tres segundos');
  comprobar((await alumno.api('responder',{codigo,indice:0,opcion:0},token)).estadoHttp===409,'No responde durante preparación');
  function situar(segundos){ejecutarPhp(`EvaluaEIC\\Servicios\\BaseDatos::ejecutar('UPDATE salas_evaluacion SET inicio=? WHERE codigo=?',[microtime(true)-${segundos},'${codigo}']);`);}
  situar(3);
  const publico=await alumno.api(`estado?codigo=${codigo}`);comprobar(!('ranking' in publico)&&!('pregunta' in publico),'Sala pública sin preguntas ni ranking');
  const pregunta=await alumno.api(`estado?codigo=${codigo}`,null,token);comprobar(pregunta.fase==='pregunta'&&!('correcta' in pregunta.pregunta)&&!('explicacion' in pregunta.pregunta),'Solución oculta hasta el cierre');
  const estadoDocente=await docente.api(`estado?codigo=${codigo}`);
  const idAlumno=pregunta.participante.id;
  comprobar(pregunta.participante.avatar===23 && estadoDocente.pregunta.enunciado===pregunta.pregunta.enunciado && JSON.stringify(estadoDocente.pregunta.opciones)===JSON.stringify(pregunta.pregunta.opciones),'La sala mantiene el avatar y el mismo orden para todos');
  comprobar((await alumno.api(`respuestas?codigo=${codigo}`,null,token)).estadoHttp===409 && (await docente.api(`respuestas?codigo=${codigo}&participante=${idAlumno}`)).estadoHttp===409,'El historial permanece cerrado durante la evaluación');
  comprobar((await alumno.api('responder',{codigo,indice:1,opcion:1},token)).estadoHttp===409,'Rechaza pregunta futura');
  comprobar((await alumno.api('responder',{codigo,indice:0,opcion:copia[0].opciones.length},token)).estadoHttp===422,'Rechaza una opción fuera del rango');
  comprobar((await alumno.api('responder',{codigo,indice:0,opcion:copia[0].correcta},token)).ok,'Respuesta registrada');
  const errorElegido=(copia[0].correcta+1)%copia[0].opciones.length;
  comprobar((await alumno.api('responder',{codigo,indice:0,opcion:errorElegido},tercero.token)).ok,'Registra también una respuesta incorrecta');
  comprobar((await alumno.api('responder',{codigo,indice:0,opcion:1},token)).estadoHttp===409,'Respuesta bloqueada contra cambios');
  const antes=await alumno.api(`estado?codigo=${codigo}`,null,token);comprobar(antes.fase==='pregunta'&&!('puntos' in antes.respuesta)&&antes.participante.puntos===0,'Responder no avanza ni revela puntos');
  comprobar(antes.participante.racha===0,'La racha no anticipa el acierto de una pregunta abierta');
  situar(16);
  const revelar=await alumno.api(`estado?codigo=${codigo}`,null,token);comprobar(revelar.fase==='revelacion'&&revelar.pregunta.correcta===copia[0].correcta&&revelar.respuesta.puntos>500,'Revelación y bonificación del servidor');
  comprobar(revelar.participante.racha===1 && (await alumno.api(`estado?codigo=${codigo}`,null,token)).participante.racha===1,'La racha aparece al revelar y no se duplica al recargar');
  comprobar((await alumno.api(`estado?codigo=${codigo}`,null,tercero.token)).participante.racha===0,'Una respuesta incorrecta deja la racha en cero');
  comprobar((await alumno.api('responder',{codigo,indice:0,opcion:0},segundo.token)).estadoHttp===409,'Rechaza respuestas fuera de tiempo');
  situar(19.1);const siguiente=await alumno.api(`estado?codigo=${codigo}`,null,token);comprobar(siguiente.indice===1&&siguiente.fase==='pregunta','Avanza tras cuatro segundos de revelación');
  comprobar((await alumno.api('responder',{codigo,indice:1,opcion:copia[1].correcta},token)).ok,'Acepta la respuesta correcta de la segunda pregunta');
  situar(38.1);const final=await alumno.api(`estado?codigo=${codigo}`,null,token);comprobar(final.fase==='finalizada','Finaliza automáticamente');comprobar(final.ranking[0].porcentaje===100&&final.ranking[1].porcentaje===0,'Ranking por porcentaje');
  const ausente=final.ranking.find(p=>p.nombre==='Sin respuestas');
  comprobar(ausente.puntos===0&&ausente.tiempo_total===30,'Ausentes con cero puntos y tiempo máximo');
  const peticionHistorial=`respuestas?codigo=${codigo}&participante=${idAlumno}`;
  const misRespuestas=await alumno.api(`respuestas?codigo=${codigo}`,null,token);
  comprobar(misRespuestas.ok && misRespuestas.preguntas.every((p,n)=>p.elegida===copia[n].correcta && p.enunciado===copia[n].enunciado),'El participante ve lo que respondió en el orden de la sala');
  comprobar(JSON.stringify((await docente.api(peticionHistorial)).preguntas)===JSON.stringify(misRespuestas.preguntas) && (await admin.api(peticionHistorial)).ok,'Docente responsable y administrador pueden consultar las respuestas');
  comprobar((await alumno.api(peticionHistorial)).estadoHttp===401 && (await otro.api(peticionHistorial)).estadoHttp===403 && (await alumno.api(peticionHistorial,null,tercero.token)).estadoHttp===403,'El historial excluye a personas y participantes sin permiso');
  const fallos=await alumno.api(`respuestas?codigo=${codigo}`,null,tercero.token);
  comprobar(fallos.preguntas[0].elegida===errorElegido && fallos.preguntas[0].correcta!==errorElegido && fallos.preguntas[1].elegida===null,'El historial distingue respuestas incorrectas de preguntas sin responder');
  comprobar((await alumno.api('unirse',{codigo,nombre:'Tardío',avatar:0})).estadoHttp===409,'No permite registro al terminar');
  const detalle=await docente.api(`material?id=${id}`);await docente.api('eliminar-pregunta',{id:detalle.preguntas[0].id});comprobar((await alumno.api(`estado?codigo=${codigo}`,null,token)).total===2,'Las salas conservan preguntas históricas');
  ejecutarPhp(`
    EvaluaEIC\\Servicios\\BaseDatos::ejecutar('DELETE FROM preguntas WHERE documento_id=?', [${id}]);
    foreach (EvaluaEIC\\Servicios\\DatosIniciales::preguntas() as $pregunta) {
        $correcta = $pregunta['opciones'][$pregunta['correcta']];
        unset($pregunta['opciones'][$pregunta['correcta']]);
        $opciones = array_merge([$correcta], $pregunta['opciones']);
        EvaluaEIC\\Servicios\\BaseDatos::ejecutar('INSERT INTO preguntas (documento_id,enunciado,opciones_json,correcta,explicacion) VALUES (?,?,?,?,?)',[${id},$pregunta['enunciado'],json_encode($opciones),0,$pregunta['explicacion']]);
    }
  `);
  const salaMezclada=await docente.api('crear-sala',{documento_id:id,tiempo:15});
  const copiaRacha=leerCopia(salaMezclada.codigo);
  comprobar(salaMezclada.ok && copiaRacha.map(p=>p.correcta).sort().join(',')==='0,1,2,3','Cuatro respuestas originalmente A se reparten entre las cuatro letras');
  const codigoRacha=salaMezclada.codigo;
  const entradaRacha=await alumno.api('unirse',{codigo:codigoRacha,nombre:'Racha de prueba',avatar:1});
  const entradaAusente=await alumno.api('unirse',{codigo:codigoRacha,nombre:'Racha con ausencia',avatar:2});
  comprobar((await docente.api('iniciar-sala',{codigo:codigoRacha})).ok,'Inicia la sala de rachas');
  function situarRacha(segundos) {
    ejecutarPhp(`EvaluaEIC\\Servicios\\BaseDatos::ejecutar('UPDATE salas_evaluacion SET inicio=? WHERE codigo=?',[microtime(true)-${segundos},'${codigoRacha}']);`);
  }
  async function estadoRacha(entrada) {
    return alumno.api(`estado?codigo=${codigoRacha}`,null,entrada.token);
  }
  for (let indice=0; indice<4; indice++) {
    situarRacha(indice*19+3);
    const correcta=copiaRacha[indice].correcta;
    const opcion=indice===3?(correcta+1)%4:correcta;
    comprobar((await alumno.api('responder',{codigo:codigoRacha,indice,opcion},entradaRacha.token)).ok,'Respuesta para calcular racha');
    if (indice===0) await alumno.api('responder',{codigo:codigoRacha,indice,opcion:correcta},entradaAusente.token);
    situarRacha(indice*19+16);
    const esperado=indice===3?0:indice+1;
    comprobar((await estadoRacha(entradaRacha)).participante.racha===esperado,'La racha avanza con aciertos y se reinicia con un fallo');
    if (indice===1) comprobar((await estadoRacha(entradaAusente)).participante.racha===0,'Agotar el tiempo también reinicia una racha');
  }
  const crearEquipos={documento_id:id,tiempo:15,modalidad:'equipos',cantidad_equipos:2};
  comprobar((await docente.api('crear-sala',{...crearEquipos,modalidad:'otro'})).estadoHttp===422,'Valida la modalidad en el servidor');
  comprobar((await docente.api('crear-sala',{...crearEquipos,cantidad_equipos:1})).estadoHttp===422 &&
    (await docente.api('crear-sala',{...crearEquipos,cantidad_equipos:9})).estadoHttp===422,'Acepta de dos a ocho equipos');
  const porEquipos=await docente.api('crear-sala',crearEquipos);
  comprobar(porEquipos.ok,'Crea una sala por equipos');
  const codigoEquipos=porEquipos.codigo;
  const estadoEquipos=(cliente,token='')=>cliente.api(`estado?codigo=${codigoEquipos}`,null,token);
  const entrarEquipo=nombre=>alumno.api('unirse',{codigo:codigoEquipos,nombre,avatar:1,equipo:8});
  const primera=await entrarEquipo('Ana de equipos');
  comprobar((await docente.api('iniciar-sala',{codigo:codigoEquipos})).estadoHttp===422,'Cada equipo debe tener al menos un integrante al iniciar');
  const segunda=await entrarEquipo('Bea de equipos');
  const tercera=await entrarEquipo('Celia de equipos');
  const esperaEquipos=await estadoEquipos(docente);
  comprobar(esperaEquipos.equipos.map(eq=>eq.integrantes).join(',')==='2,1','Reparte automáticamente en equipos equilibrados');
  comprobar((await estadoEquipos(alumno,primera.token)).participante.equipo===1 &&
    (await estadoEquipos(alumno,segunda.token)).participante.equipo===2 &&
    (await estadoEquipos(alumno,tercera.token)).participante.equipo===1,'La asignación se conserva tras nuevas entradas y descarta el equipo enviado por el cliente');
  const equiposPublico=await estadoEquipos(alumno);
  comprobar(equiposPublico.cantidad_equipos===2 && !('equipos' in equiposPublico) && !('ranking' in equiposPublico),'La sala pública indica la modalidad sin exponer el reparto');
  comprobar((await docente.api('iniciar-sala',{codigo:codigoEquipos})).ok,'Inicia con ambos equipos formados');
  comprobar((await entrarEquipo('Ingreso tardío')).estadoHttp===409,'El padrón se cierra cuando comienza la evaluación');
  const preguntasEquipos=leerCopia(codigoEquipos).preguntas;
  const responderEquipo=(persona,indice,correcta=true)=>alumno.api('responder',{
    codigo:codigoEquipos,indice,opcion:(preguntasEquipos[indice].correcta+(correcta?0:1))%4
  },persona.token);
  const situarEquipos=segundos=>ejecutarPhp(`EvaluaEIC\\Servicios\\BaseDatos::ejecutar('UPDATE salas_evaluacion SET inicio=? WHERE codigo=?',[microtime(true)-${segundos},'${codigoEquipos}']);`);
  situarEquipos(3);
  comprobar((await responderEquipo(primera,0)).ok && (await responderEquipo(segunda,0)).ok &&
    (await responderEquipo(tercera,0,false)).ok,'Los integrantes responden por separado con las cuatro opciones');
  const equiposAbierta=await estadoEquipos(docente);
  comprobar(equiposAbierta.equipos.every(eq=>eq.aciertos===0 && eq.posibles===0 && !('aciertos_pregunta' in eq)) &&
    equiposAbierta.ranking.every(p=>p.puntos===0 && !('acierto_revelado' in p)),'No revela aciertos colectivos ni puntos individuales con la pregunta abierta');
  situarEquipos(16);
  const revelacionEquipos=await estadoEquipos(alumno,primera.token);
  const primeroRevelado=revelacionEquipos.equipos.find(eq=>eq.numero===1);
  comprobar(primeroRevelado.aciertos_pregunta===1 && primeroRevelado.integrantes===2 &&
    revelacionEquipos.participante.puntos===Number(revelacionEquipos.respuesta.puntos),'La revelación distingue el acierto de equipo y los puntos personales');
  situarEquipos(22);
  comprobar((await responderEquipo(primera,1)).ok,'La segunda pregunta recibe una respuesta individual');
  situarEquipos(35);
  const empate=await estadoEquipos(docente);
  comprobar(empate.equipos.every(eq=>eq.porcentaje===50 && eq.posicion===1),'Un mismo porcentaje comparte posición aunque cambie el número de integrantes');
  situarEquipos(41);
  comprobar((await responderEquipo(segunda,2)).ok && (await responderEquipo(tercera,2)).ok,'Ambos equipos suman aciertos en la tercera pregunta');
  situarEquipos(76.2);
  const finalEquipos=await estadoEquipos(docente);
  comprobar(finalEquipos.fase==='finalizada' && finalEquipos.equipos[0].numero===2 && finalEquipos.equipos[0].aciertos===2 &&
    finalEquipos.equipos[0].posibles===4 && finalEquipos.equipos[1].aciertos===3 && finalEquipos.equipos[1].posibles===8,
    'Clasifica por porcentaje y cuenta las preguntas sin responder en el total posible');
  const historialEquipos=await alumno.api(`respuestas?codigo=${codigoEquipos}`,null,primera.token);
  const resultadoAna=finalEquipos.ranking.find(p=>p.nombre==='Ana de equipos');
  comprobar(historialEquipos.preguntas.reduce((total,p)=>total+p.puntos,0)===resultadoAna.puntos &&
    (await estadoEquipos(alumno,primera.token)).participante.equipo===1,'Conserva el historial, los puntos y la asignación individual al volver a la sala');
  comprobar((await admin.api('salas')).salas.some(s=>s.codigo===codigoEquipos&&s.cantidad_equipos===2&&s.fase==='finalizada'),
    'El historial institucional conserva la modalidad');
  const individualAnterior=await alumno.api(`estado?codigo=${codigo}`,null,token);
  comprobar(individualAnterior.cantidad_equipos===0 && !('equipos' in individualAnterior) && individualAnterior.ranking[0].porcentaje===100,
    'Las salas con la copia anterior de preguntas siguen siendo individuales');

  const cuentas=(await admin.api('usuarios')).usuarios;
  const idAdmin=cuentas.find(u=>u.usuario==='admin-inicial').id;
  const idDocente=cuentas.find(u=>u.usuario==='docente').id;
  const nombreNuevo='administracion.eic';
  const claveNueva='ClaveDePrueba2026!';
  comprobar((await admin.pagina('/admin')).html.includes('Mi cuenta') && !(await docente.pagina('/docente')).html.includes('Mi cuenta'),'Solo el administrador ve Mi cuenta');
  comprobar((await docente.api('actualizar-cuenta',{usuario:'nuevo.docente',clave_actual:'DocenteLocal2026!'})).estadoHttp===403,'Un docente no puede usar la ruta de cambio');
  comprobar((await docente.api('eliminar-usuario',{id:idAdmin})).estadoHttp===403 && (await alumno.api('eliminar-usuario',{id:idAdmin})).estadoHttp===401,'Eliminar cuentas requiere una sesión de administrador');
  comprobar((await admin.api('eliminar-usuario',{id:idAdmin})).estadoHttp===422 && (await admin.api('estado-usuario',{id:idAdmin})).estadoHttp===422,'El administrador conserva su propia cuenta activa');
  const cuentaAdmin={usuario:nombreNuevo,clave_actual:claveInicial};
  const csrfAdmin=admin.csrf;
  admin.csrf='invalido';
  comprobar((await admin.api('actualizar-cuenta',cuentaAdmin)).estadoHttp===403 && (await admin.api('eliminar-usuario',{id:idDocente})).estadoHttp===403,'Actualizar y eliminar exigen protección CSRF');
  admin.csrf=csrfAdmin;
  comprobar((await admin.api('actualizar-cuenta',{...cuentaAdmin,clave_actual:'incorrecta'})).estadoHttp===422,'Confirma la contraseña actual antes de cambiar el usuario');
  comprobar((await admin.api('actualizar-cuenta',{...cuentaAdmin,usuario:'DOCENTE'})).estadoHttp===409,'Impide elegir un usuario que ya existe');
  comprobar((await admin.api('actualizar-cuenta',{...cuentaAdmin,clave_nueva:claveNueva,confirmar_clave:'NoCoincide2026!'})).estadoHttp===422,'Rechaza la confirmación de contraseña que no coincide');
  comprobar((await admin.api('usuarios')).usuarios.some(u=>u.id===idAdmin&&u.usuario==='admin-inicial'),'Los intentos inválidos dejan la cuenta intacta');
  comprobar((await admin.api('actualizar-cuenta',cuentaAdmin)).usuario===nombreNuevo,'El administrador puede cambiar solo su usuario');
  const otraSesionAdmin=new Cliente();
  await otraSesionAdmin.ingresar(nombreNuevo,claveInicial);
  comprobar((await admin.api('actualizar-cuenta',{usuario:nombreNuevo,clave_actual:claveInicial,clave_nueva:claveNueva,confirmar_clave:claveNueva})).ok,'El administrador puede cambiar solo su contraseña');
  comprobar((await otraSesionAdmin.api('usuarios')).estadoHttp===401 && (await admin.api('usuarios')).ok,'El cambio de contraseña revoca las otras sesiones y mantiene la actual');
  await otraSesionAdmin.pagina('/ingreso');
  comprobar((await otraSesionAdmin.api('ingresar',{usuario:nombreNuevo,clave:claveInicial})).estadoHttp===401,'La contraseña anterior ya no permite iniciar sesión');
  comprobar((await otraSesionAdmin.api('ingresar',{usuario:'admin-inicial',clave:claveNueva})).estadoHttp===401,'El usuario anterior ya no permite iniciar sesión');
  await otraSesionAdmin.ingresar(nombreNuevo,claveNueva);

  comprobar((await admin.api('eliminar-usuario',{id:idDocente})).ok,'El administrador elimina a un docente que tiene evaluaciones y documentos');
  comprobar(!(await admin.api('usuarios')).usuarios.some(u=>u.id===idDocente),'La cuenta eliminada desaparece del listado');
  comprobar((await docente.api('materiales')).estadoHttp===401 && (await admin.api('estado-usuario',{id:idDocente})).estadoHttp===404,'Se revoca la sesión del docente y no se puede reactivar la cuenta eliminada');
  comprobar((await admin.api('eliminar-usuario',{id:idDocente})).estadoHttp===404,'Repetir la eliminación no cambia los registros históricos');
  await docente.pagina('/ingreso');
  comprobar((await docente.api('ingresar',{usuario:'docente',clave:'DocenteLocal2026!'})).estadoHttp===401,'La cuenta eliminada ya no puede iniciar sesión');
  comprobar((await admin.api('materiales')).documentos.some(d=>d.id===id&&d.autor==='Docente de prueba') && (await admin.pagina(`/archivo/${id}`)).r.headers.get('content-type')==='application/pdf','El administrador conserva el PDF y la autoría');
  comprobar((await admin.api('salas')).salas.some(s=>s.codigo===codigo&&s.docente==='Docente de prueba') &&
    JSON.stringify((await admin.api(peticionHistorial)).preguntas)===JSON.stringify(misRespuestas.preguntas),'Se conservan la sala y sus respuestas después de eliminar la cuenta');
  comprobar((await admin.api('crear-usuario',{nombre:'Docente nuevo',usuario:'docente',clave:'OtroDocente2026!',rol:'docente'})).ok,'Se puede volver a usar el nombre de acceso eliminado');
  const nuevoDocente=new Cliente();
  await nuevoDocente.ingresar('docente','OtroDocente2026!');
  comprobar((await nuevoDocente.api('materiales')).documentos.length===0 && (await nuevoDocente.api(peticionHistorial)).estadoHttp===403,'Reutilizar el usuario no transfiere documentos ni evaluaciones');

  await admin.api('eliminar-material',{id});comprobar((await alumno.api(`estado?codigo=${codigo}`,null,token)).ranking[0].porcentaje===100,'Eliminar material conserva historial');
  comprobar((await admin.api(peticionHistorial)).preguntas.length===2,'El historial de respuestas sobrevive a la eliminación del material');
  const idOtro=(await admin.api('usuarios')).usuarios.find(u=>u.usuario==='otra.docente').id;await admin.api('estado-usuario',{id:idOtro});comprobar((await otro.api('materiales')).estadoHttp===401,'Desactivar revoca el acceso existente');
  const puntos=ejecutarPhp('echo EvaluaEIC\\Servicios\\Evaluacion::puntos(true,5,20);');comprobar(puntos==='875','Fórmula: 5 de 20 segundos = 875 puntos');
  console.log(`Correcto: ${comprobaciones} comprobaciones del recorrido HTTP completo.`);
} finally {
  servidor.kill();await new Promise(resolve=>{if(servidor.exitCode!==null)resolve();else servidor.once('exit',resolve);});
  rmSync(path.join(raiz,'almacenamiento','documentos',tokenPrueba+'.pdf'),{force:true});
  // Solo borra la carpeta de prueba exacta creada por este proceso, dentro de almacenamiento.
  if(path.dirname(directorio)===path.join(raiz,'almacenamiento')&&path.basename(directorio).startsWith('pruebas-'))rmSync(directorio,{recursive:true,force:true});
}
