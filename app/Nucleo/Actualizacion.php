<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

use App\Esquema;

/**
 * Pone la base al día sola, en la primera petición después de subir archivos.
 *
 * Hasta la 3.5 había que entrar al panel y pulsar «Actualizar la base de
 * datos». Eso tenía un agujero que no se veía hasta que dolía: para llegar al
 * botón hay que iniciar sesión, y el inicio de sesión también es código nuevo
 * corriendo contra la base vieja. Con una instalación anterior a la 1.1.0, el
 * segundo factor escribe en una columna que todavía no existe, la verificación
 * responde 500 con el código correcto, y el administrador se queda fuera
 * justamente de la pantalla que lo arreglaría.
 *
 * Así que ahora la base se pone al día antes de atender nada. Es seguro hacerlo
 * sin preguntar porque el modo «actualizar» solo agrega: crea las tablas que
 * falten, agrega columnas y ensancha tipos; nunca borra ni estrecha nada. Es lo
 * mismo que hace el botón, y el botón sigue ahí para quien lo prefiera.
 *
 * Tres cuidados:
 *
 *   · Barato cuando no hay nada que hacer. Una marca en disco con la versión
 *     evita preguntarle a la base en cada petición; se vuelve a mirar cada
 *     diez minutos, por si alguien restauró un respaldo viejo.
 *
 *   · Una sola vez aunque lleguen cien peticiones juntas. Un candado de MySQL
 *     hace esperar a las demás, que al entrar ven el trabajo ya hecho.
 *
 *   · Si no se puede —el usuario de la base no tiene permiso de ALTER, que en
 *     algunos alojamientos pasa—, se anota, se reintenta cada cinco minutos y
 *     no se rompe nada más: el aviso del panel y el botón siguen funcionando.
 *
 * Se apaga con 'actualizacion_automatica' => false en la configuración, para el
 * área de sistemas que prefiera hacerlo a mano.
 */
final class Actualizacion
{
    private const CANDADO = 'evtic_esquema';
    private const ESPERA_CANDADO = 20;      // segundos
    private const REVISAR_CADA = 600;       // segundos
    private const REINTENTAR_FALLO = 300;   // segundos

    /**
     * Las marcas son .php y empiezan con una salida, igual que el registro de
     * errores: si nginx sirviera almacen/ por error, pedirlas no devuelve nada.
     */
    private const GUARDA = "<?php exit; ?>\n";

    /** Las carpetas que la plataforma necesita para guardar lo que se sube. */
    public const CARPETAS = [
        'almacen/fotos', 'almacen/documentos', 'almacen/logos',
        'almacen/respaldos', 'almacen/registro',
    ];

    /**
     * ¿Hay que actualizar? Si sí, actualiza.
     *
     * Devuelve true si aplicó cambios en esta petición. Nunca lanza: si algo
     * sale mal, la petición sigue con la base como esté.
     */
    public static function alDia(): bool
    {
        if (!Config::obtener('actualizacion_automatica', true)) {
            return false;
        }

        // La marca dice que ya se revisó hace poco: no se pregunta a la base.
        if (self::marcaVigente()) {
            return false;
        }

        // Falló hace poco: no se reintenta en cada petición. Con un permiso que
        // falta, insistir solo llena el registro de errores.
        if (self::falloReciente()) {
            return false;
        }

        try {
            if (!self::atrasada()) {
                self::marcar();
                return false;
            }

            if (!self::tomarCandado()) {
                // Otra petición lleva más de veinte segundos actualizando. No se
                // espera más: lo que falte lo dirá el aviso del panel.
                return false;
            }

            try {
                // Con el candado en la mano se vuelve a mirar: puede que la
                // petición que lo tenía antes ya lo haya hecho todo.
                Esquema::olvidarRevision();
                if (!self::atrasada()) {
                    self::marcar();
                    return false;
                }

                self::aplicar('automatica');
                return true;
            } finally {
                self::soltarCandado();
            }
        } catch (\Throwable $e) {
            // Nunca se deja caer la petición por esto. El detalle va al registro
            // de errores; la marca solo sirve para esperar antes de reintentar.
            Registro::excepcion($e);
            self::escribirMarca(self::archivoFallo(), date('c'));
            return false;
        }
    }

