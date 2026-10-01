<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * Los documentos que adjunta un expositor: la hoja de vida y la exposición.
 *
 * Es la segunda subida que hace alguien de fuera del equipo, y es más delicada
 * que la foto: aquí no se puede volver a generar el archivo. Una imagen se
 * descompone y se vuelve a dibujar, y en el camino se pierde cualquier cosa
 * escondida dentro; un PDF o un PPTX se guardan tal como llegaron, porque
 * reescribirlos significaría cambiar el documento que la persona quiso enviar.
 *
 * Como no se puede limpiar el contenido, lo que se hace es quitarle al archivo
 * toda posibilidad de ejecutarse:
 *
 *   · el formato lo decide el contenido real, nunca la extensión ni lo que
 *     declare el navegador. Un .php renombrado a .pdf no pasa de aquí;
 *   · un PPTX es un ZIP, y libmagic casi siempre dice solo «application/zip».
 *     Así que se abre y se le pregunta qué lleva dentro: sin ppt/presentation.xml
 *     declarado en [Content_Types].xml no es una presentación, es un ZIP con
 *     otro nombre;
 *   · el nombre del archivo lo pone el servidor;
 *   · se guarda fuera de la raíz web y solo sale por PHP;
 *   · y sale SIEMPRE como descarga, nunca incrustado. Un PDF abierto dentro de
 *     la página es un documento que puede traer sus propios guiones; bajado al
 *     disco es un archivo que abre el lector de quien lo pidió, fuera del
 *     origen del sitio. Ver docs/SEGURIDAD.md.
 */
final class Documento
{
    /**
     * Las dos ranuras del formulario.
     *
     * «ranura» es el trozo que va en la dirección pública, y por eso no lleva
     * guion bajo: /medios/documento/12/hoja-de-vida.
     */
    public const CLASES = [
        'hoja_vida' => [
            'etiqueta' => 'Hoja de vida',
            'ranura'   => 'hoja-de-vida',
            'formatos' => ['pdf'],
            'peso'     => 8 * 1024 * 1024,
            'prefijo'  => 'hv',
        ],
        'exposicion' => [
            'etiqueta' => 'Exposición',
            'ranura'   => 'exposicion',
            'formatos' => ['pdf', 'pptx'],
            'peso'     => 25 * 1024 * 1024,
            'prefijo'  => 'ex',
        ],
    ];

    private const FORMATOS = [
        'pdf' => [
            'nombre'    => 'PDF',
            'extension' => 'pdf',
            'mime'      => 'application/pdf',
        ],
        'pptx' => [
            'nombre'    => 'PPTX',
            'extension' => 'pptx',
            'mime'      => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
    ];

    /** Tope al leer [Content_Types].xml: ese índice son unos pocos kilobytes. */
    private const INDICE_MAXIMO = 512 * 1024;

    /**
     * Recibe $_FILES['hoja_vida'] y devuelve [nombreArchivo, tipoMime].
     *
     * @throws \DomainException con un texto que se le puede enseñar a la persona
     */
    public static function guardar(array $archivo, string $clase, int $propuestaId): array
    {
        $formato = self::comprobar($archivo, $clase);
        $regla = self::CLASES[$clase];

        $directorio = RAIZ . '/almacen/documentos';
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0750, true);
        }

        // Con azar en el nombre: sin él, saber el número de una propuesta
        // bastaría para adivinar la ruta del archivo en el disco.
        $nombre = 'pr' . $propuestaId . '-' . $regla['prefijo'] . '-' . bin2hex(random_bytes(8))
            . '.' . self::FORMATOS[$formato]['extension'];
        $destino = $directorio . '/' . $nombre;

        if (!@move_uploaded_file((string) $archivo['tmp_name'], $destino)) {
            throw new \DomainException(
                'No se pudo guardar el archivo en el servidor. Puede ser un problema de permisos '
                . 'en la carpeta almacen/documentos.'
            );
        }
        @chmod($destino, 0640);

