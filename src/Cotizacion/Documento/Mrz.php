<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use DateTimeImmutable;

/**
 * La zona legible por máquina de un pasaporte (ICAO 9303, formato TD3: dos líneas de 44).
 *
 * 🔑 **Esto es lo que convierte «el modelo dice» en «está comprobado».** Un modelo de visión lee
 * un pasaporte y devuelve un número con toda la seguridad del mundo, acertado o no; `CLAUDE.md`
 * ya lo avisa para el agente —«lo que decide el modelo, valídalo con código»— y aquí se puede
 * cumplir a rajatabla, porque **la MRZ lleva sus propios dígitos de control**. Un `7` leído como
 * `1` rompe la suma y se caza sin consultar nada ni a nadie.
 *
 * Sin esto, el único modo de saber si la extracción acertó sería que una persona comparase a mano
 * — que es exactamente el trabajo que se quería quitar.
 *
 * ### Dos formatos, y el segundo llegó por sorpresa
 *
 * 🔥 **El DNI peruano nuevo lleva MRZ en el ANVERSO**, formato TD1: tres líneas de 30. Se creía que
 * no tenía banda —y por eso el DNI se quedaba siempre en `VALIDADO_OCR`, sin respaldo aritmético—
 * hasta que se miró un escaneo de cerca. Con TD1, un DNI puede llegar a `VALIDADO_MRZ` igual que un
 * pasaporte, y eso cambia la mitad del padrón: son 86 anversos.
 *
 * ⚠️ **Medio soportar un formato es peor que no soportarlo**, así que cada uno tiene sus posiciones
 * y su longitud exacta. Leer un TD1 con las posiciones de TD3 sacaría campos de sitios equivocados
 * y los dígitos no cuadrarían: parecería un error de lectura y no un formato no contemplado.
 *
 * **TD3** (pasaporte, 2 × 44). Segunda línea:
 *
 *     0-8   número de documento          9    dígito de control
 *     10-12 nacionalidad                 13-18 nacimiento (AAMMDD)
 *     19    dígito de control            20    sexo
 *     21-26 vencimiento (AAMMDD)         27    dígito de control
 *     28-41 número personal              42    dígito de control
 *     43    dígito COMPUESTO sobre 0-9, 13-19 y 21-42
 *
 * **TD1** (DNI, 3 × 30). Los mismos datos repartidos en dos líneas:
 *
 *     línea 1:  0-1 tipo · 2-4 emisor · 5-13 número · 14 control · 15-29 opcional
 *     línea 2:  0-5 nacimiento · 6 control · 7 sexo · 8-13 vencimiento · 14 control
 *               15-17 nacionalidad · 18-28 opcional · 29 dígito COMPUESTO
 *     línea 3:  apellidos<<nombres
 */
