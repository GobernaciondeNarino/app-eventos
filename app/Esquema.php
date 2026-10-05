<?php
declare(strict_types=1);

namespace App;

defined('EVENTOS_TIC') || exit;

use App\Nucleo\Bd;

/**
 * Definición del modelo de datos.
 *
 * Fuente única para el instalador, las migraciones y la documentación. Si una
 * columna cambia, cambia aquí y todo lo demás la sigue.
 */
final class Esquema
{
    public const VERSION = '1.6.0';

    /**
     * Columnas que ya existen pero cambiaron de tipo.
     *
     * «actualizar» solo agrega lo que falta, y eso no alcanza cuando una
     * columna pasa de NOT NULL a nulable, o cuando a un ENUM le entra un valor
     * nuevo: la tabla ya la tiene, así que nadie la toca y la instalación vieja
     * se queda con la definición antigua.
     *
     * Cada ajuste dice qué tiene que ser cierto para aplicarse, y se comprueba
     * contra information_schema antes de ejecutar el ALTER. Así se puede correr
     * muchas veces sin que pase nada: es el requisito para que el botón de
     * actualizar del panel sea seguro con el evento en curso.
     *
     * Condiciones:
     *   'no_nulable'     la columna todavía es NOT NULL
     *   'falta_en_tipo'  el tipo actual no contiene «busca» (un valor de ENUM)
     *
     * @var array<int, array{tabla:string, columna:string, tipo:string, si:string, busca?:string}>
     */
    private const AJUSTES = [
        [
            'tabla'   => 'persona',
            'columna' => 'documento_cifrado',
            'tipo'    => 'VARBINARY(255) NULL DEFAULT NULL',
            'si'      => 'no_nulable',
        ],
        [
            'tabla'   => 'persona',
            'columna' => 'documento_huella',
            'tipo'    => 'CHAR(64) NULL DEFAULT NULL',
            'si'      => 'no_nulable',
        ],
        [
            'tabla'   => 'persona',
            'columna' => 'rol',
            'tipo'    => "ENUM('participante','visitante','expositor','organizador','prensa','staff') "
                         . "NOT NULL DEFAULT 'participante'",
            'si'      => 'falta_en_tipo',
            'busca'   => "'staff'",
        ],
    ];