        return [$nombre, self::FORMATOS[$formato]['mime']];
    }

    /**
     * Comprueba un archivo sin guardarlo. Devuelve el motivo del rechazo, o
     * null si sirve.
     *
     * Existe porque los dos adjuntos son obligatorios para quien va a exponer,
     * y un campo obligatorio tiene que fallar con los demás campos del
     * formulario, antes de guardar nada. Avisar después —«lo guardamos todo,
     * menos esto»— es lo correcto para la foto del carnet, que es un adorno,
     * y lo incorrecto para algo sin lo cual la propuesta no se puede evaluar.
     */
    public static function revisar(array $archivo, string $clase): ?string
    {
        try {
            self::comprobar($archivo, $clase);
            return null;
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
    }

    /** Borra un documento del disco. */
    public static function borrar(string $nombre): void
    {
        if ($nombre === '') {
            return;
        }
        // basename() corta cualquier intento de salir del directorio, aunque el
        // nombre lo haya puesto el servidor.
        @unlink(RAIZ . '/almacen/documentos/' . basename($nombre));
    }

    public static function ruta(string $nombre): string
    {
        return RAIZ . '/almacen/documentos/' . basename($nombre);
    }

    /** Tamaño en el disco, ya redactado: «1,4 MB». Vacío si el archivo no está. */
    public static function peso(string $nombre): string
    {
        $ruta = self::ruta($nombre);
        if ($nombre === '' || !is_file($ruta)) {
            return '';
        }
        $bytes = (int) filesize($ruta);
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', '.') . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** De «hoja-de-vida» a «hoja_vida». Null si esa ranura no existe. */
    public static function porRanura(string $ranura): ?string
    {
        foreach (self::CLASES as $clase => $regla) {
            if ($regla['ranura'] === $ranura) {
                return $clase;
            }
        }
        return null;
    }

    /** «PDF» o «PDF o PPTX», para los textos de la pantalla. */
    public static function formatosLegibles(string $clase): string
    {
        $nombres = array_map(
            static fn(string $f): string => self::FORMATOS[$f]['nombre'],
            self::CLASES[$clase]['formatos'] ?? []
        );
        return $nombres ? implode(' o ', $nombres) : '';
    }

    /** El atributo accept del campo: una pista para el navegador, nada más. */
    public static function aceptados(string $clase): string
    {
        $partes = [];
        foreach (self::CLASES[$clase]['formatos'] ?? [] as $formato) {
            $partes[] = '.' . self::FORMATOS[$formato]['extension'];
            $partes[] = self::FORMATOS[$formato]['mime'];
        }
        return implode(',', $partes);
    }

    public static function pesoMaximo(string $clase): int
    {
        return (int) (self::CLASES[$clase]['peso'] ?? 0);
    }

    /** «8 MB», para el rótulo del campo. */
    public static function pesoLegible(string $clase): string
    {
        return (int) round(self::pesoMaximo($clase) / 1048576) . ' MB';
    }

    /**
     * El nombre con el que se baja: «Hoja-de-vida-Maria-Zambrano.pdf».
     *
     * No se guarda el nombre original que traía el archivo. Sirve de poco
     * —«presentacion final FINAL v3.pptx»— y es texto que escribió alguien de
     * fuera, así que habría que desconfiar de él cada vez que se imprime. Se
     * arma aquí a partir de datos que ya están en la base.
     */
    public static function nombreDescarga(string $clase, string $persona, string $archivo): string
    {
        $etiqueta = (string) (self::CLASES[$clase]['etiqueta'] ?? 'Documento');
        $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION)) ?: 'bin';

        $base = self::aPalabras($etiqueta . ' ' . $persona);
        return ($base !== '' ? $base : 'documento') . '.' . preg_replace('/[^a-z0-9]/', '', $extension);
    }

    /* =====================================================================
       Interno
       ===================================================================== */

    /**
     * Todo lo que tiene que cumplir un archivo. Devuelve su formato.
     *
     * @throws \DomainException con un texto que se le puede enseñar a la persona
     */
    private static function comprobar(array $archivo, string $clase): string
    {
        $regla = self::CLASES[$clase] ?? null;
        if ($regla === null) {
            throw new \DomainException('Ese documento no corresponde a ningún campo del formulario.');
        }

        self::validarSubida($archivo, $regla);

        $formato = self::formatoReal((string) $archivo['tmp_name']);
        if ($formato === null || !in_array($formato, $regla['formatos'], true)) {
            throw new \DomainException(
                'El archivo no es ' . self::formatosLegibles($clase) . '. Lo que cuenta es el '
                . 'contenido, no el nombre: cambiarle la extensión a un archivo no lo convierte '
                . 'en otra cosa.'
            );
        }

        return $formato;
    }

    /**
     * Sin tildes, sin espacios y sin nada que pueda romper una cabecera.
     *
     * El nombre acaba en Content-Disposition, que es una cabecera HTTP: una
     * comilla o un salto de línea ahí dentro dejan de ser texto y pasan a ser
     * estructura. Por eso se reduce a letras, números y guiones en vez de
     * escaparlo.
     */
    private static function aPalabras(string $texto): string
    {
        $tabla = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                  'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'];
        $texto = strtr($texto, $tabla);
        $texto = (string) preg_replace('/[^A-Za-z0-9]+/', '-', $texto);
        return trim(mb_substr($texto, 0, 80), '-');
    }

    private static function validarSubida(array $archivo, array $regla): void
    {
        $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \DomainException(
                'El archivo supera el tamaño máximo que admite el servidor ('
                . ini_get('upload_max_filesize') . ').'
            );
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \DomainException('No se pudo recibir el archivo. Inténtalo de nuevo.');
        }
        if ((int) ($archivo['size'] ?? 0) > (int) $regla['peso']) {
            throw new \DomainException(
                'El archivo pesa más de ' . (int) round($regla['peso'] / 1048576) . ' MB.'
            );
        }
        if (!is_uploaded_file((string) $archivo['tmp_name'])) {
            throw new \DomainException('El archivo no llegó por una subida válida.');
        }
    }

    /**
     * Qué es el archivo de verdad. Null si no es ninguno de los dos formatos.
     */
    private static function formatoReal(string $ruta): ?string
    {
        if (!class_exists(\finfo::class)) {
            throw new \DomainException(
                'Este servidor no tiene la extensión fileinfo de PHP, que es la que comprueba '
                . 'el tipo real de un archivo. Sin ella no se admiten adjuntos.'
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($ruta);

        if ($mime === 'application/pdf') {
            // libmagic se conforma con encontrar «%PDF-» en el primer kilobyte,
            // así que un archivo que es otra cosa y lleva esa cadena metida más
            // adelante le pasa por PDF. La norma dice que la cabecera es la
            // primera línea del archivo y toda herramienta la escribe ahí, así
            // que exigirlo no deja fuera nada legítimo y sí cierra la puerta a
            // los archivos que son dos cosas a la vez.
            return self::empiezaPor($ruta, '%PDF-') ? 'pdf' : null;
        }

        // Un PPTX es un ZIP. libmagic lo reconoce solo si viene con
        // [Content_Types].xml sin comprimir al principio, que es como lo escribe
        // PowerPoint pero no necesariamente otras herramientas. Cuando no lo
        // reconoce dice «application/zip», y ahí es donde hay que mirar dentro.
        if ($mime === self::FORMATOS['pptx']['mime']
            || $mime === 'application/zip'
            || $mime === 'application/x-zip-compressed') {
            return self::esPresentacion($ruta) ? 'pptx' : null;
        }

        return null;
    }

    /** ¿El archivo arranca exactamente con esos bytes? */
    private static function empiezaPor(string $ruta, string $cabecera): bool
    {
        $mano = @fopen($ruta, 'rb');
        if ($mano === false) {
            return false;
        }
        try {
            return fread($mano, strlen($cabecera)) === $cabecera;
        } finally {
            fclose($mano);
        }
    }

    /**
     * ¿Ese ZIP es de verdad una presentación?
     *
     * Dos condiciones, y hacen falta las dos: que esté la pieza que define una
     * presentación y que el índice del paquete la declare. Con solo la primera,
     * cualquiera que metiera un archivo vacío llamado ppt/presentation.xml
     * dentro de un ZIP pasaría el control.
     *
     * Nunca se extrae nada al disco. Del paquete se lee un único archivo, y
     * solo después de comprobar en el índice que es pequeño: un ZIP de un mega
     * puede llevar dentro un XML de varios gigas, y descomprimirlo a ciegas
     * tumbaría el servidor con la memoria agotada.
     */
    private static function esPresentacion(string $ruta): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) {
            return false;
        }

        try {
            if ($zip->locateName('ppt/presentation.xml') === false) {
                return false;
            }

            $indice = $zip->statName('[Content_Types].xml');
            if ($indice === false || (int) $indice['size'] > self::INDICE_MAXIMO) {
                return false;
            }

            $tipos = $zip->getFromName('[Content_Types].xml');
            return is_string($tipos) && str_contains($tipos, 'presentationml.presentation');
        } finally {
            $zip->close();
        }
    }
}