final readonly class Mrz
{
    private function __construct(
        public string $numero,
        public string $paisEmisor,
        public string $nacionalidad,
        public string $apellidos,
        public string $nombres,
        public ?string $sexo,
        public ?DateTimeImmutable $nacimiento,
        public ?DateTimeImmutable $vencimiento,
        /** @var list<string> Qué dígitos de control NO cuadran. Vacío = la MRZ es coherente. */
        public array $problemas,
        /** TD3 = pasaporte; TD1 = documento de identidad. Ver {@see self::esPasaporte()}. */
        private bool $esTd3 = true,
    ) {}

    /**
     * Lee la banda. `null` cuando no es una MRZ reconocible, que es distinto de «no cuadra»: lo
     * primero es un formato que no toca, lo segundo un dato que hay que revisar.
     *
     * La tercera línea sólo la trae el TD1; para un pasaporte llega vacía.
     */
    public static function desde(string $linea1, string $linea2, string $linea3 = ''): ?self
    {
        $l1 = self::normalizar($linea1);
        $l2 = self::normalizar($linea2);
        $l3 = self::normalizar($linea3);

        // El formato se decide por la LONGITUD, que es lo que de verdad los distingue. La `P` del
        // pasaporte no vale como única señal: un TD1 también empieza por letra.
        if (strlen($l1) === 30 && strlen($l2) === 30 && strlen($l3) === 30) {
            return self::td1($l1, $l2, $l3);
        }

        // 44 exactos y una `P` delante: sin las dos cosas, las posiciones de abajo no significan
        // nada y todo lo que saliera de aquí sería inventado con formato de dato bueno.
        if (strlen($l1) !== 44 || strlen($l2) !== 44 || !str_starts_with($l1, 'P')) {
            return null;
        }

        [$apellidos, $nombres] = self::partirNombre(substr($l1, 5));

        $numero = rtrim(substr($l2, 0, 9), '<');
        $nacimientoCrudo = substr($l2, 13, 6);
        $vencimientoCrudo = substr($l2, 21, 6);

        $problemas = [];
        foreach ([
            'número de documento' => [substr($l2, 0, 9), $l2[9]],
            'fecha de nacimiento' => [$nacimientoCrudo, $l2[19]],
            'fecha de vencimiento' => [$vencimientoCrudo, $l2[27]],
            // El compuesto cubre los tres anteriores más el número personal: si los tres cuadran
            // y éste no, lo que está mal es el número personal, que casi nunca se usa.
            'dígito compuesto' => [substr($l2, 0, 10) . substr($l2, 13, 7) . substr($l2, 21, 22), $l2[43]],
        ] as $que => [$campo, $esperado]) {
            if ($esperado !== '<' && self::digitoDeControl($campo) !== $esperado) {
                $problemas[] = $que;
            }
        }

        return new self(
            numero: $numero,
            paisEmisor: rtrim(substr($l1, 2, 3), '<'),
            nacionalidad: rtrim(substr($l2, 10, 3), '<'),
            apellidos: $apellidos,
            nombres: $nombres,
            sexo: in_array($l2[20], ['M', 'F'], true) ? $l2[20] : null,
            // ⚠️ Un nacimiento es SIEMPRE pasado y un vencimiento casi siempre futuro, y con dos
            // dígitos de año hay que elegir siglo. `680312` en nacimiento es 1968, no 2068.
            nacimiento: self::fecha($nacimientoCrudo, pasado: true),
            vencimiento: self::fecha($vencimientoCrudo, pasado: false),
            problemas: $problemas,
        );
    }

    /**
     * El TD1 del DNI: tres líneas de 30.
     *
     * ⚠️ **Los dígitos de control son los mismos cuatro que en TD3**, pero repartidos en otras
     * posiciones, así que la comprobación se escribe una vez y sólo cambia de dónde se saca cada
     * campo. Si esto se duplicara, la cuenta acabaría divergiendo entre formatos y un DNI daría
     * por bueno lo que un pasaporte rechaza.
     */
    private static function td1(string $l1, string $l2, string $l3): self
    {
        [$apellidos, $nombres] = self::partirNombre($l3);

        $nacimientoCrudo = substr($l2, 0, 6);
        $vencimientoCrudo = substr($l2, 8, 6);

        $problemas = [];
        foreach ([
            'número de documento' => [substr($l1, 5, 9), $l1[14]],
            'fecha de nacimiento' => [$nacimientoCrudo, $l2[6]],
            'fecha de vencimiento' => [$vencimientoCrudo, $l2[14]],
            // El compuesto del TD1 cubre casi toda la banda: línea 1 desde el número, y la 2 hasta
            // el opcional. Es el que caza una línea entera mal transcrita.
            'dígito compuesto' => [substr($l1, 5, 25) . substr($l2, 0, 7) . substr($l2, 8, 7) . substr($l2, 18, 11), $l2[29]],
        ] as $que => [$campo, $esperado]) {
            if ($esperado !== '<' && self::digitoDeControl($campo) !== $esperado) {
                $problemas[] = $que;
            }
        }

        return new self(
            numero: rtrim(substr($l1, 5, 9), '<'),
            paisEmisor: rtrim(substr($l1, 2, 3), '<'),
            nacionalidad: rtrim(substr($l2, 15, 3), '<'),
            apellidos: $apellidos,
            nombres: $nombres,
            sexo: in_array($l2[7], ['M', 'F'], true) ? $l2[7] : null,
            nacimiento: self::fecha($nacimientoCrudo, pasado: true),
            vencimiento: self::fecha($vencimientoCrudo, pasado: false),
            problemas: $problemas,
            esTd3: false,
        );
    }

    /**
     * ¿Es un pasaporte? Lo dice el FORMATO de la banda, no lo que parezca el documento.
     *
     * TD3 es siempre pasaporte; TD1 es documento de identidad. Se guarda al construir porque las
     * dos ramas ya lo saben y deducirlo después obligaría a mirar longitudes que aquí ya no están.
     */
    public function esPasaporte(): bool
    {
        return $this->esTd3;
    }

    /** ¿Cuadran todos los dígitos de control? */
    public function esCoherente(): bool
    {
        return $this->problemas === [];
    }

    /**
     * El dígito de control de ICAO 9303: pesos 7-3-1 girando, letras como 10..35, `<` como 0.
     *
     * ⚠️ Es la misma cuenta para los cuatro campos, y por eso vive una sola vez. Escrita cuatro
     * veces, la cuarta se escribe mal y ese campo deja de comprobarse sin decirlo.
     */
    private static function digitoDeControl(string $campo): string
    {
        $pesos = [7, 3, 1];
        $suma = 0;

        foreach (str_split($campo) as $i => $caracter) {
            $valor = match (true) {
                $caracter === '<' => 0,
                ctype_digit($caracter) => (int) $caracter,
                ctype_upper($caracter) => ord($caracter) - 55,   // A = 10 … Z = 35
                default => 0,
            };
            $suma += $valor * $pesos[$i % 3];
        }

        return (string) ($suma % 10);
    }

    /** AAMMDD → fecha, eligiendo el siglo por el lado en que tiene que caer. */
    private static function fecha(string $crudo, bool $pasado): ?DateTimeImmutable
    {
        if (!ctype_digit($crudo)) {
            return null;
        }

        $aa = (int) substr($crudo, 0, 2);
        $corte = (int) (new DateTimeImmutable())->format('y');
        $siglo = $pasado
            ? ($aa > $corte ? 1900 : 2000)
            : 2000;

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%s-%s', $siglo + $aa, substr($crudo, 2, 2), substr($crudo, 4, 2)));

        // `createFromFormat` acepta un 31 de febrero y lo corre al 3 de marzo. Un vencimiento
        // corrido tres días es un dato malo que parece bueno: mejor nada.
        return $fecha instanceof DateTimeImmutable && $fecha->format('ymd') === $crudo ? $fecha : null;
    }

    /**
     * `APELLIDOS<<NOMBRES<CON<ESPACIOS` → los dos, ya legibles.
     *
     * @return array{string, string}
     */
    private static function partirNombre(string $campo): array
    {
        $partes = explode('<<', rtrim($campo, '<'), 2);

        return [
            trim(str_replace('<', ' ', $partes[0])),
            trim(str_replace('<', ' ', $partes[1] ?? '')),
        ];
    }

    /** Mayúsculas, sin espacios y con los `«` que a veces devuelve un OCR pasados a `<`. */
    private static function normalizar(string $linea): string
    {
        return str_replace([' ', '«', '‹'], ['', '<', '<'], strtoupper(trim($linea)));
    }
}