    /**
     * @return array<string, array{nota: string, columnas: array<string,string>, llaves: array<int,string>}>
     */
    public static function tablas(): array
    {
        return [
            'evento' => [
                'nota' => 'Un registro por evento. La plataforma es multievento desde el día uno.',
                'columnas' => [
                    'id'            => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'nombre'        => 'VARCHAR(160) NOT NULL',
                    'dependencia'   => "VARCHAR(160) NOT NULL DEFAULT ''",
                    'sede'          => "VARCHAR(160) NOT NULL DEFAULT ''",
                    'fecha_inicio'  => 'DATE NOT NULL',
                    'jornadas'      => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
                    'estado'        => "ENUM('borrador','abierto','en_curso','cerrado') NOT NULL DEFAULT 'borrador'",
                    'activo'        => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'creado_en'     => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'llaves' => ['PRIMARY KEY (id)', 'KEY idx_evento_estado (estado, activo)'],
            ],

            'evento_tema' => [
                'nota' => 'Identidad visual: paleta, tipografía y logo. Es lo que se convierte en variables CSS.',
                'columnas' => [
                    'evento_id'      => 'INT UNSIGNED NOT NULL',
                    'preset'         => "VARCHAR(40) NOT NULL DEFAULT 'tic-nocturno'",
                    'tipografia'     => "VARCHAR(40) NOT NULL DEFAULT 'tecnologica'",
                    'colores_json'   => 'TEXT NULL',
                    'logo_archivo'   => "VARCHAR(120) NOT NULL DEFAULT ''",
                    'logo_tipo'      => "VARCHAR(40) NOT NULL DEFAULT ''",
                    'actualizado_en' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'llaves' => [
                    'PRIMARY KEY (evento_id)',
                    'CONSTRAINT fk_tema_evento FOREIGN KEY (evento_id) REFERENCES {evento} (id) ON DELETE CASCADE',
                ],
            ],

            'evento_dia' => [
                'nota' => 'Las jornadas. El código QR de acceso cuelga de aquí, no del evento: por eso cambia cada día.',
                'columnas' => [
                    'id'              => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'evento_id'       => 'INT UNSIGNED NOT NULL',
                    'numero'          => 'TINYINT UNSIGNED NOT NULL',
                    'fecha'           => 'DATE NOT NULL',
                    'abre_a'          => "TIME NOT NULL DEFAULT '06:00:00'",
                    'cierra_a'        => "TIME NOT NULL DEFAULT '22:00:00'",
                    'token'           => 'CHAR(32) NOT NULL',
                    'token_rotado_en' => 'DATETIME NULL',
                    'creado_en'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'UNIQUE KEY uq_dia (evento_id, numero)',
                    'UNIQUE KEY uq_dia_token (token)',
                    'CONSTRAINT fk_dia_evento FOREIGN KEY (evento_id) REFERENCES {evento} (id) ON DELETE CASCADE',
                ],
            ],

            'persona' => [
                'nota' => 'Quien se preregistra. El documento va cifrado, con una huella aparte para detectar duplicados sin descifrar.',
                'columnas' => [
                    'id'                 => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'evento_id'          => 'INT UNSIGNED NOT NULL',
                    'nombre'             => 'VARCHAR(160) NOT NULL',
                    'correo'             => 'VARCHAR(190) NOT NULL',
                    'tipo_documento'     => "ENUM('CC','CE','TI','PP') NOT NULL DEFAULT 'CC'",
                    // Nulables desde la 1.4.0: se puede crear el acceso con
                    // solo correo y contraseña y completar el resto después.
                    // En MySQL los nulos no chocan entre sí, así que la llave
                    // única sigue valiendo con varios registros a medias.
                    'documento_cifrado'  => 'VARBINARY(255) NULL DEFAULT NULL',
                    'documento_huella'   => 'CHAR(64) NULL DEFAULT NULL',
                    'telefono'           => "VARCHAR(32) NOT NULL DEFAULT ''",
                    'entidad'            => "VARCHAR(160) NOT NULL DEFAULT ''",
                    'departamento'       => "VARCHAR(80) NOT NULL DEFAULT ''",
                    'municipio'          => "VARCHAR(80) NOT NULL DEFAULT ''",
                    // «staff» da acceso a la plataforma —ver y acreditar—, así
                    // que solo lo asigna un administrador desde la ficha. El
                    // formulario público no lo acepta ni enviándolo a mano:
                    // ver Persona::ROLES_PUBLICOS.
                    'rol'                => "ENUM('participante','visitante','expositor','organizador','prensa','staff') NOT NULL DEFAULT 'participante'",
                    'comparte_telefono'  => 'TINYINT(1) NOT NULL DEFAULT 1',
                    // Métodos de acceso distintos del código por correo.
                    // Van aquí y no en una tabla aparte porque son uno por
                    // persona y se leen en cada intento de entrada.
                    'clave_hash'         => "VARCHAR(255) NOT NULL DEFAULT ''",
                    // Fotografía del carnet. Solo el nombre del archivo en
                    // almacen/fotos y su tipo real, decidido por el servidor:
                    // nunca se sirve desde el disco ni se confía en la extensión.
                    'foto'               => "VARCHAR(80) NOT NULL DEFAULT ''",
                    'foto_tipo'          => "VARCHAR(40) NOT NULL DEFAULT ''",
                    // Nulable a propósito: con una llave única, dos cadenas
                    // vacías chocarían y la segunda persona sin QR no se podría
                    // guardar. En MySQL los nulos no chocan entre sí.
                    'acceso_token'       => 'VARCHAR(32) NULL DEFAULT NULL',
                    'acceso_token_en'    => 'DATETIME NULL DEFAULT NULL',
                    'en_directorio'      => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'autorizo_datos_en'  => 'DATETIME NOT NULL',
                    'creado_en'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'UNIQUE KEY uq_persona_correo (evento_id, correo)',
                    'UNIQUE KEY uq_persona_doc (evento_id, documento_huella)',
                    'KEY idx_persona_municipio (evento_id, municipio)',
                    'KEY idx_persona_rol (evento_id, rol)',
                    'UNIQUE KEY uq_persona_acceso (acceso_token)',
                    'CONSTRAINT fk_persona_evento FOREIGN KEY (evento_id) REFERENCES {evento} (id) ON DELETE CASCADE',
                ],
            ],

            'persona_caracterizacion' => [
                'nota' => 'Datos sensibles (Ley 1581, art. 5) en tabla aparte: las consultas del día a día no los tocan y su lectura se audita.',
                'columnas' => [
                    'persona_id'   => 'INT UNSIGNED NOT NULL',
                    'genero'       => "VARCHAR(20) NOT NULL DEFAULT ''",
                    'rango_edad'   => "VARCHAR(12) NOT NULL DEFAULT ''",
                    'etnia'        => "VARCHAR(40) NOT NULL DEFAULT ''",
                    'discapacidad' => "VARCHAR(40) NOT NULL DEFAULT ''",
                ],
                'llaves' => [
                    'PRIMARY KEY (persona_id)',
                    'CONSTRAINT fk_caract_persona FOREIGN KEY (persona_id) REFERENCES {persona} (id) ON DELETE CASCADE',
                ],
            ],

            'credencial' => [
                'nota' => 'El carnet. El token es lo único que viaja en el QR; nunca datos personales.',
                'columnas' => [
                    'id'           => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'persona_id'   => 'INT UNSIGNED NOT NULL',
                    'codigo'       => 'VARCHAR(32) NOT NULL',
                    'token'        => 'CHAR(32) NOT NULL',
                    'foto_archivo' => "VARCHAR(120) NOT NULL DEFAULT ''",
                    'emitida_en'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'revocada_en'  => 'DATETIME NULL',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'UNIQUE KEY uq_credencial_token (token)',
                    'UNIQUE KEY uq_credencial_codigo (codigo)',
                    'UNIQUE KEY uq_credencial_persona (persona_id)',
                    'CONSTRAINT fk_credencial_persona FOREIGN KEY (persona_id) REFERENCES {persona} (id) ON DELETE CASCADE',
                ],
            ],

            'asistencia' => [
                'nota' => 'Un ingreso por persona y jornada. La llave única es lo que impide contar dos veces a la misma persona.',
                'columnas' => [
                    'id'            => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'persona_id'    => 'INT UNSIGNED NOT NULL',
                    'evento_dia_id' => 'INT UNSIGNED NOT NULL',
                    'registrado_en' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'via'           => "ENUM('qr_dia','carnet_operador','manual') NOT NULL DEFAULT 'qr_dia'",
                    'operador_id'   => 'INT UNSIGNED NULL',
                    // Quién selló: alguien del equipo (tabla usuario) o una
                    // persona con perfil Staff (tabla persona). Sin esto,
                    // operador_id sería un número sin tabla a la que apuntar y
                    // el usuario 7 y el staff 7 se confundirían en los reportes.
                    'operador_tipo' => "ENUM('equipo','staff') NOT NULL DEFAULT 'equipo'",
                    'ip'            => 'VARBINARY(16) NULL',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'UNIQUE KEY uq_asistencia (persona_id, evento_dia_id)',
                    'KEY idx_asistencia_dia (evento_dia_id, registrado_en)',
                    'CONSTRAINT fk_asis_persona FOREIGN KEY (persona_id) REFERENCES {persona} (id) ON DELETE CASCADE',
                    'CONSTRAINT fk_asis_dia FOREIGN KEY (evento_dia_id) REFERENCES {evento_dia} (id) ON DELETE CASCADE',
                ],
            ],

            'contacto' => [
                'nota' => 'Intercambio de datos entre asistentes. Guarda quién escaneó a quién, para poder revertirlo si alguien lo pide.',
                'columnas' => [
                    'id'          => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'persona_id'  => 'INT UNSIGNED NOT NULL',
                    'contacto_id' => 'INT UNSIGNED NOT NULL',
                    'creado_en'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'revocado_en' => 'DATETIME NULL',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'UNIQUE KEY uq_contacto (persona_id, contacto_id)',
                    'CONSTRAINT fk_contacto_a FOREIGN KEY (persona_id) REFERENCES {persona} (id) ON DELETE CASCADE',
                    'CONSTRAINT fk_contacto_b FOREIGN KEY (contacto_id) REFERENCES {persona} (id) ON DELETE CASCADE',
                ],
            ],

            'propuesta' => [
                'nota' => 'Lo que envía un expositor en el preregistro. Al aprobarse se convierte en charla.',
                'columnas' => [
                    'id'             => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'persona_id'     => 'INT UNSIGNED NOT NULL',
                    'titulo'         => 'VARCHAR(200) NOT NULL',
                    'categoria'      => 'VARCHAR(80) NOT NULL',
                    'detalle'        => 'TEXT NOT NULL',
                    'dia_preferido'  => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
                    'duracion_min'   => 'SMALLINT UNSIGNED NOT NULL DEFAULT 40',
                    'requerimientos' => "VARCHAR(255) NOT NULL DEFAULT ''",
                    // Los dos adjuntos del expositor. Como en la foto del
                    // carnet, aquí solo va el nombre que puso el servidor
                    // dentro de almacen/documentos y el tipo real que se
                    // comprobó al recibirlo; el archivo nunca toca la raíz web.
                    'hoja_vida'       => "VARCHAR(120) NOT NULL DEFAULT ''",
                    'hoja_vida_tipo'  => "VARCHAR(120) NOT NULL DEFAULT ''",
                    'exposicion'      => "VARCHAR(120) NOT NULL DEFAULT ''",
                    'exposicion_tipo' => "VARCHAR(120) NOT NULL DEFAULT ''",
                    'estado'         => "ENUM('pendiente','observada','aprobada','rechazada') NOT NULL DEFAULT 'pendiente'",
                    'observacion'    => 'TEXT NULL',
                    'revisada_por'   => 'INT UNSIGNED NULL',
                    'revisada_en'    => 'DATETIME NULL',
                    'creado_en'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'KEY idx_propuesta_estado (estado)',
                    'CONSTRAINT fk_propuesta_persona FOREIGN KEY (persona_id) REFERENCES {persona} (id) ON DELETE CASCADE',
                ],
            ],

            'charla' => [
                'nota' => 'La agenda pública: propuestas aprobadas con horario y salón asignados.',
                'columnas' => [
                    'id'            => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'propuesta_id'  => 'INT UNSIGNED NOT NULL',
                    'evento_dia_id' => 'INT UNSIGNED NOT NULL',
                    'hora_inicio'   => "TIME NOT NULL DEFAULT '09:00:00'",
                    'salon'         => "VARCHAR(80) NOT NULL DEFAULT ''",
                    'publicada'     => 'TINYINT(1) NOT NULL DEFAULT 1',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'UNIQUE KEY uq_charla_propuesta (propuesta_id)',
                    'KEY idx_charla_dia (evento_dia_id, hora_inicio)',
                    'CONSTRAINT fk_charla_propuesta FOREIGN KEY (propuesta_id) REFERENCES {propuesta} (id) ON DELETE CASCADE',
                    'CONSTRAINT fk_charla_dia FOREIGN KEY (evento_dia_id) REFERENCES {evento_dia} (id) ON DELETE CASCADE',
                ],
            ],

            'usuario' => [
                'nota' => 'El equipo organizador. Contraseña con Argon2id y segundo factor obligatorio para el rol administrador.',
                'columnas' => [
                    'id'                => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'nombre'            => 'VARCHAR(160) NOT NULL',
                    'correo'            => 'VARCHAR(190) NOT NULL',
                    'clave_hash'        => 'VARCHAR(255) NOT NULL',
                    'rol'               => "ENUM('administrador','operador','consulta') NOT NULL DEFAULT 'operador'",
                    'puesto'            => "VARCHAR(80) NOT NULL DEFAULT ''",
                    'totp_secreto'      => 'VARBINARY(255) NULL',
                    'totp_confirmado'   => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'totp_ultimo'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'estado'            => "ENUM('activo','suspendido') NOT NULL DEFAULT 'activo'",
                    'debe_cambiar'      => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'ultimo_acceso'     => 'DATETIME NULL',
                    'creado_en'         => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'llaves' => ['PRIMARY KEY (id)', 'UNIQUE KEY uq_usuario_correo (correo)'],
            ],

            'sesion' => [
                'nota' => 'Sesiones en base de datos: se pueden cerrar a distancia y no quedan en archivos compartidos del servidor.',
                'columnas' => [
                    'id'           => 'CHAR(64) NOT NULL',
                    'tipo'         => "ENUM('admin','asistente') NOT NULL",
                    'sujeto_id'    => 'INT UNSIGNED NOT NULL',
                    'datos'        => 'TEXT NULL',
                    'ip'           => 'VARBINARY(16) NULL',
                    'agente'       => "VARCHAR(255) NOT NULL DEFAULT ''",
                    'ultima_senal' => 'DATETIME NOT NULL',
                    'expira_en'    => 'DATETIME NOT NULL',
                    'creado_en'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'KEY idx_sesion_expira (expira_en)',
                    'KEY idx_sesion_sujeto (tipo, sujeto_id)',
                ],
            ],

            'dispositivo' => [
                'nota' => 'Teléfonos desde los que un asistente ya entró. Le evitan volver a pedir el código cada vez.',
                'columnas' => [
                    'selector'       => 'CHAR(32) NOT NULL',
                    'persona_id'     => 'INT UNSIGNED NOT NULL',
                    // Del validador se guarda solo el hash: quien lea la tabla
                    // no obtiene cookies utilizables.
                    'validador_hash' => 'CHAR(64) NOT NULL',
                    'agente'         => "VARCHAR(255) NOT NULL DEFAULT ''",
                    'ip'             => 'VARBINARY(16) NULL',
                    'ultimo_uso'     => 'DATETIME NOT NULL',
                    'expira_en'      => 'DATETIME NOT NULL',
                    'creado_en'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'llaves' => [
                    'PRIMARY KEY (selector)',
                    'KEY idx_dispositivo_persona (persona_id)',
                    'KEY idx_dispositivo_expira (expira_en)',
                    'CONSTRAINT fk_dispositivo_persona FOREIGN KEY (persona_id) REFERENCES {persona} (id) ON DELETE CASCADE',
                ],
            ],

            'codigo_acceso' => [
                'nota' => 'Códigos de un solo uso que se envían por correo al asistente. Se guarda el hash, no el código.',
                'columnas' => [
                    'id'         => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'persona_id' => 'INT UNSIGNED NOT NULL',
                    'codigo_hash'=> 'CHAR(64) NOT NULL',
                    'destino'    => "VARCHAR(255) NOT NULL DEFAULT ''",
                    'usado_en'   => 'DATETIME NULL',
                    'expira_en'  => 'DATETIME NOT NULL',
                    'creado_en'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'KEY idx_codigo_persona (persona_id, expira_en)',
                    'CONSTRAINT fk_codigo_persona FOREIGN KEY (persona_id) REFERENCES {persona} (id) ON DELETE CASCADE',
                ],
            ],

            'intento' => [
                'nota' => 'Contador de intentos fallidos para el límite de fuerza bruta. La clave se guarda como HMAC, no en claro.',
                'columnas' => [
                    'id'        => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'huella'    => 'CHAR(64) NOT NULL',
                    'accion'    => 'VARCHAR(40) NOT NULL',
                    'ip'        => 'VARBINARY(16) NULL',
                    'creado_en' => 'DATETIME NOT NULL',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'KEY idx_intento_huella (huella, creado_en)',
                ],
            ],

            'bitacora' => [
                'nota' => 'Auditoría. Quién hizo qué, cuándo y desde dónde. Solo se inserta: no se actualiza ni se borra.',
                'columnas' => [
                    'id'         => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'usuario_id' => 'INT UNSIGNED NULL',
                    'accion'     => 'VARCHAR(80) NOT NULL',
                    'entidad'    => "VARCHAR(60) NOT NULL DEFAULT ''",
                    'entidad_id' => 'INT UNSIGNED NULL',
                    'detalle'    => 'TEXT NULL',
                    'ip'         => 'VARBINARY(16) NULL',
                    'creado_en'  => 'DATETIME NOT NULL',
                ],
                'llaves' => [
                    'PRIMARY KEY (id)',
                    'KEY idx_bitacora_fecha (creado_en)',
                    'KEY idx_bitacora_accion (accion)',
                ],
            ],

            'migracion' => [
                'nota' => 'Qué versión del esquema está aplicada. Sin esto el instalador no sabría si actualizar o instalar.',
                'columnas' => [
                    'version'     => 'VARCHAR(20) NOT NULL',
                    'aplicada_en' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'descripcion' => "VARCHAR(190) NOT NULL DEFAULT ''",
                ],
                'llaves' => ['PRIMARY KEY (version)'],
            ],
        ];
    }

    /** Nombres en el orden de creación (las llaves foráneas dependen de él). */
    public static function nombres(): array
    {
        return array_keys(self::tablas());
    }

    /** CREATE TABLE de una tabla, con el prefijo ya resuelto. */
    public static function crear(string $nombre, string $prefijo, bool $siNoExiste = true): string
    {
        $tabla = self::tablas()[$nombre] ?? null;
        if ($tabla === null) {
            throw new \InvalidArgumentException("Tabla desconocida: $nombre");
        }

        $partes = [];
        foreach ($tabla['columnas'] as $columna => $tipo) {
            $partes[] = "  `$columna` $tipo";
        }
        foreach ($tabla['llaves'] as $llave) {
            $partes[] = '  ' . self::aplicarPrefijo($llave, $prefijo);
        }

        return "-- {$tabla['nota']}\n"
            . 'CREATE TABLE ' . ($siNoExiste ? 'IF NOT EXISTS ' : '')
            . "`$prefijo$nombre` (\n"
            . implode(",\n", $partes) . "\n"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
    }

    /**
     * Resuelve {tabla} y pone el prefijo también en los nombres de las claves
     * foráneas.
     *
     * En MySQL el nombre de una clave foránea es único en toda la base, no solo
     * dentro de su tabla. Sin prefijo, instalar dos veces la plataforma con
     * prefijos distintos —evt_ y cumbre_— en la misma base fallaba a mitad del
     * esquema con el errno 121, dejando las tablas a medio crear.
     */
    private static function aplicarPrefijo(string $texto, string $prefijo): string
    {
        $texto = preg_replace_callback(
            '/\bCONSTRAINT\s+(fk_[a-z0-9_]+)\b/i',
            static fn(array $m): string => 'CONSTRAINT `' . $prefijo . $m[1] . '`',
            $texto
        ) ?? $texto;

        return preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn(array $m): string => '`' . $prefijo . $m[1] . '`',
            $texto
        ) ?? $texto;
    }

    /**
     * Guion completo, para previsualizar antes de ejecutar.
     * $modo: 'limpio' | 'actualizar' | 'anexar'
     */
    public static function guion(string $prefijo, string $modo, array $existentes = []): string
    {
        $lineas = [
            '-- Plataforma de Eventos TIC · esquema ' . self::VERSION,
            '-- Modo: ' . $modo,
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS = 0;',
            '',
        ];

        if ($modo === 'limpio') {
            $lineas[] = '-- Se eliminan las tablas existentes con este prefijo.';
            foreach (array_reverse(self::nombres()) as $nombre) {
                $lineas[] = "DROP TABLE IF EXISTS `$prefijo$nombre`;";
            }
            $lineas[] = '';
        }

        foreach (self::nombres() as $nombre) {
            $existe = in_array($nombre, $existentes, true);
            if ($modo === 'anexar' && $existe) {
                $lineas[] = "-- `$prefijo$nombre` ya existe: se deja intacta.";
                $lineas[] = '';
                continue;
            }
            $lineas[] = self::crear($nombre, $prefijo, $modo !== 'limpio');
            $lineas[] = '';
        }

        if ($modo === 'actualizar') {
            $lineas[] = '-- Columnas que falten en tablas ya existentes se agregan una a una,';
            $lineas[] = '-- consultando antes information_schema (compatible con MySQL y MariaDB).';
            $lineas[] = '';
        }

        $lineas[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $lineas[] = "INSERT INTO `{$prefijo}migracion` (version, descripcion)";
        $lineas[] = "  VALUES ('" . self::VERSION . "', 'Instalación " . $modo . "')";
        $lineas[] = '  ON DUPLICATE KEY UPDATE aplicada_en = CURRENT_TIMESTAMP;';

        return implode("\n", $lineas);
    }

    /**
     * Aplica el esquema de verdad.
     *
     * Devuelve la lista de acciones ejecutadas, que el instalador muestra al
     * terminar. Cada sentencia va suelta y no en un bloque: si una falla, se
     * sabe exactamente cuál.
     */
    public static function aplicar(string $modo, array $existentes = []): array
    {
        $prefijo = Bd::prefijo();
        $hechas = [];

        Bd::ejecutarBruto('SET FOREIGN_KEY_CHECKS = 0');

        try {
            if ($modo === 'limpio') {
                foreach (array_reverse(self::nombres()) as $nombre) {
                    Bd::ejecutarBruto("DROP TABLE IF EXISTS `$prefijo$nombre`");
                }
                $hechas[] = ['tabla' => '(todas)', 'accion' => 'eliminadas', 'detalle' => count($existentes) . ' tablas previas'];
            }

            foreach (self::tablas() as $nombre => $definicion) {
                $existe = in_array($nombre, $existentes, true) && $modo !== 'limpio';

                if (!$existe) {
                    Bd::ejecutarBruto(self::crear($nombre, $prefijo, true));
                    $hechas[] = ['tabla' => $prefijo . $nombre, 'accion' => 'creada',
                                 'detalle' => count($definicion['columnas']) . ' columnas'];
                    continue;
                }

                if ($modo === 'anexar') {
                    $hechas[] = ['tabla' => $prefijo . $nombre, 'accion' => 'intacta', 'detalle' => 'ya existía'];
                    continue;
                }

                // Modo actualizar: se agregan solo las columnas que falten, y
                // se corrigen las que cambiaron de tipo.
                $agregadas = self::agregarColumnasFaltantes($nombre, $definicion, $prefijo);
                $ajustadas = $modo === 'actualizar' ? self::ajustarColumnas($nombre, $prefijo) : [];
                $cambios = array_merge($agregadas, $ajustadas);

                $hechas[] = [
                    'tabla'   => $prefijo . $nombre,
                    'accion'  => $cambios ? 'actualizada' : 'sin cambios',
                    'detalle' => $cambios ? implode(', ', $cambios) : 'ya estaba al día',
                ];
            }
        } finally {
            Bd::ejecutarBruto('SET FOREIGN_KEY_CHECKS = 1');
        }

        Bd::ejecutar(
            'INSERT INTO {migracion} (version, descripcion) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE aplicada_en = CURRENT_TIMESTAMP',
            [self::VERSION, 'Instalación ' . $modo]
        );

        return $hechas;
    }

    /**
     * ALTER TABLE ... ADD COLUMN para lo que falte.
     *
     * Se consulta information_schema en vez de usar «ADD COLUMN IF NOT EXISTS»
     * porque esa sintaxis es de MariaDB y en MySQL 8 falla. La plataforma tiene
     * que instalarse igual en los dos.
     */
    private static function agregarColumnasFaltantes(string $nombre, array $definicion, string $prefijo): array
    {
        $actuales = array_column(
            Bd::filasDirecto(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$prefijo . $nombre]
            ),
            'COLUMN_NAME'
        );

        $agregadas = [];
        $previa = null;
        foreach ($definicion['columnas'] as $columna => $tipo) {
            if (!in_array($columna, $actuales, true)) {
                $donde = $previa ? " AFTER `$previa`" : ' FIRST';
                Bd::ejecutarBruto("ALTER TABLE `$prefijo$nombre` ADD COLUMN `$columna` $tipo$donde");
                $agregadas[] = $columna;
            }
            $previa = $columna;
        }
        return $agregadas;
    }

    /**
     * ALTER TABLE ... MODIFY para las columnas que cambiaron de tipo.
     *
     * Solo se toca lo que de verdad hace falta: se pregunta a
     * information_schema por el estado actual y, si ya está como debe, no se
     * ejecuta nada. Ejecutarlo dos veces tiene que ser inofensivo, porque el
     * botón de actualizar del panel se puede pulsar con el evento abierto.
     *
     * @return array<int, string> nombres de las columnas modificadas
     */
    private static function ajustarColumnas(string $nombre, string $prefijo): array
    {
        $pendientes = array_values(array_filter(
            self::AJUSTES,
            static fn(array $a): bool => $a['tabla'] === $nombre
        ));
        if ($pendientes === []) {
            return [];
        }

        $estado = [];
        foreach (Bd::filasDirecto(
            'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$prefijo . $nombre]
        ) as $fila) {
            $estado[(string) $fila['COLUMN_NAME']] = [
                'nulable' => (string) $fila['IS_NULLABLE'],
                'tipo'    => (string) $fila['COLUMN_TYPE'],
            ];
        }

        $hechas = [];
        foreach ($pendientes as $ajuste) {
            $actual = $estado[$ajuste['columna']] ?? null;
            if ($actual === null) {
                continue;   // la columna no existe todavía; ya la agregó el paso anterior
            }

            $hace = match ($ajuste['si']) {
                'no_nulable'    => $actual['nulable'] === 'NO',
                'falta_en_tipo' => !str_contains($actual['tipo'], (string) ($ajuste['busca'] ?? '')),
                default         => false,
            };
            if (!$hace) {
                continue;   // ya está como tiene que estar
            }

            Bd::ejecutarBruto(
                "ALTER TABLE `$prefijo$nombre` MODIFY COLUMN `{$ajuste['columna']}` {$ajuste['tipo']}"
            );
            $hechas[] = $ajuste['columna']
                . ($ajuste['si'] === 'no_nulable' ? ' (nulable)' : ' (tipo)');
        }
        return $hechas;
    }

    /** Qué tablas del esquema ya están en la base. */
    public static function existentes(): array
    {
        $prefijo = Bd::prefijo();
        $encontradas = array_column(
            Bd::filasDirecto(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            ),
            'TABLE_NAME'
        );

        $nuestras = [];
        foreach (self::nombres() as $nombre) {
            if (in_array($prefijo . $nombre, $encontradas, true)) {
                $nuestras[] = $nombre;
            }
        }
        return $nuestras;
    }

    /** Versión aplicada, o null si no hay tabla de migraciones. */
    public static function versionInstalada(): ?string
    {
        try {
            $filas = Bd::filas('SELECT version FROM {migracion}');
        } catch (\Throwable) {
            return null;
        }
        if ($filas === []) {
            return null;
        }

        // La más alta, no la más reciente.
        //
        // Antes era «ORDER BY aplicada_en DESC LIMIT 1», y eso falla justo
        // cuando más duele: dos filas escritas en el mismo segundo —una
        // actualización inmediatamente después de otra— empatan, y con un empate
        // el orden que devuelve la base es indeterminado. Si gana la vieja, el
        // aviso de «la base está atrasada» se queda puesto para siempre y pulsar
        // el botón no lo quita, porque la fila nueva ya existe.
        //
        // La pregunta que de verdad se hace aquí es «qué versión está aplicada»,
        // y eso se responde comparando versiones. version_compare además ordena
        // bien 1.10 frente a 1.9, que como texto quedarían al revés.
        $mayor = null;
        foreach ($filas as $fila) {
            $v = (string) $fila['version'];
            if ($mayor === null || version_compare($v, $mayor, '>')) {
                $mayor = $v;
            }
        }
        return $mayor;
    }

    /**
     * ¿Le falta a la base algo de esta versión del código?
     *
     * Se mira la versión anotada y, además, si están todas las tablas. Lo
     * segundo importa: una instalación puede tener anotada la versión correcta
     * y haberse quedado sin una tabla por un error a mitad, y quien mira el
     * panel no tiene forma de enterarse hasta que algo falla en la puerta del
     * evento.
     *
     * Devuelve [pendiente, motivo].
     *
     * @return array{0: bool, 1: string}
     */
    /** @var array{0:bool,1:string}|null Respuesta de revisionPendiente() en esta petición. */
    private static ?array $revision = null;

    public static function revisionPendiente(): array
    {
        // Se consulta information_schema dos veces, y desde la 3.4 esto lo
        // pregunta cada pantalla del equipo y no solo el panel. Una vez por
        // petición es suficiente: el esquema no cambia a mitad de una.
        if (self::$revision !== null) {
            return self::$revision;
        }
        return self::$revision = self::revisar();
    }

    private static function revisar(): array
    {
        try {
            $faltan = array_values(array_diff(self::nombres(), self::existentes()));
        } catch (\Throwable) {
            return [false, ''];
        }

        if ($faltan !== []) {
            return [true, 'Faltan ' . count($faltan) . ' tabla(s): ' . implode(', ', $faltan) . '.'];
        }

        $aplicada = self::versionInstalada();
        if ($aplicada === null) {
            return [true, 'No hay ninguna versión anotada en la tabla de migraciones.'];
        }
        if (version_compare($aplicada, self::VERSION, '<')) {
            return [true, 'La base está en la versión ' . $aplicada
                . ' y el código necesita la ' . self::VERSION . '.'];
        }

        return [false, ''];
    }
}
