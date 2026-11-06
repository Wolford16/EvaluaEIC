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
  comprobar(sala.url===entorno.APLICACION_URL+'/sala/'+codigo && (await alumno.pagina(new URL(sala.url).pathname)).r.status===200,'El enlace de la sala usa la dirección configurada y abre la vista participante');
  comprobar((await otro.api('iniciar-sala',{codigo})).estadoHttp===403,'Solo el docente responsable inicia');
  comprobar(!(await docente.api('iniciar-sala',{codigo})).ok,'No inicia una sala vacía');
  const entrada=await alumno.api('unirse',{codigo,nombre:'Participante de prueba',avatar:4});comprobar(entrada.ok,'Registro sin cuenta');const token=entrada.token;
  const segundo=await alumno.api('unirse',{codigo,nombre:'Sin respuestas',avatar:2});comprobar(segundo.ok,'Segundo participante');
  comprobar((await docente.api('iniciar-sala',{codigo})).ok,'Iniciar evaluación');
  comprobar((await alumno.api(`estado?codigo=${codigo}`,null,token)).fase==='preparacion','Cuenta inicial de tres segundos');
  comprobar((await alumno.api('responder',{codigo,indice:0,opcion:0},token)).estadoHttp===409,'No responde durante preparación');
  function situar(segundos){ejecutarPhp(`EvaluaEIC\\Servicios\\BaseDatos::ejecutar('UPDATE salas_evaluacion SET inicio=? WHERE codigo=?',[microtime(true)-${segundos},'${codigo}']);`);}
  situar(3);
  const publico=await alumno.api(`estado?codigo=${codigo}`);comprobar(!('ranking' in publico)&&!('pregunta' in publico),'Sala pública sin preguntas ni ranking');
  const pregunta=await alumno.api(`estado?codigo=${codigo}`,null,token);comprobar(pregunta.fase==='pregunta'&&!('correcta' in pregunta.pregunta)&&!('explicacion' in pregunta.pregunta),'Solución oculta hasta el cierre');
  comprobar((await alumno.api('responder',{codigo,indice:1,opcion:1},token)).estadoHttp===409,'Rechaza pregunta futura');
  comprobar(pregunta.pregunta.opciones.length===2 && (await alumno.api('responder',{codigo,indice:0,opcion:2},token)).estadoHttp===422,'Rechaza una opción que no existe en la pregunta de dos opciones');
  comprobar((await alumno.api('responder',{codigo,indice:0,opcion:0},token)).ok,'Respuesta registrada');
  comprobar((await alumno.api('responder',{codigo,indice:0,opcion:1},token)).estadoHttp===409,'Respuesta bloqueada contra cambios');
  const antes=await alumno.api(`estado?codigo=${codigo}`,null,token);comprobar(antes.fase==='pregunta'&&!('puntos' in antes.respuesta)&&antes.participante.puntos===0,'Responder no avanza ni revela puntos');
  situar(16);
  const revelar=await alumno.api(`estado?codigo=${codigo}`,null,token);comprobar(revelar.fase==='revelacion'&&revelar.pregunta.correcta===0&&revelar.respuesta.puntos>500,'Revelación y bonificación del servidor');
  comprobar((await alumno.api('responder',{codigo,indice:0,opcion:0},segundo.token)).estadoHttp===409,'Rechaza respuestas fuera de tiempo');
  situar(19.1);const siguiente=await alumno.api(`estado?codigo=${codigo}`,null,token);comprobar(siguiente.indice===1&&siguiente.fase==='pregunta','Avanza tras cuatro segundos de revelación');
  comprobar(siguiente.pregunta.opciones.length===4 && (await alumno.api('responder',{codigo,indice:1,opcion:3},token)).ok,'Una pregunta histórica acepta la cuarta opción');
  situar(38.1);const final=await alumno.api(`estado?codigo=${codigo}`,null,token);comprobar(final.fase==='finalizada','Finaliza automáticamente');comprobar(final.ranking[0].porcentaje===100&&final.ranking[1].porcentaje===0,'Ranking por porcentaje');comprobar(final.ranking[1].puntos===0&&final.ranking[1].tiempo_total===30,'Ausentes con cero puntos y tiempo máximo');
  comprobar((await alumno.api('unirse',{codigo,nombre:'Tardío',avatar:0})).estadoHttp===409,'No permite registro al terminar');
  const detalle=await docente.api(`material?id=${id}`);await docente.api('eliminar-pregunta',{id:detalle.preguntas[0].id});comprobar((await alumno.api(`estado?codigo=${codigo}`,null,token)).total===2,'Las salas conservan preguntas históricas');
  await docente.api('eliminar-material',{id});comprobar((await alumno.api(`estado?codigo=${codigo}`,null,token)).ranking[0].porcentaje===100,'Eliminar material conserva historial');
  const idOtro=(await admin.api('usuarios')).usuarios.find(u=>u.usuario==='otra.docente').id;await admin.api('estado-usuario',{id:idOtro});comprobar((await otro.api('materiales')).estadoHttp===401,'Desactivar revoca el acceso existente');
  const puntos=ejecutarPhp('echo EvaluaEIC\\Servicios\\Evaluacion::puntos(true,5,20);');comprobar(puntos==='875','Fórmula: 5 de 20 segundos = 875 puntos');
  console.log(`Correcto: ${comprobaciones} comprobaciones del recorrido HTTP completo.`);
} finally {
  servidor.kill();await new Promise(resolve=>{if(servidor.exitCode!==null)resolve();else servidor.once('exit',resolve);});
  rmSync(path.join(raiz,'almacenamiento','documentos',tokenPrueba+'.pdf'),{force:true});
  // Solo borra la carpeta de prueba exacta creada por este proceso, dentro de almacenamiento.
  if(path.dirname(directorio)===path.join(raiz,'almacenamiento')&&path.basename(directorio).startsWith('pruebas-'))rmSync(directorio,{recursive:true,force:true});
}
