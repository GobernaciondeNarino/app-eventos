<?php
declare(strict_types=1);

namespace App\Nucleo;

defined('EVENTOS_TIC') || exit;

/**
 * La fotografía del carnet.
 *
 * Es la única subida que hace un visitante sin cuenta del equipo, así que es la
 * que más cuidado pide. Las reglas son las mismas que ya rigen para el logo del
 * evento (ver docs/SEGURIDAD.md) y aquí se aplican sin excepción:
 *
 *   · el tipo lo decide el contenido real del archivo, nunca su extensión ni lo
 *     que declare el navegador;
 *   · la imagen se vuelve a generar entera, lo que descarta cualquier carga útil
 *     escondida en los metadatos EXIF o detrás de la cabecera;
 *   · no se admite SVG. Para un logo tiene sentido; para una foto de una cara no
 *     hay ningún motivo, y un SVG es un documento capaz de ejecutar guiones;
 *   · el nombre del archivo lo pone el servidor;
 *   · el archivo se guarda fuera de la raíz web y solo se sirve a través de PHP.
 *
 * Además se recorta cuadrada y se reduce a 480 px: un carnet no necesita más, y
 * así una foto de 12 megapíxeles no ocupa doce megas en el disco del servidor.
 */
final class Imagen
{
    /** 6 MB. Una foto de teléfono cabe de sobra; un vídeo disfrazado, no. */
    private const PESO_MAXIMO = 6 * 1024 * 1024;

    /** Lado del cuadrado final, en píxeles. */
    private const LADO = 480;