    /**
     * Aplica el esquema y deja lo demás en orden: carpetas, marca y bitácora.
     *
     * La usan la actualización automática y el botón del panel, para que las
     * dos hagan exactamente lo mismo.
     *
     * @return array<int, array{tabla: string, accion: string, detalle: string}> lo que cambió
     */
    public static function aplicar(string $origen): array
    {
        $antes = Esquema::versionInstalada();
        $hechas = Esquema::aplicar('actualizar', Esquema::existentes());
        Esquema::olvidarRevision();
        self::crearCarpetas();

        $cambios = array_values(array_filter(
            $hechas,
            static fn(array $h): bool => in_array($h['accion'], ['creada', 'actualizada'], true)
        ));

        Bitacora::registrar('esquema_actualizado', 'sistema', null, [
            'origen'  => $origen,
            'de'      => $antes ?? 'sin versión anotada',
            'a'       => Esquema::VERSION,
            'cambios' => count($cambios),
            'tablas'  => implode(', ', array_column($cambios, 'tabla')),
        ]);

        self::marcar();
        @unlink(self::archivoFallo());
        return $cambios;
    }

    /** Crea las carpetas de almacen/ que falten. Las subidas las necesitan. */
    public static function crearCarpetas(): void
    {
        foreach (self::CARPETAS as $relativa) {
            $ruta = RAIZ . '/' . $relativa;
            if (!is_dir($ruta)) {
                @mkdir($ruta, 0750, true);
            }
        }
    }

    /**
     * Olvida las marcas en disco. Lo usan el asistente al terminar —acaba de
     * dejar la base como debe estar— y las pruebas, que cambian de base.
     */
    public static function olvidar(): void
    {
        @unlink(self::archivoMarca());
        @unlink(self::archivoFallo());
    }

    /* =====================================================================
       Interno
       ===================================================================== */

    private static function atrasada(): bool
    {
        [$pendiente] = Esquema::revisionPendiente();
        return $pendiente;
    }

    private static function archivoMarca(): string
    {
        return RAIZ . '/almacen/registro/esquema-al-dia.php';
    }

    private static function archivoFallo(): string
    {
        return RAIZ . '/almacen/registro/esquema-fallo.php';
    }

    /**
     * La marca vale si es de esta versión y es reciente.
     *
     * Se vuelve a mirar cada diez minutos aunque la versión coincida: si
     * alguien restaura un respaldo viejo de la base, la marca en disco seguiría
     * diciendo que todo está al día.
     */
    private static function marcaVigente(): bool
    {
        $archivo = self::archivoMarca();
        if (!is_file($archivo)) {
            return false;
        }
        $contenido = (string) @file_get_contents($archivo);
        if (trim(str_replace(self::GUARDA, '', $contenido)) !== Esquema::VERSION) {
            return false;
        }
        return (time() - (int) @filemtime($archivo)) < self::REVISAR_CADA;
    }

    private static function marcar(): void
    {
        self::escribirMarca(self::archivoMarca(), Esquema::VERSION);
    }

    private static function escribirMarca(string $archivo, string $texto): void
    {
        if (!is_dir(dirname($archivo))) {
            @mkdir(dirname($archivo), 0750, true);
        }
        @file_put_contents($archivo, self::GUARDA . $texto . "\n", LOCK_EX);
    }

    private static function falloReciente(): bool
    {
        $archivo = self::archivoFallo();
        return is_file($archivo) && (time() - (int) @filemtime($archivo)) < self::REINTENTAR_FALLO;
    }

    /*
     * El nombre del candado lleva la base y el prefijo: los candados de MySQL
     * son de todo el servidor, y en un alojamiento compartido puede haber otra
     * instalación de la plataforma que no tiene por qué esperar a esta.
     */
    private const NOMBRE_CANDADO = "LEFT(CONCAT(?, ':', DATABASE(), ':', ?), 64)";

    private static function tomarCandado(): bool
    {
        return (int) Bd::valorDirecto(
            'SELECT GET_LOCK(' . self::NOMBRE_CANDADO . ', ?)',
            [self::CANDADO, Bd::prefijo(), self::ESPERA_CANDADO]
        ) === 1;
    }

    private static function soltarCandado(): void
    {
        try {
            Bd::valorDirecto('SELECT RELEASE_LOCK(' . self::NOMBRE_CANDADO . ')', [self::CANDADO, Bd::prefijo()]);
        } catch (\Throwable) {
            // Se suelta solo al cerrarse la conexión, al terminar la petición.
        }
    }
}
