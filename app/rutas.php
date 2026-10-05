<?php
declare(strict_types=1);

/**
 * Tabla de rutas.
 *
 * Toda la superficie que la plataforma expone, en un solo archivo y con el
 * guardia de cada ruta a la vista. Está escrito así a propósito: para revisar
 * qué puede tocar cada rol basta leer esta columna, sin ir clase por clase.
 *
 * Guardias:
 *   (ninguno)              público
 *   'asistente'            exige sesión de asistente
 *   'acreditar'            equipo operador, o persona con perfil Staff
 *   'admin:consulta'       equipo, cualquier rol
 *   'admin:operador'       operador o administrador
 *   'admin:administrador'  solo administrador
 *   'instalar'             solo mientras no haya instalación terminada
 *
 * @var App\Nucleo\Enrutador $enrutador
 */

defined('EVENTOS_TIC') || exit;

use App\Controladores\Acceso;
use App\Controladores\Admin;
use App\Controladores\Carnet;
use App\Controladores\Contactos;
use App\Controladores\Cuenta;
use App\Controladores\Escaneo;
use App\Controladores\Instalador;
use App\Controladores\Medios;
use App\Controladores\Publico;

/* =========================================================================
   Instalación
   ========================================================================= */
$enrutador->ambos('/instalar', [Instalador::class, 'asistente'], 'instalar');

// El resumen final queda accesible aunque la instalación ya esté cerrada: solo
// muestra lo que se hizo y los siguientes pasos, sin ejecutar nada.
$enrutador->get('/instalar/listo', [Instalador::class, 'terminado']);

// Diagnóstico. Sin guardia en la tabla porque él mismo decide: mientras la
// plataforma no funcione es público —igual que el asistente, y sin él no hay
// forma de saber qué falta—, y en cuanto funciona exige ser administrador.
$enrutador->get('/instalar/diagnostico', [Instalador::class, 'diagnostico']);

/* =========================================================================
   Público — no exige identificarse
   ========================================================================= */
$enrutador->get('/', [Publico::class, 'inicio']);
// El formulario completo, siempre abierto al público. «/preregistro» se
// conserva porque está en correos ya enviados y en enlaces compartidos.
$enrutador->ambos('/registro', [Publico::class, 'registro']);
$enrutador->ambos('/preregistro', [Publico::class, 'registro']);
$enrutador->get('/agenda', [Publico::class, 'agenda']);
$enrutador->get('/agenda/{id:num}', [Publico::class, 'charla']);
$enrutador->get('/municipios/{departamento:texto}', [Publico::class, 'municipios']);

/* =========================================================================
   Acceso del asistente — correo y código de un solo uso
   ========================================================================= */
$enrutador->ambos('/entrar', [Acceso::class, 'asistente']);
$enrutador->ambos('/entrar/codigo', [Acceso::class, 'codigo']);

// Crear el acceso con solo correo y contraseña. El resto del formulario se
// llena después, ya dentro: es la puerta corta para quien llega a «entrar»
// sin estar inscrito.
$enrutador->ambos('/entrar/crear', [Acceso::class, 'crearCuenta']);

// Acceso por QR personal. Sin guardia: identificarse es justamente lo que hace.
// El token es de 128 bits y hay límite por IP contra la enumeración.
$enrutador->get('/entrar/qr/{token:token}', [Acceso::class, 'porQr']);
$enrutador->post('/salir', [Acceso::class, 'salirAsistente']);

/* =========================================================================
   Códigos QR
   -------------------------------------------------------------------------
   Estas dos rutas son las que se abren desde la cámara del teléfono, sin
   ningún contexto previo. Si no hay sesión no devuelven un error: llevan al
   acceso que corresponde y, al terminar, continúan con lo que la persona
   venía a hacer.
   ========================================================================= */

// Código de la jornada, pegado en la entrada. Registra el ingreso propio.
$enrutador->get('/d/{token:token}', [Escaneo::class, 'codigoDelDia'], 'asistente');

// Carnet de una persona. Quién lo escanea decide qué pasa:
// el equipo sella su ingreso, otro asistente intercambia contacto.
$enrutador->get('/c/{token:token}', [Escaneo::class, 'carnetAjeno']);
$enrutador->post('/c/{token:token}/contacto', [Escaneo::class, 'guardarContacto'], 'asistente');
$enrutador->post('/c/{token:token}/asistencia', [Escaneo::class, 'sellarAsistencia'], 'acreditar');

/* =========================================================================
   Asistente identificado
   ========================================================================= */
