<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

use App\Esquema;

/**
 * Salud de la instalación.
 *
 * Existe por un caso concreto que ocurrió en producción: la configuración
 * quedó escrita y marcada como instalada, pero la base no tenía ninguna cuenta
 * administradora. El resultado era el peor posible —el asistente respondía «ya
 * está instalada» y el acceso del equipo respondía «correo o contraseña
 * incorrectos»— y no había forma de entrar ni de saber por qué.
 *
 * Estar instalado no es tener un archivo de configuración: es tener las tablas,
 * una cuenta con la que entrar y un evento que mostrar. Esta clase lo comprueba
 * y el resto del sistema decide qué hacer con la respuesta.
 *
 * Nunca lanza excepciones. Se la consulta justo en los momentos en que algo ya
 * está roto, y entonces no puede ser ella quien remate la página.
 */
final class Instalacion
{
    private static ?array $cache = null;

    /** Credenciales que el paso 2 del asistente deja mientras dura el proceso. */
    public static function rutaConexion(): string
    {
        return RAIZ . '/config/instalacion.php';
    }

    /**
     * Datos de conexión de una instalación a medio hacer, si los hay.
     *
     * Los escribe el paso 2 y los borra el paso 5 al terminar. Mientras
     * existan, son la única forma de mirar la base de datos: config/config.php
     * todavía no está.
     *
     * @return array{host:string,puerto:int,nombre:string,usuario:string,clave:string,prefijo:string}|null
     */
    public static function credencialesEnCurso(): ?array
    {
        $ruta = self::rutaConexion();
        clearstatcache(true, $ruta);
        if (!is_file($ruta) || !is_readable($ruta)) {
            return null;
        }
        // Otro proceso de PHP-FPM puede tener cacheada una versión anterior.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($ruta, true);
        }
        try {
            $datos = require $ruta;
        } catch (\Throwable) {
            return null;
        }
        return (is_array($datos) && !empty($datos['nombre'])) ? $datos : null;
    }

    /**
     * @return array{
     *   config: bool, bd: bool, tablas: int, esperadas: int, faltantes: array<int,string>,
     *   administradores: int, usuarios: int, eventos: int, completa: bool, motivo: string,
     *   fuente: string
     * }
     */
    public static function diagnostico(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $d = [
            'config'          => Config::existe(),
            'bd'              => false,
            'tablas'          => 0,
            'esperadas'       => count(Esquema::nombres()),
            'faltantes'       => Esquema::nombres(),
            'administradores' => 0,
            'usuarios'        => 0,
            'eventos'         => 0,
            'completa'        => false,
            'motivo'          => '',
            'fuente'          => 'config/config.php',
        ];

        // Sin config.php todavía se puede mirar la base: el asistente deja sus
        // credenciales en config/instalacion.php mientras trabaja.
        //
        // Antes se devolvía aquí mismo «nunca se instaló», y el diagnóstico de
        // una instalación parada a mitad —con las dieciséis tablas ya creadas—
        // anunciaba «sin conexión, 0 de 16 tablas». Justo lo contrario de lo
        // que pasaba, y en la única pantalla a la que se acude para averiguarlo.
        if (!$d['config']) {
            $credenciales = self::credencialesEnCurso();
            if ($credenciales === null) {
                $d['motivo'] = 'Todavía no existe config/config.php: la plataforma nunca se instaló.';
                return self::$cache = $d;
            }
            $d['fuente'] = 'config/instalacion.php (instalación en curso)';
            Config::establecerEnMemoria([
                'bd_host'    => $credenciales['host'],
                'bd_puerto'  => (int) $credenciales['puerto'],
                'bd_nombre'  => $credenciales['nombre'],
                'bd_usuario' => $credenciales['usuario'],
                'bd_clave'   => $credenciales['clave'],
                'bd_prefijo' => $credenciales['prefijo'],
            ]);
            Bd::establecerPrefijo((string) $credenciales['prefijo']);
        }

        try {
            Bd::conectar();
            $d['bd'] = true;

            $presentes = Esquema::existentes();
            $d['tablas'] = count($presentes);
            $d['faltantes'] = array_values(array_diff(Esquema::nombres(), $presentes));

            if ($d['faltantes']) {
                $d['motivo'] = 'Faltan ' . count($d['faltantes']) . ' tablas: '
                    . implode(', ', array_slice($d['faltantes'], 0, 5))
                    . (count($d['faltantes']) > 5 ? '…' : '') . '.';
                return self::$cache = $d;
            }

            $d['usuarios'] = (int) Bd::valor('SELECT COUNT(*) FROM {usuario}');
            $d['administradores'] = (int) Bd::valor(
                "SELECT COUNT(*) FROM {usuario} WHERE rol = 'administrador' AND estado = 'activo'"
            );
            $d['eventos'] = (int) Bd::valor('SELECT COUNT(*) FROM {evento}');

            if ($d['administradores'] === 0) {
                $d['motivo'] = $d['usuarios'] === 0
                    ? 'Las ' . $d['tablas'] . ' tablas están creadas pero la tabla de usuarios está '
                        . 'vacía: la instalación se interrumpió entre el paso 3 (tablas) y el paso 5 '
                        . '(crear la cuenta y el evento). Vuelve al asistente y termina esos dos pasos; '
                        . 'no se borrará nada de lo ya creado.'
                    : 'Hay cuentas del equipo pero ninguna administradora activa.';
                return self::$cache = $d;
            }

            $d['completa'] = true;
            if ($d['eventos'] === 0) {
                $d['motivo'] = 'Hay cuenta administradora pero ningún evento creado.';
            } elseif (!$d['config']) {
                $d['motivo'] = 'La cuenta y el evento existen, pero falta escribir config/config.php: '
                    . 'vuelve al asistente y pulsa «Terminar». No se duplicará nada.';
            }
        } catch (\Throwable $e) {
            $d['motivo'] = 'No se pudo consultar la base de datos: ' . $e->getMessage();
        }

        return self::$cache = $d;
    }

    /** Hay tablas y al menos una cuenta administradora activa con la que entrar. */
    public static function completa(): bool
    {
        return self::diagnostico()['completa'];
    }

    /**
     * Marcada como instalada, pero inservible.
     *
     * Es el estado que reabre el asistente en modo reparación. La barrera no se
     * pierde: el paso 2 sigue exigiendo las credenciales de la base de datos,
     * que quien llega de fuera no tiene.
     */
    public static function incompleta(): bool
    {
        return Config::instalado() && !self::completa();
    }

    public static function hayAdministrador(): bool
    {
        return self::diagnostico()['administradores'] > 0;
    }

    public static function hayEvento(): bool
    {
        return self::diagnostico()['eventos'] > 0;
    }

    /** Tras crear una cuenta o un evento, el diagnóstico anterior ya no vale. */
    /**
     * Lo que hay cifrado en la base: cuántos documentos, cuántas cuentas con
     * segundo factor, y el dato más reciente de cada clase para probar llaves.
     *
     * Lo usan el asistente y la consola antes de escribir una configuración
     * sin llave: si la base ya tiene datos cifrados, una llave nueva los deja
     * ilegibles, y eso no puede pasar sin que alguien lo decida.
     *
     * @return array{personas: int, cuentas: int, muestras: array<int, string>}
     */
    public static function datosCifrados(): array
    {
        $vacio = ['personas' => 0, 'cuentas' => 0, 'muestras' => []];
        try {
            $personas = Bd::existeTabla('persona') ? (int) Bd::valor(
                "SELECT COUNT(*) FROM {persona} WHERE documento_cifrado IS NOT NULL AND documento_cifrado <> ''"
            ) : 0;
            $cuentas = Bd::existeTabla('usuario') ? (int) Bd::valor(
                "SELECT COUNT(*) FROM {usuario} WHERE totp_secreto IS NOT NULL AND totp_secreto <> ''"
            ) : 0;
            // Los más recientes: si alguna vez se cambió la llave, son los que
            // están cifrados con la última.
            $muestras = array_values(array_filter([
                $personas ? (string) Bd::valor("SELECT documento_cifrado FROM {persona}
                     WHERE documento_cifrado IS NOT NULL AND documento_cifrado <> '' ORDER BY id DESC LIMIT 1") : '',
                $cuentas ? (string) Bd::valor("SELECT totp_secreto FROM {usuario}
                     WHERE totp_secreto IS NOT NULL AND totp_secreto <> '' ORDER BY id DESC LIMIT 1") : '',
            ]));
        } catch (\Throwable) {
            return $vacio;
        }
        return ['personas' => $personas, 'cuentas' => $cuentas, 'muestras' => $muestras];
    }

    /** ¿Abre esta llave alguno de los datos cifrados de la base? */
    public static function llaveAbreLosDatos(string $llave, array $muestras): bool
    {
        foreach ($muestras as $muestra) {
            if (Cripto::llaveAbre($llave, $muestra)) {
                return true;
            }
        }
        return false;
    }

    public static function olvidar(): void
    {
        self::$cache = null;
    }

    /**
     * Últimos errores que registró la aplicación.
     *
     * Siempre se quita la ruta absoluta del servidor. Y cuando la pantalla es
     * pública —el diagnóstico lo es mientras la plataforma no funcione, porque
     * si no nadie podría averiguar qué falta— se tapan además los correos y las
     * cifras largas: en ese registro acaban, por ejemplo, las direcciones de los
     * asistentes a los que no se les pudo enviar el carnet, y eso no puede verlo
     * cualquiera que pase por ahí.
     *
     * @return array<int, string>
     */
    public static function erroresRecientes(int $cuantos = 10, bool $publico = true): array
    {
        $archivos = glob(Registro::directorio() . '/*.log.php') ?: [];
        if (!$archivos) {
            return [];
        }
        rsort($archivos);

        $lineas = [];
        foreach (array_slice($archivos, 0, 3) as $archivo) {
            $contenido = @file_get_contents($archivo);
            if ($contenido === false) {
                continue;
            }
            foreach (explode("\n", $contenido) as $linea) {
                if (!str_contains($linea, 'ERROR') && !str_contains($linea, 'AVISO')) {
                    continue;
                }
                $linea = str_replace(RAIZ, '…', mb_substr(trim($linea), 0, 400));
                if ($publico) {
                    $linea = (string) preg_replace('/[\w.+-]+@[\w.-]+\.\w+/u', '[correo]', $linea);
                    $linea = (string) preg_replace('/\d{6,}/', '[número]', $linea);
                }
                $lineas[] = $linea;
            }
        }

        return array_slice(array_reverse($lineas), 0, max(1, $cuantos));
    }
}
