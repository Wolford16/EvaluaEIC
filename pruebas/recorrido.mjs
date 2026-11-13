// Prueba HTTP completa con una base MySQL desechable y vacía.
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {existsSync,mkdirSync,rmSync,writeFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import {setTimeout as esperar} from 'node:timers/promises';

const raiz=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.EVALUAEIC_PHP || ['C:/xampp/php/php.exe','C:/php/php.exe'].find(existsSync) || 'php';
const basePruebas=process.env.EVALUAEIC_BD_PRUEBAS;
if(!/^evaluaeic_pruebas(?:_\w+)?$/.test(basePruebas??''))throw new Error('Indica una base de pruebas vacía en EVALUAEIC_BD_PRUEBAS, por ejemplo evaluaeic_pruebas.');
const codigoInstalacion=randomBytes(24).toString('hex');
const entorno={...process.env,BD_NOMBRE:basePruebas,CLAVE_INSTALACION:codigoInstalacion,APLICACION_URL:'http://127.0.0.1:8091',OPENROUTER_API_KEY:'',OPENROUTER_MODEL:''};
if(ejecutarPhp("echo EvaluaEIC\\Servicios\\BaseDatos::ejecutar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();")!=='0')throw new Error('La base de pruebas debe estar vacía.');
const directorio=path.join(raiz,'almacenamiento',`pruebas-${Date.now()}`);
const tokenPrueba=randomBytes(24).toString('hex');
mkdirSync(directorio,{recursive:true});
mkdirSync(path.join(raiz,'almacenamiento','documentos'),{recursive:true});
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
  async api(accion,datos=null,token=''){
    const [operacion,consulta]=accion.split('?',2);
    const parametros=new URLSearchParams(consulta);
    parametros.set('api',operacion);
    const headers={Cookie:this.cookie,Accept:'application/json'};
    if(token)headers.Authorization=`Bearer ${token}`;
    const opciones={headers,method:datos===null?'GET':'POST'};
    if(datos!==null){
      const formulario=datos instanceof FormData ? datos : new URLSearchParams(
        Object.entries(datos).map(([campo,valor])=>[campo,Array.isArray(valor)?JSON.stringify(valor):valor])
      );
      formulario.set('csrf',this.csrf);
      opciones.body=formulario;
    }
    const r=await fetch(`http://127.0.0.1:8091/index.php?${parametros}`,opciones);
    this.actualizar(r);
    return {...await r.json(),estadoHttp:r.status};
  }
  async formulario(ruta,datos,solicitarJson=true){
    const r=await fetch(`http://127.0.0.1:8091${ruta}`,{
      method:'POST',headers:{Cookie:this.cookie,Accept:solicitarJson?'application/json':'text/html'},redirect:'manual',
      body:new URLSearchParams({csrf:this.csrf,...datos})
    });
    this.actualizar(r);
    return solicitarJson
      ? {...await r.json(),estadoHttp:r.status}
      : {estadoHttp:r.status,destino:r.headers.get('location'),html:await r.text()};
  }
  async descargar(ruta){
    const r=await fetch(`http://127.0.0.1:8091${ruta}`,{headers:{Cookie:this.cookie},redirect:'manual'});
    return {r,contenido:Buffer.from(await r.arrayBuffer())};
  }
  async ingresar(usuario,clave){
    await this.pagina('/ingreso');
    const r=await this.formulario('/index.php',{usuario,clave},false);
    comprobar(r.estadoHttp===303 && /^\/(admin|docente)$/.test(r.destino),'Ingreso válido por formulario PHP');
    comprobar((await this.pagina(r.destino)).r.status===200,'El formulario abre una sesión en el panel');
  }
}
try {
  comprobar(ejecutarPhp("echo EvaluaEIC\\Servicios\\BaseDatos::ejecutar('SELECT COUNT(*) FROM usuarios')->fetchColumn();")==='0','La instalación comienza sin cuentas de ejemplo');
  for(let intento=0;intento<30;intento++){try{await fetch('http://127.0.0.1:8091/ingreso');break;}catch{await esperar(100);}}
  const instalador=new Cliente(),instaladorAjeno=new Cliente();
  const ingresoInicial=await instalador.pagina('/ingreso');
  comprobar(ingresoInicial.html.includes('Primer acceso de administrador')&&ingresoInicial.html.includes('name="clave_instalacion"'),'El primer acceso pide el código de instalación');
  const diagnostico=await instalador.formulario('/index.php?verificar_bd=1',{clave_instalacion:codigoInstalacion},false);
  comprobar(diagnostico.estadoHttp===200 && diagnostico.html.includes(`MySQL/MariaDB, base ${basePruebas}`),'El diagnóstico nativo confirma la base MySQL seleccionada');
  await instaladorAjeno.pagina('/crear_administrador.php');
  comprobar((await instaladorAjeno.formulario('/crear_administrador.php',{accion:'guardar'})).estadoHttp===403,'Guardar el primer usuario exige haber abierto el primer acceso');
  const csrfInicial=instalador.csrf;
  instalador.csrf='invalido';
  comprobar((await instalador.formulario('/crear_administrador.php',{accion:'entrar',clave_instalacion:codigoInstalacion})).estadoHttp===403,'El botón de primer acceso comprueba la sesión');
  instalador.csrf=csrfInicial;
  comprobar((await instalador.formulario('/crear_administrador.php',{accion:'entrar',clave_instalacion:'incorrecto'},false)).estadoHttp===403,'El código incorrecto no abre el alta inicial');
  const primerAcceso=await instalador.formulario('/crear_administrador.php',{accion:'entrar',clave_instalacion:codigoInstalacion},false);
  comprobar(primerAcceso.estadoHttp===303 && primerAcceso.destino==='/crear_administrador.php','El formulario nativo abre la creación del administrador');
  const formularioInicial=await instalador.pagina(primerAcceso.destino);
  comprobar(formularioInicial.html.includes('name="nombre"')&&formularioInicial.html.includes('name="usuario"')&&!formularioInicial.html.includes('name="clave_instalacion"'),'El primer administrador solo necesita nombre, usuario y contraseña');
  comprobar((await instalador.api('usuarios')).estadoHttp===401,'El primer acceso está limitado a crear la cuenta real');
  const datosIniciales={accion:'guardar',nombre:'Administración inicial',usuario:'administracion.inicial',clave:'ClaveInicialÁ2026!'};
  comprobar((await instalador.formulario('/crear_administrador.php',{...datosIniciales,clave:'corta'})).estadoHttp===422 &&
    ejecutarPhp("echo EvaluaEIC\\Controladores\\Instalacion::completada()?'cerrada':'abierta';")==='abierta','Un dato inválido no cierra la instalación');
  const altaInicial=await instalador.formulario('/crear_administrador.php',datosIniciales,false);
  comprobar(altaInicial.estadoHttp===303&&altaInicial.destino==='/admin','La cuenta real se guarda y entra al panel directamente');
  const panelInicial=await instalador.pagina(altaInicial.destino);
  comprobar(panelInicial.r.status===200&&(await instalador.api('usuarios')).usuarios.length===1,'La sesión corresponde al único administrador guardado');
  comprobar(panelInicial.html.includes('method="post" action="/index.php?salir=1"'),'El panel ofrece el cierre de sesión mediante formulario PHP');
  comprobar(ejecutarPhp("echo password_verify('ClaveInicialÁ2026!',EvaluaEIC\\Servicios\\BaseDatos::ejecutar('SELECT clave_hash FROM usuarios')->fetchColumn())?'correcta':'incorrecta';")==='correcta','La contraseña se guarda como hash compatible y conserva los caracteres');
  const ingresoTrasAlta=await instaladorAjeno.pagina('/ingreso');
  comprobar(!ingresoTrasAlta.html.includes('Primer acceso de administrador')&&ingresoTrasAlta.html.includes('id="formulario-ingreso" method="post" action="/index.php"'),'El alta cerrada deja el formulario directo a PHP');
  const ingresoInvalido=await instaladorAjeno.formulario('/index.php',{usuario:datosIniciales.usuario,clave:'incorrecta'},false);
  comprobar(ingresoInvalido.estadoHttp===401 && ingresoInvalido.html.includes('Usuario o contraseña incorrectos'),'El formulario nativo informa credenciales inválidas');
  comprobar((await instaladorAjeno.formulario('/crear_administrador.php',{accion:'entrar'})).estadoHttp===409,'Otra sesión no puede reabrir el primer acceso');
  comprobar((await instalador.pagina('/index.php?salir=1')).r.status===405,'Abrir la dirección de salida no cierra la sesión');
  comprobar((await instalador.formulario('/index.php?salir=1',{csrf:'invalido'},false)).estadoHttp===403,'El cierre comprueba el código CSRF');
  comprobar((await instalador.pagina('/admin')).r.status===200,'Las solicitudes rechazadas conservan la sesión');
  const cookieAnterior=instalador.cookie;
  const salida=await instalador.formulario('/index.php?salir=1',{},false);
  comprobar(salida.estadoHttp===303 && salida.destino==='/ingreso' && instalador.cookie!==cookieAnterior,'El cierre elimina la cookie y redirige al ingreso');
  const sesionAnterior=new Cliente(); sesionAnterior.cookie=cookieAnterior;
  comprobar((await sesionAnterior.pagina('/admin')).r.headers.get('location')==='/ingreso','La sesión anterior ya no puede volver al panel');
  await instalador.ingresar(datosIniciales.usuario,datosIniciales.clave);
  // Solo en la base desechable del recorrido, comprueba el cierre permanente y restaura el estado vacío para probar el comando.
  ejecutarPhp('EvaluaEIC\\Servicios\\BaseDatos::ejecutar("DELETE FROM usuarios");');
  comprobar(!(await instaladorAjeno.pagina('/ingreso')).html.includes('Primer acceso de administrador'),'Eliminar todas las cuentas no vuelve a habilitar el primer acceso');
  ejecutarPhp("EvaluaEIC\\Servicios\\BaseDatos::ejecutar('DELETE FROM configuraciones WHERE nombre=?',['primer_administrador_creado']);");
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
  const {curso} = await docente.api('crear-curso', {nombre:'Curso reutilizable'});
  const cursoRepetido = await otro.api('crear-curso', {nombre:'curso   reutilizable'});
  comprobar(curso && cursoRepetido.curso.id === curso.id, 'El catálogo evita cursos duplicados');
  comprobar((await docente.api('actualizar-curso-material', {id, compartido:1})).estadoHttp === 422,
    'Compartir requiere asignar un curso');
  comprobar((await otro.api('crear-sala', {documento_id:id, tiempo:15})).estadoHttp === 403,
    'Un docente no puede aplicar un examen privado de otra cuenta');
  comprobar((await docente.api('actualizar-curso-material', {id, curso_id:curso.id, compartido:1})).ok,
    'El autor puede publicar un examen existente en un curso');
  const bibliotecaCompartida = await otro.api('materiales');
  const publicado = bibliotecaCompartida.documentos.find(d => Number(d.id) === id);
  comprobar(publicado && publicado.curso === curso.nombre && !publicado.puede_gestionar,
    'El examen compartido aparece en la biblioteca de otro docente');
  const detalleCompartido = await otro.api(`material?id=${id}`);
  comprobar(detalleCompartido.preguntas.length === originales.length && detalleCompartido.salas.length === 0
    && (await otro.pagina(`/archivo/${id}`)).r.status === 200,
    'Otro docente puede leer las preguntas y el PDF compartido, sin ver las salas del autor');
  comprobar((await otro.api('eliminar-pregunta', {id:originales[0].id})).estadoHttp === 403 &&
    (await otro.api('eliminar-material', {id})).estadoHttp === 403 &&
    (await otro.api('actualizar-curso-material', {id, curso_id:curso.id})).estadoHttp === 403,
    'El permiso de reutilizar no permite modificar el original');
  const salaCompartida = await otro.api('crear-sala', {documento_id:id, tiempo:15});
  comprobar(salaCompartida.ok && salaCompartida.codigo !== codigo &&
    (await otro.api(`material?id=${id}`)).salas.every(s => s.codigo === salaCompartida.codigo) &&
    (await docente.api(`estado?codigo=${salaCompartida.codigo}`)).anfitrion === false,
    'Cada docente conserva su propia sala al aplicar el mismo examen');
  const copiaPrivada = await otro.api('copiar-material', {id});
  const detalleCopia = await otro.api(`material?id=${copiaPrivada.id}`);
  comprobar(copiaPrivada.ok && detalleCopia.documento.puede_gestionar && Number(detalleCopia.documento.compartido) === 0 &&
    detalleCopia.salas.length === 0 && detalleCopia.preguntas.length === originales.length &&
    (await docente.pagina(`/archivo/${copiaPrivada.id}`)).r.status === 403,
    'La copia incluye preguntas y PDF y queda privada para el nuevo propietario');
  comprobar((await otro.api('eliminar-material', {id:copiaPrivada.id})).ok &&
    (await docente.pagina(`/archivo/${id}`)).r.status === 200,
    'Eliminar la copia privada conserva el PDF original');
  comprobar((await docente.api('actualizar-curso-material', {id, curso_id:curso.id, compartido:0})).ok &&
    (await otro.api(`material?id=${id}`)).estadoHttp === 403 &&
    (await otro.api(`estado?codigo=${salaCompartida.codigo}`)).anfitrion === true,
    'Retirar el examen de la biblioteca conserva las salas que ya se crearon');
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
  const rutaInforme=`/resultados/${codigo}.pdf`;
  const rutaExcel=`/resultados/${codigo}.xlsx`;
  const rutaFicha=`/resultados/${codigo}/${idAlumno}.pdf`;
  comprobar((await docente.descargar(rutaInforme)).r.status===409,'El informe se habilita al finalizar la evaluación');
  comprobar((await docente.descargar(rutaFicha)).r.status===409 && (await docente.descargar(rutaExcel)).r.status===409,
    'Las nuevas descargas esperan el cierre de la sala');
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
  comprobar((await alumno.descargar(rutaInforme)).r.status===401 && (await otro.descargar(rutaInforme)).r.status===403,'El informe protege los resultados del grupo');
  const informe=await docente.descargar(rutaInforme);
  comprobar(informe.r.ok && informe.r.headers.get('content-type')==='application/pdf' &&
    informe.r.headers.get('content-disposition')===`attachment; filename="resultados-${codigo}.pdf"` &&
    informe.contenido.subarray(0,5).toString()==='%PDF-','El docente descarga un PDF válido de la sala');
  const archivoInforme=path.join(directorio,'informe.pdf');
  writeFileSync(archivoInforme,informe.contenido);
  const rutaCodificada=Buffer.from(archivoInforme).toString('base64');
  const textoInforme=ejecutarPhp(`echo (new Smalot\\PdfParser\\Parser())->parseFile(base64_decode('${rutaCodificada}'))->getText();`);
  comprobar(textoInforme.includes('ESCUELA DE INVESTIGACIÓN CRIMINAL') && textoInforme.includes('Material de prueba') &&
    textoInforme.includes('Firma del docente'),'El PDF incluye el encabezado institucional, el título y la firma');
  const datosInforme=JSON.parse(ejecutarPhp(`
    $datos = EvaluaEIC\\Servicios\\Evaluacion::resultadosInforme('${codigo}', ['rol'=>'admin']);
    echo json_encode($datos['filas']);
  `));
  const falloInforme=datosInforme.find(p=>p.nombre==='Respuesta incorrecta');
  comprobar(falloInforme.incorrectas===1 && falloInforme.sin_responder===1 && datosInforme[0].puntos===final.ranking[0].puntos,
    'El informe distingue errores de ausencias y conserva el puntaje de la sala');
  const idFallos=falloInforme.id;
  const fichaFallos=`/resultados/${codigo}/${idFallos}`;
  comprobar((await alumno.descargar(`${fichaFallos}.pdf`)).r.status===401 &&
    (await otro.descargar(`${fichaFallos}.xlsx`)).r.status===403 && (await otro.descargar(rutaExcel)).r.status===403,
    'Los informes nuevos protegen las respuestas individuales y del grupo');
  comprobar((await docente.descargar(`/resultados/${codigo}/999999.pdf`)).r.status===404,
    'La ficha solo admite participantes de la sala');
  const ficha=await docente.descargar(`${fichaFallos}.pdf`);
  comprobar(ficha.r.ok && ficha.r.headers.get('content-disposition')===`attachment; filename="respuestas-${codigo}-${idFallos}.pdf"`,
    'El docente descarga la ficha PDF del participante elegido');
  writeFileSync(archivoInforme,ficha.contenido);
  const textoFicha=ejecutarPhp(`echo (new Smalot\\PdfParser\\Parser())->parseFile(base64_decode('${rutaCodificada}'))->getText();`);
  comprobar(['Material de prueba','ESCUELA DE INVESTIGACIÓN CRIMINAL','Participante: Respuesta incorrecta','Incorrecta','Sin responder']
    .every(texto=>textoFicha.includes(texto)), 'La ficha contiene el encabezado y distingue los errores de las ausencias');
  const datosFicha=JSON.parse(ejecutarPhp(`
    echo json_encode(EvaluaEIC\\Servicios\\Evaluacion::resultadosInforme('${codigo}', ['rol'=>'admin'], ${idFallos}));
  `));
  comprobar(datosFicha.filas[0].respuesta.endsWith(copia[0].opciones[errorElegido]) &&
    datosFicha.filas[0].correcta.endsWith(copia[0].opciones[copia[0].correcta]) &&
    datosFicha.filas[1].resultado==='Sin responder', 'La ficha utiliza el orden de opciones guardado en la sala');
  for (const [cliente, ruta, nombre] of [
    [docente,rutaExcel,`resultados-${codigo}.xlsx`],
    [admin,`${fichaFallos}.xlsx`,`respuestas-${codigo}-${idFallos}.xlsx`],
  ]) {
    const excel=await cliente.descargar(ruta);
    comprobar(excel.r.ok && excel.r.headers.get('content-type')==='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' &&
      excel.r.headers.get('content-disposition')===`attachment; filename="${nombre}"` &&
      excel.contenido.subarray(0,4).equals(Buffer.from([0x50,0x4b,3,4])), 'Descarga el archivo XLSX del alcance seleccionado');
  }
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

  const manual = {
    token: randomBytes(24).toString('hex'), curso_id: curso.id, compartido: 1, titulo: 'Examen de tres tipos',
    preguntas: [
      {tipo:'opcion_multiple', enunciado:'¿Cuál es un número par?', opciones:['1','2','3','5'], correcta:1},
      {tipo:'verdadero_falso', enunciado:'El agua es una sustancia.', correcta:0},
      {tipo:'completacion', enunciado:'Escribe el resultado de seis más seis.', respuestas_aceptadas:['doce','12']},
    ],
  };
  const incompleto = {...manual, preguntas:[{...manual.preguntas[2], respuestas_aceptadas:['doce',' DOCE ']}]};
  comprobar((await admin.api('crear-examen-manual', incompleto)).estadoHttp===422, 'Rechaza variantes equivalentes en completación');
  const examenManual = await admin.api('crear-examen-manual', manual);
  const manualId = examenManual.id;
  comprobar(examenManual.ok && (await admin.api('crear-examen-manual', manual)).id === manualId &&
    (await admin.pagina(`/archivo/${manualId}`)).r.status === 404,
    'Crea un examen sin PDF ni OpenRouter y guarda una sola copia al reintentar');
  const copiaManual = await nuevoDocente.api('copiar-material', {id:manualId});
  const preguntasCopia = (await nuevoDocente.api(`material?id=${copiaManual.id}`)).preguntas;
  comprobar(copiaManual.ok && preguntasCopia.length === 3 && preguntasCopia[2].respuestas_aceptadas.join(',') === 'doce,12',
    'Copiar un examen manual compartido conserva sus tipos y respuestas aceptadas');
  comprobar((await nuevoDocente.api('editar-examen-manual', {...manual,id:manualId})).estadoHttp === 403 &&
    (await nuevoDocente.api('editar-examen-manual', {...manual,id:copiaManual.id,compartido:0})).ok,
    'Solo el responsable puede editar el examen manual o su propia copia');
  const salaManual = await nuevoDocente.api('crear-sala', {documento_id:copiaManual.id,tiempo:15});
  const codigoManual = salaManual.codigo;
  const congeladas = leerCopia(codigoManual);
  comprobar(congeladas.length === 3 && congeladas.some(p => p.tipo === 'verdadero_falso' && p.opciones.join(',') === 'Verdadero,Falso'),
    'Una sala admite preguntas mixtas y conserva el orden de verdadero o falso');
  const editadas = manual.preguntas.map(p => p.tipo === 'completacion' ? {...p,respuestas_aceptadas:['diferente']} : p);
  comprobar((await nuevoDocente.api('editar-examen-manual', {...manual,id:copiaManual.id,compartido:0,preguntas:editadas})).ok &&
    JSON.stringify(leerCopia(codigoManual)) === JSON.stringify(congeladas),
    'Editar el banco manual conserva las preguntas de las salas ya creadas');
  const personaManual = await alumno.api('unirse',{codigo:codigoManual,nombre:'Participante manual',avatar:1});
  const personaFallos = await alumno.api('unirse',{codigo:codigoManual,nombre:'Prueba de faltas',avatar:0});
  comprobar(personaManual.ok && personaFallos.ok && (await nuevoDocente.api('iniciar-sala',{codigo:codigoManual})).ok,
    'Abre una sala para un examen manual reutilizado');
  const indiceTexto = congeladas.findIndex(p => p.tipo === 'completacion');
  for (const [indice,pregunta] of congeladas.entries()) {
    ejecutarPhp(`EvaluaEIC\\Servicios\\BaseDatos::ejecutar('UPDATE salas_evaluacion SET inicio=? WHERE codigo=?',[microtime(true)-${indice*19+1},'${codigoManual}']);`);
    const vistaManual = await alumno.api(`estado?codigo=${codigoManual}`,null,personaManual.token);
    comprobar(vistaManual.fase === 'pregunta' && !('respuestas_aceptadas' in vistaManual.pregunta) &&
      !('correcta' in vistaManual.pregunta), 'La sala protege las soluciones durante la pregunta');
    const respuesta = indice === indiceTexto ? {texto:'  DOCE  '} : {opcion:pregunta.correcta};
    comprobar((await alumno.api('responder',{codigo:codigoManual,indice,...respuesta},personaManual.token)).ok,
      'La sala acepta la respuesta del tipo correcto');
    if (indice === indiceTexto) {
      comprobar((await alumno.api('responder',{codigo:codigoManual,indice,texto:'  '},personaFallos.token)).estadoHttp === 422 &&
        (await alumno.api('responder',{codigo:codigoManual,indice,texto:'Once'},personaFallos.token)).ok &&
        (await alumno.api('responder',{codigo:codigoManual,indice,texto:'12'},personaFallos.token)).estadoHttp === 409,
        'Valida el texto, registra el error y bloquea el segundo envío');
      comprobar((await nuevoDocente.api(`estado?codigo=${codigoManual}`)).respondieron === 2, 'Las respuestas de texto cuentan como respondidas');
    }
  }
  ejecutarPhp(`EvaluaEIC\\Servicios\\BaseDatos::ejecutar('UPDATE salas_evaluacion SET inicio=? WHERE codigo=?',[microtime(true)-60,'${codigoManual}']);`);
  const resultadosManual = await alumno.api(`estado?codigo=${codigoManual}`,null,personaManual.token);
  const respuestasManual = await alumno.api(`respuestas?codigo=${codigoManual}`,null,personaManual.token);
  const respuestaManual = respuestasManual.preguntas[indiceTexto];
  comprobar(resultadosManual.participante.aciertos === 3 && respuestaManual.elegida === 'DOCE' && respuestaManual.acierto &&
    respuestaManual.respuestas_aceptadas.includes('doce'), 'Normaliza el texto y conserva la respuesta escrita en el historial');
  const resumenManual = JSON.parse(ejecutarPhp(`echo json_encode(EvaluaEIC\\Servicios\\Evaluacion::resultadosInforme('${codigoManual}',['rol'=>'admin'])['filas']);`));
  const erroresManuales = resumenManual.find(fila => fila.nombre === 'Prueba de faltas');
  comprobar(erroresManuales.incorrectas === 1 && erroresManuales.sin_responder === 2,
    'El informe distingue una completación incorrecta de las preguntas sin responder');
  const informeManual = await nuevoDocente.descargar(`/index.php?informe_pdf=${codigoManual}&participante=${resultadosManual.participante.id}`);
  const excelManual = await nuevoDocente.descargar(`/resultados/${codigoManual}/${resultadosManual.participante.id}.xlsx`);
  comprobar(informeManual.r.ok && informeManual.contenido.subarray(0,5).toString() === '%PDF-' &&
    excelManual.r.ok && excelManual.contenido.readUInt32LE(0) === 0x04034b50,
    'El examen mixto se puede descargar en PDF y Excel');
  writeFileSync(archivoInforme, informeManual.contenido);
  const textoManual = ejecutarPhp(`echo (new Smalot\\PdfParser\\Parser())->parseFile(base64_decode('${rutaCodificada}'))->getText();`);
  comprobar(textoManual.includes('DOCE') && textoManual.includes('doce / 12'),
    'La ficha PDF contiene la respuesta escrita y sus variantes aceptadas');

  const entradaCurso = await nuevoDocente.api('crear-sala', {documento_id:copiaManual.id, tiempo:20, entrada:1});
  const codigoEntrada = entradaCurso.codigo;
  comprobar(entradaCurso.ok && (await nuevoDocente.api('crear-salida',{codigo:codigoEntrada})).estadoHttp === 409 &&
    (await otro.api('crear-salida',{codigo:codigoEntrada})).estadoHttp === 403,
    'Solo el responsable puede abrir la salida de una entrada finalizada');
  const primeraEntrada = await alumno.api('unirse',{codigo:codigoEntrada,nombre:'Mismo nombre',avatar:1});
  const segundaEntrada = await alumno.api('unirse',{codigo:codigoEntrada,nombre:'Mismo nombre',avatar:2});
  const codigoPrivado = async persona => (await alumno.api(`estado?codigo=${codigoEntrada}`,null,persona.token)).codigo_personal;
  const personal = await codigoPrivado(primeraEntrada);
  comprobar(/^[0-9A-F]{16}$/.test(personal) && personal !== await codigoPrivado(segundaEntrada) &&
    !('codigo_personal' in await nuevoDocente.api(`estado?codigo=${codigoEntrada}`)),
    'Cada participante recibe un código personal aunque comparta nombre, y el código se entrega solo a su sesión');
  comprobar((await nuevoDocente.api('iniciar-sala',{codigo:codigoEntrada})).ok, 'Se puede iniciar la etapa de entrada');
  const situarEtapa = (codigoSala, segundos) => ejecutarPhp(
    `EvaluaEIC\\Servicios\\BaseDatos::ejecutar('UPDATE salas_evaluacion SET inicio=? WHERE codigo=?',[microtime(true)-${segundos},'${codigoSala}']);`);
  situarEtapa(codigoEntrada, 74);
  comprobar((await nuevoDocente.api(`comparacion?codigo=${codigoEntrada}`)).estadoHttp === 409,
    'La comparación espera la salida');
  const versionEntrada = leerCopia(codigoEntrada);
  comprobar((await nuevoDocente.api('editar-examen-manual', {...manual,id:copiaManual.id,compartido:0,preguntas:manual.preguntas.slice(0,1)})).ok,
    'El banco puede cambiar entre las dos etapas');
  const [salidaCurso, repetida] = await Promise.all([
    nuevoDocente.api('crear-salida',{codigo:codigoEntrada}), nuevoDocente.api('crear-salida',{codigo:codigoEntrada}),
  ]);
  const codigoSalida = salidaCurso.codigo;
  const vistaSalida = await alumno.api(`estado?codigo=${codigoSalida}`);
  comprobar(salidaCurso.ok && repetida.codigo === codigoSalida && JSON.stringify(leerCopia(codigoSalida)) === JSON.stringify(versionEntrada) &&
    vistaSalida.etapa === 'Salida' && vistaSalida.tiempo === 20,
    'La salida reutiliza la copia y el tiempo de la entrada, incluso con dos solicitudes simultáneas');
  comprobar((await alumno.api('unirse',{codigo:codigoSalida,nombre:'Mismo nombre'})).estadoHttp === 422 &&
    (await alumno.api('unirse',{codigo:codigoSalida,codigo_personal:'FFFFFFFFFFFFFFFF'})).estadoHttp === 422,
    'La salida distingue el código inválido de la inscripción explícita sin entrada');
  const vinculada = await alumno.api('unirse',{codigo:codigoSalida,codigo_personal:personal.match(/.{1,4}/g).join('-').toLowerCase()});
  const sinEntrada = await alumno.api('unirse',{codigo:codigoSalida,nombre:'Solo salida',solo_salida:1});
  comprobar(vinculada.ok && sinEntrada.ok && (await alumno.api('unirse',{codigo:codigoSalida,codigo_personal:personal})).estadoHttp === 409,
    'La salida permite vincular el código una vez y registrar a quienes solo cursan esa etapa');
  comprobar((await nuevoDocente.api('iniciar-sala',{codigo:codigoSalida})).ok, 'Se puede iniciar la etapa de salida');
  for (const [indice, pregunta] of versionEntrada.entries()) {
    situarEtapa(codigoSalida, indice * 24 + 1);
    const respuesta = pregunta.tipo === 'completacion' ? {texto:pregunta.respuestas_aceptadas[0]} : {opcion:pregunta.correcta};
    comprobar((await alumno.api('responder',{codigo:codigoSalida,indice,...respuesta},vinculada.token)).ok,
      'Se registran las respuestas de salida con la copia original');
  }
  situarEtapa(codigoSalida, 74);
  const enlaceComparacion = `/index.php?comparacion=${codigoEntrada}`;
  const informeComparado = await nuevoDocente.api(`comparacion?codigo=${codigoEntrada}`);
  const personas = informeComparado.participantes;
  comprobar(personas.length === 3 && personas[0].diferencia === 100 && personas[1].nombre === personas[0].nombre &&
    personas[1].salida === null && personas[1].diferencia === null && personas[2].entrada === null,
    'La comparación vincula por código y separa pendientes de un resultado de cero');
  comprobar((await otro.descargar(enlaceComparacion)).r.status === 403 &&
    (await alumno.descargar(enlaceComparacion)).r.status === 401 &&
    (await nuevoDocente.descargar(enlaceComparacion+'&participante=999999')).r.status === 404,
    'La comparación está restringida al responsable y a participantes de esas salas');
  for (const [etapa,codigoEtapa] of [['Entrada',codigoEntrada],['Salida',codigoSalida]]) {
    const listado = await nuevoDocente.descargar(`/index.php?informe_pdf=${codigoEtapa}`);
    const hoja = await nuevoDocente.descargar(`/resultados/${codigoEtapa}.xlsx`);
    comprobar(listado.r.ok && listado.contenido.subarray(0,5).toString() === '%PDF-' && hoja.r.ok &&
      hoja.contenido.readUInt32LE(0) === 0x04034b50, `Se descargan los resultados de ${etapa} en PDF y Excel`);
    writeFileSync(archivoInforme,listado.contenido);
    const textoEtapa = ejecutarPhp(`echo (new Smalot\\PdfParser\\Parser())->parseFile(base64_decode('${rutaCodificada}'))->getText();`);
    comprobar(textoEtapa.includes(etapa) && textoEtapa.includes('Resultados por pregunta'), `El informe grupal de ${etapa} incluye el detalle por pregunta`);
    const idEtapa = etapa === 'Entrada' ? personas[0].id :
      (await alumno.api(`estado?codigo=${codigoSalida}`,null,vinculada.token)).participante.id;
    const fichaEtapa = await nuevoDocente.descargar(`/index.php?informe_pdf=${codigoEtapa}&participante=${idEtapa}`);
    const excelEtapa = await nuevoDocente.descargar(`/resultados/${codigoEtapa}/${idEtapa}.xlsx`);
    comprobar(fichaEtapa.r.ok && fichaEtapa.contenido.subarray(0,5).toString() === '%PDF-' && excelEtapa.r.ok &&
      excelEtapa.contenido.readUInt32LE(0) === 0x04034b50, `Se descarga la ficha individual de ${etapa} en PDF y Excel`);
  }
  for (const [filtro, esperado] of [['', 'Aciertos por pregunta'],[`&participante=${personas[0].id}`,'Sin responder']]) {
    const pdf = await nuevoDocente.descargar(enlaceComparacion + filtro);
    const xlsx = await nuevoDocente.descargar(enlaceComparacion + '&formato=xlsx' + filtro);
    comprobar(pdf.r.ok && pdf.contenido.subarray(0,5).toString() === '%PDF-' &&
      xlsx.r.ok && xlsx.contenido.readUInt32LE(0) === 0x04034b50, 'Se descarga la comparación en PDF y Excel');
    writeFileSync(archivoInforme,pdf.contenido);
    const texto = ejecutarPhp(`echo (new Smalot\\PdfParser\\Parser())->parseFile(base64_decode('${rutaCodificada}'))->getText();`);
    comprobar(texto.includes(esperado) && texto.includes('+100 pp'), 'El PDF comparativo incluye el cambio y el detalle correspondiente');
  }

  await admin.api('eliminar-material',{id});comprobar((await alumno.api(`estado?codigo=${codigo}`,null,token)).ranking[0].porcentaje===100,'Eliminar material conserva historial');
  comprobar((await admin.api(peticionHistorial)).preguntas.length===2,'El historial de respuestas sobrevive a la eliminación del material');
  comprobar((await admin.descargar(rutaInforme)).r.status===200,'El administrador descarga informes aunque se haya eliminado el documento');
  comprobar((await admin.descargar(`${fichaFallos}.xlsx`)).r.status===200,'La ficha individual conserva las respuestas al eliminar el documento');
  const idOtro=(await admin.api('usuarios')).usuarios.find(u=>u.usuario==='otra.docente').id;await admin.api('estado-usuario',{id:idOtro});comprobar((await otro.api('materiales')).estadoHttp===401,'Desactivar revoca el acceso existente');
  const puntos=ejecutarPhp('echo EvaluaEIC\\Servicios\\Evaluacion::puntos(true,5,20);');comprobar(puntos==='875','Fórmula: 5 de 20 segundos = 875 puntos');
  console.log(`Correcto: ${comprobaciones} comprobaciones del recorrido HTTP completo.`);
} finally {
  servidor.kill();await new Promise(resolve=>{if(servidor.exitCode!==null)resolve();else servidor.once('exit',resolve);});
  rmSync(path.join(raiz,'almacenamiento','documentos',tokenPrueba+'.pdf'),{force:true});
  // Solo borra la carpeta de prueba exacta creada por este proceso, dentro de almacenamiento.
  if(path.dirname(directorio)===path.join(raiz,'almacenamiento')&&path.basename(directorio).startsWith('pruebas-'))rmSync(directorio,{recursive:true,force:true});
}