$enrutador->get('/carnet', [Carnet::class, 'ver'], 'asistente');
$enrutador->get('/carnet/imprimir', [Carnet::class, 'imprimir'], 'asistente');
$enrutador->post('/carnet/dispositivos', [Carnet::class, 'cerrarDispositivos'], 'asistente');

/* =========================================================================
   Acreditación — el equipo y el Staff
   -------------------------------------------------------------------------
   Las mismas pantallas para los dos. El equipo llega por /admin/escaner desde
   su panel; el Staff por /acreditar, que es lo que ve en su navegación.
   ========================================================================= */
$enrutador->get('/acreditar', [Escaneo::class, 'pantallaAcreditar'], 'acreditar');
$enrutador->post('/acreditar/buscar', [Escaneo::class, 'buscar'], 'acreditar');

// Todos los carnets del evento: para consultarlos e imprimirlos en tanda.
$enrutador->get('/carnets', [Carnet::class, 'lista'], 'acreditar');
$enrutador->get('/carnets/imprimir', [Carnet::class, 'imprimirTodos'], 'acreditar');
$enrutador->get('/checkin', [Escaneo::class, 'pantallaCheckin'], 'asistente');
$enrutador->get('/contactos', [Contactos::class, 'listar'], 'asistente');
$enrutador->post('/contactos/privacidad', [Contactos::class, 'privacidad'], 'asistente');
$enrutador->get('/contactos/exportar', [Contactos::class, 'exportar'], 'asistente');

/* =========================================================================
   Equipo organizador
   ========================================================================= */
$enrutador->ambos('/admin/entrar', [Acceso::class, 'equipo']);
$enrutador->ambos('/admin/verificar', [Acceso::class, 'verificarSegundoFactor']);
$enrutador->ambos('/admin/activar-2fa', [Acceso::class, 'activarSegundoFactor']);
$enrutador->post('/admin/salir', [Acceso::class, 'salirEquipo']);

// Sin guardia: quien tiene que cambiar la contraseña obligatoriamente todavía
// no ha pasado el guardia del panel, y el propio método comprueba la sesión.
$enrutador->ambos('/admin/clave', [Acceso::class, 'cambiarClave']);

$enrutador->get('/admin', [Admin::class, 'panel'], 'admin:consulta');

// «Configuración»: la cuenta propia. Sobre todo, restablecer el código QR de la
// verificación en dos pasos sin depender de alguien con acceso al servidor.
$enrutador->ambos('/admin/cuenta', [Cuenta::class, 'ver'], 'admin:consulta');

// Aplica al esquema lo que falte para esta versión del código. Solo agrega
// tablas y columnas; nunca borra. Ver Esquema::revisionPendiente().
$enrutador->post('/admin/actualizar-esquema', [Admin::class, 'actualizarEsquema'], 'admin:administrador');

// La pantalla de la puerta. Es la misma para el equipo y para el Staff —de ahí
// el guardia 'acreditar' y las dos direcciones—, y vive en Escaneo porque dejó
// de ser del backoffice el día que una persona con perfil Staff pudo usarla.
$enrutador->get('/admin/escaner', [Escaneo::class, 'pantallaAcreditar'], 'acreditar');
$enrutador->post('/admin/escaner/buscar', [Escaneo::class, 'buscar'], 'acreditar');

$enrutador->get('/admin/registros', [Admin::class, 'registros'], 'admin:consulta');
$enrutador->get('/admin/registros/exportar', [Admin::class, 'exportar'], 'admin:consulta');

// Ficha de una persona. Ver los datos de alguien queda en la bitácora, igual
// que la pantalla de acreditación.
$enrutador->get('/admin/registros/{persona:num}', [Admin::class, 'ficha'], 'admin:consulta');
$enrutador->post('/admin/registros/clave', [Admin::class, 'restablecerClave'], 'admin:administrador');
$enrutador->post('/admin/registros/qr', [Admin::class, 'regenerarQrAcceso'], 'admin:administrador');

// El perfil de asistencia. Exige administrador porque por aquí se pone «Staff»,
// que da acceso a los carnets de todo el evento y a sellar ingresos.
$enrutador->post('/admin/registros/perfil', [Admin::class, 'cambiarPerfil'], 'admin:administrador');

$enrutador->get('/admin/qr-dias', [Admin::class, 'codigosDia'], 'admin:operador');
$enrutador->get('/admin/qr-dias/{numero:num}/imprimir', [Admin::class, 'imprimirCodigo'], 'admin:operador');
$enrutador->post('/admin/qr-dias/rotar', [Admin::class, 'rotarCodigo'], 'admin:administrador');
$enrutador->post('/admin/qr-dias/agregar', [Admin::class, 'agregarJornada'], 'admin:administrador');
$enrutador->post('/admin/qr-dias/eliminar', [Admin::class, 'eliminarJornada'], 'admin:administrador');
$enrutador->post('/admin/qr-dias/ajustar', [Admin::class, 'ajustarJornada'], 'admin:administrador');