    private const TIPOS = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * Recibe $_FILES['foto'] y devuelve [nombreArchivo, tipoMime].
     *
     * $recorte es lo que eligió la persona en el editor del navegador: el
     * cuadro visible, en coordenadas de la imagen tal como ella la vio. Puede
     * faltar —sin JavaScript, o si no tocó nada— y entonces se recorta el
     * centro, que es lo que se hacía antes.
     *
     * Nunca se confía en esos números: se reescalan a las medidas reales de la
     * imagen en el servidor y se encajan dentro de ella. Vienen de un
     * formulario, así que pueden llegar negativos, enormes o con letras.
     *
     * @param array{x?:mixed,y?:mixed,lado?:mixed,ancho?:mixed,alto?:mixed}|null $recorte
     * @throws \DomainException con un texto que se le puede enseñar a la persona
     */
    public static function guardarFoto(array $archivo, int $personaId, ?array $recorte = null): array
    {
        [$original, $tipoReal] = self::abrirFoto($archivo);

        try {
            $cuadrada = self::recortarCuadrado($original, $archivo['tmp_name'], $tipoReal, $recorte);
        } finally {
            imagedestroy($original);
        }

        $directorio = RAIZ . '/almacen/fotos';
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0750, true);
        }

        // El nombre lo pone el servidor y lleva azar: sin él, saber el id de una
        // persona bastaría para adivinar la ruta de su foto.
        $nombre = 'p' . $personaId . '-' . bin2hex(random_bytes(8)) . '.jpg';
        $destino = $directorio . '/' . $nombre;

        // Siempre JPEG a la salida, sea cual sea la entrada: un solo formato que
        // servir, y ninguna posibilidad de que sobreviva un trozo del original.
        $escrita = imagejpeg($cuadrada, $destino, 82);
        imagedestroy($cuadrada);

        if (!$escrita) {
            throw new \DomainException(
                'No se pudo guardar la foto en el servidor. Puede ser un problema de permisos '
                . 'en la carpeta almacen/fotos.'
            );
        }
        @chmod($destino, 0640);

        return [$nombre, 'image/jpeg'];
    }

    /**
     * ¿Sirve esta foto? Lo mismo que mira guardarFoto(), sin guardar nada.
     *
     * Para cuando el evento la exige: entonces tiene que fallar con los demás
     * campos, antes de crear el registro, y no después con un «se guardó todo
     * menos la foto» que deja el carnet sin ella.
     *
     * @throws \DomainException con un texto que se le puede enseñar a la persona
     */
    public static function revisarFoto(array $archivo): void
    {
        [$imagen] = self::abrirFoto($archivo);
        imagedestroy($imagen);
    }

    /** Borra la foto de una persona del disco. */
    public static function borrarFoto(string $nombre): void
    {
        if ($nombre === '') {
            return;
        }
        // basename() corta cualquier intento de salir del directorio, aunque el
        // nombre lo haya puesto el servidor.
        @unlink(RAIZ . '/almacen/fotos/' . basename($nombre));
    }

    /**
     * El banner del formulario de registro.
     *
     * Las mismas reglas que la foto —el tipo por el contenido, la imagen
     * regenerada entera, sin SVG, el nombre puesto aquí— pero sin recorte: se
     * conserva la proporción y solo se reduce si pasa de 1600 px de ancho, que
     * es más de lo que ocupa en cualquier pantalla. Los PNG y WEBP salen en PNG
     * para no perder la transparencia; las fotos, en JPEG.
     *
     * @return array{0: string, 1: string} [nombreArchivo, tipoMime]
     * @throws \DomainException con un texto que se le puede enseñar a la persona
     */
    public static function guardarBanner(array $archivo, int $eventoId): array
    {
        self::validarSubida($archivo, 'La imagen');

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $tipoReal = (string) $finfo->file($archivo['tmp_name']);
        if (!isset(self::TIPOS[$tipoReal])) {
            throw new \DomainException('El banner tiene que ser JPG, PNG o WEBP.');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new \DomainException(
                'Este servidor no tiene la extensión GD de PHP, que es la que procesa las '
                . 'imágenes. Pídele al área de sistemas que la active.'
            );
        }

        $original = @imagecreatefromstring((string) file_get_contents($archivo['tmp_name']));
        if ($original === false) {
            throw new \DomainException('La imagen está dañada o no se pudo leer. Prueba con otra.');
        }

        $imagen = self::enderezar($original, $archivo['tmp_name'], $tipoReal);
        if ($imagen !== $original) {
            imagedestroy($original);
        }

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        if ($ancho < 300 || $alto < 60) {
            imagedestroy($imagen);
            throw new \DomainException('La imagen es muy pequeña para un banner: usa una de al menos 1200 px de ancho.');
        }

        $conTransparencia = $tipoReal !== 'image/jpeg';
        $escala = min(1.0, 1600 / $ancho);
        $nuevoAncho = (int) round($ancho * $escala);
        $nuevoAlto = (int) round($alto * $escala);

        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        if ($conTransparencia) {
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        }
        imagecopyresampled($destino, $imagen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        imagedestroy($imagen);

        $directorio = RAIZ . '/almacen/logos';
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0750, true);
        }
        $nombre = 'banner-' . $eventoId . '-' . bin2hex(random_bytes(6)) . ($conTransparencia ? '.png' : '.jpg');
        $ruta = $directorio . '/' . $nombre;

        $escrita = $conTransparencia ? imagepng($destino, $ruta, 7) : imagejpeg($destino, $ruta, 85);
        imagedestroy($destino);
        if (!$escrita) {
            throw new \DomainException('No se pudo guardar la imagen en el servidor. Revisa los permisos de almacen/logos.');
        }
        @chmod($ruta, 0640);

        return [$nombre, $conTransparencia ? 'image/png' : 'image/jpeg'];
    }

    public static function borrarBanner(string $nombre): void
    {
        if ($nombre !== '' && str_starts_with(basename($nombre), 'banner-')) {
            @unlink(RAIZ . '/almacen/logos/' . basename($nombre));
        }
    }

    /* =====================================================================
       Interno
       ===================================================================== */

    /**
     * La foto subida, abierta: el tipo por su contenido y la imagen ya leída.
     *
     * @return array{0: \GdImage, 1: string} la imagen y su tipo real
     * @throws \DomainException con un texto que se le puede enseñar a la persona
     */
    private static function abrirFoto(array $archivo): array
    {
        self::validarSubida($archivo);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $tipoReal = (string) $finfo->file($archivo['tmp_name']);
        if (!isset(self::TIPOS[$tipoReal])) {
            throw new \DomainException(
                'Esa foto no está en un formato que podamos usar. Sirven JPG, PNG y WEBP.'
            );
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new \DomainException(
                'Este servidor no tiene la extensión GD de PHP, que es la que procesa las '
                . 'imágenes. Pídele al área de sistemas que la active.'
            );
        }

        $original = @imagecreatefromstring((string) file_get_contents($archivo['tmp_name']));
        if ($original === false) {
            throw new \DomainException('La imagen está dañada o no se pudo leer. Prueba con otra.');
        }
        return [$original, $tipoReal];
    }

    private static function validarSubida(array $archivo, string $que = 'La foto'): void
    {
        $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \DomainException(
                $que . ' supera el tamaño máximo que admite el servidor ('
                . ini_get('upload_max_filesize') . '). Usa una más pequeña o recórtala.'
            );
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \DomainException('No se pudo recibir ' . mb_strtolower($que) . '. Inténtalo de nuevo.');
        }
        if ((int) ($archivo['size'] ?? 0) > self::PESO_MAXIMO) {
            throw new \DomainException($que . ' pesa más de 6 MB. Reduce el tamaño e inténtalo de nuevo.');
        }
        if (!is_uploaded_file((string) $archivo['tmp_name'])) {
            throw new \DomainException('El archivo no llegó por una subida válida.');
        }
    }

    /**
     * Recorta un cuadrado y lo reduce a 480 px.
     *
     * Se respeta la orientación EXIF antes de recortar. Sin eso, las fotos
     * hechas con el teléfono en vertical salen acostadas: el sensor graba
     * siempre igual y la orientación real va en una etiqueta que se pierde al
     * regenerar la imagen, que es justo lo que hacemos aquí a propósito.
     *
     * @param \GdImage $original
     * @param array{x?:mixed,y?:mixed,lado?:mixed,ancho?:mixed,alto?:mixed}|null $recorte
     * @return \GdImage
     */
    private static function recortarCuadrado(
        \GdImage $original,
        string $ruta,
        string $tipo,
        ?array $recorte = null
    ): \GdImage {
        $imagen = self::enderezar($original, $ruta, $tipo);

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);

        [$x, $y, $lado] = self::encuadre($recorte, $ancho, $alto);

        $destino = imagecreatetruecolor(self::LADO, self::LADO);

        // Fondo blanco: si la fuente es un PNG con transparencia, sin esto el
        // JPEG resultante sale con el fondo negro.
        $blanco = imagecolorallocate($destino, 255, 255, 255);
        imagefilledrectangle($destino, 0, 0, self::LADO, self::LADO, $blanco);

        imagecopyresampled($destino, $imagen, 0, 0, $x, $y, self::LADO, self::LADO, $lado, $lado);

        if ($imagen !== $original) {
            imagedestroy($imagen);
        }
        return $destino;
    }

    /**
     * Decide qué cuadrado se recorta, a partir de lo que eligió la persona.
     *
     * El navegador manda el cuadro en las medidas con las que vio la imagen.
     * Aquí se reescala a las medidas reales —que pueden no coincidir: basta
     * con que el navegador y GD interpreten distinto la orientación EXIF— y se
     * encaja dentro de la imagen. Si algo no cuadra, se cae al recorte de
     * siempre en vez de fallar: la foto es un adorno del carnet.
     *
     * @param array{x?:mixed,y?:mixed,lado?:mixed,ancho?:mixed,alto?:mixed}|null $recorte
     * @return array{0:int,1:int,2:int} [x, y, lado]
     */
    private static function encuadre(?array $recorte, int $ancho, int $alto): array
    {
        $maximo = min($ancho, $alto);

        // Sin recorte: el centro horizontal, y algo por encima del centro
        // vertical. En un retrato la cara está en el tercio superior, y un
        // recorte al centro exacto la corta por la frente.
        $porOmision = [
            (int) round(($ancho - $maximo) / 2),
            (int) round(($alto - $maximo) * 0.35),
            $maximo,
        ];

        if ($recorte === null) {
            return $porOmision;
        }

        $numero = static fn(string $clave): float => is_numeric($recorte[$clave] ?? null)
            ? (float) $recorte[$clave]
            : -1.0;

        $anchoVisto = $numero('ancho');
        $altoVisto = $numero('alto');
        $lado = $numero('lado');
        $x = $numero('x');
        $y = $numero('y');

        if ($anchoVisto < 1 || $altoVisto < 1 || $lado < 1 || $x < 0 || $y < 0) {
            return $porOmision;
        }

        // Si el navegador vio la imagen girada respecto a como la ve GD, sus
        // coordenadas no significan nada aquí: mejor el recorte de siempre que
        // uno que corta por donde no es.
        $mismaForma = abs(($anchoVisto / $altoVisto) - ($ancho / $alto)) < 0.02;
        if (!$mismaForma) {
            return $porOmision;
        }

        $escala = $ancho / $anchoVisto;
        $lado = (int) round($lado * $escala);
        $x = (int) round($x * $escala);
        $y = (int) round($y * $escala);

        // Y ahora se encaja: nunca más grande que la imagen, nunca fuera de
        // ella. Estos tres pasos son los que hacen que un envío manipulado no
        // pueda pedir un recorte imposible.
        $lado = max(16, min($lado, $maximo));
        $x = max(0, min($x, $ancho - $lado));
        $y = max(0, min($y, $alto - $lado));

        return [$x, $y, $lado];
    }

    /**
     * Aplica la orientación EXIF, si la hay y si la extensión está disponible.
     *
     * @param \GdImage $imagen
     * @return \GdImage el mismo recurso si no había nada que girar
     */
    private static function enderezar(\GdImage $imagen, string $ruta, string $tipo): \GdImage
    {
        if ($tipo !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $imagen;
        }

        // exif_read_data() avisa con un warning ante metadatos rotos, y el
        // manejador de errores de la plataforma convierte los warnings en
        // excepciones: una foto con EXIF mal formado tumbaría el preregistro.
        try {
            $exif = @exif_read_data($ruta);
        } catch (\Throwable $e) {
            return $imagen;
        }

        $orientacion = (int) ($exif['Orientation'] ?? 1);
        $girada = match ($orientacion) {
            3 => imagerotate($imagen, 180, 0),
            6 => imagerotate($imagen, -90, 0),
            8 => imagerotate($imagen, 90, 0),
            default => false,
        };

        return $girada === false ? $imagen : $girada;
    }
}