$enrutador->get('/admin/expositores', [Admin::class, 'expositores'], 'admin:administrador');
$enrutador->post('/admin/expositores/decidir', [Admin::class, 'decidirPropuesta'], 'admin:administrador');

$enrutador->get('/admin/organizadores', [Admin::class, 'organizadores'], 'admin:administrador');
$enrutador->post('/admin/organizadores/crear', [Admin::class, 'crearUsuario'], 'admin:administrador');
$enrutador->post('/admin/organizadores/estado', [Admin::class, 'cambiarEstadoUsuario'], 'admin:administrador');
$enrutador->post('/admin/organizadores/rol', [Admin::class, 'cambiarRolUsuario'], 'admin:administrador');
$enrutador->post('/admin/organizadores/segundo-factor', [Admin::class, 'restablecerSegundoFactor'], 'admin:administrador');

$enrutador->get('/admin/eventos', [Admin::class, 'eventos'], 'admin:administrador');
$enrutador->post('/admin/eventos/crear', [Admin::class, 'crearEvento'], 'admin:administrador');
$enrutador->post('/admin/eventos/activar', [Admin::class, 'activarEvento'], 'admin:administrador');
$enrutador->post('/admin/eventos/editar', [Admin::class, 'editarEvento'], 'admin:administrador');
$enrutador->post('/admin/eventos/desactivar', [Admin::class, 'desactivarEvento'], 'admin:administrador');

// Eliminar se lleva por delante los registros de las personas y no hay
// deshacer: el propio método exige que se escriba ELIMINAR.
$enrutador->post('/admin/eventos/eliminar', [Admin::class, 'eliminarEvento'], 'admin:administrador');

$enrutador->get('/admin/identidad', [Admin::class, 'identidad'], 'admin:administrador');
$enrutador->post('/admin/identidad', [Admin::class, 'guardarIdentidad'], 'admin:administrador');

// Autenticación. Solo administrador: aquí se decide cómo entra la gente al
// evento y se guardan las credenciales con las que la plataforma envía en
// nombre de la Gobernación.
$enrutador->get('/admin/autenticacion', [Admin::class, 'correo'], 'admin:administrador');
$enrutador->post('/admin/autenticacion', [Admin::class, 'guardarAutenticacion'], 'admin:administrador');
// La dirección anterior sigue viva: hay enlaces y marcadores hechos con ella.
$enrutador->get('/admin/correo', [Admin::class, 'correo'], 'admin:administrador');
$enrutador->post('/admin/correo', [Admin::class, 'guardarCorreo'], 'admin:administrador');
$enrutador->post('/admin/correo/probar', [Admin::class, 'probarCorreo'], 'admin:administrador');
$enrutador->post('/admin/correo/red', [Admin::class, 'probarRedCorreo'], 'admin:administrador');
$enrutador->post('/admin/correo/local', [Admin::class, 'usarCorreoLocal'], 'admin:administrador');

/* =========================================================================
   Archivos subidos
   -------------------------------------------------------------------------
   Nunca se sirven directamente desde el disco: pasan por PHP para que el tipo
   lo decida el servidor y no la extensión del archivo. Ver docs/SEGURIDAD.md.
   ========================================================================= */
$enrutador->get('/medios/logo/{evento:num}', [Medios::class, 'logo']);

// Sin guardia en la tabla: la foto de una persona la puede ver ella misma y el
// equipo organizador, y eso lo decide el propio método. Con un guardia fijo,
// una de las dos mitades quedaba fuera.
$enrutador->get('/medios/foto/{persona:num}', [Medios::class, 'foto']);

// Los adjuntos del expositor, por la misma razón: los ve quien los subió y
// quien revisa las propuestas. La ranura es «hoja-de-vida» o «exposicion».
$enrutador->get('/medios/documento/{propuesta:num}/{ranura:slug}', [Medios::class, 'documento']);

$enrutador->get('/medios/qr/carnet.svg', [Medios::class, 'qrCarnet'], 'asistente');
$enrutador->get('/medios/qr/acceso.svg', [Medios::class, 'qrAcceso'], 'asistente');
$enrutador->get('/medios/qr/dia/{numero:num}.svg', [Medios::class, 'qrDia'], 'admin:operador');
