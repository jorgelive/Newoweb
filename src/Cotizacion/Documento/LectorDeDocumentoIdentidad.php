<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Agent\Vision\LectorDeImagenInterface;
use App\Enum\DocumentoTipoEnum;
use Symfony\Component\Intl\Countries;
use DateTimeImmutable;

/**
 * Lee un documento de identidad escaneado y propone sus datos.
 *
 * Aquí vive **el conocimiento del dominio** —qué campos tiene un documento, qué tipos existen, qué
 * es una MRZ—; el proveedor de visión no sabe nada de esto y se cambia sin tocar este archivo.
 *
 * ### Lo que hace de verdad, que no es «pedirle los datos al modelo»
 *
 * Le pide la **MRZ transcrita carácter a carácter** además de los campos sueltos, y entonces:
 *
 * 1. Comprueba la MRZ con sus dígitos de control ({@see Mrz}) — aritmética pura, sin confiar.
 * 2. Si cuadra, **manda la MRZ** sobre lo que el modelo leyó en el resto de la imagen.
 * 3. Y compara las dos lecturas: si difieren, eso es un aviso, no un empate.
 *
 * ⚠️ **El paso 3 es el que más vale y el más fácil de omitir.** Que el modelo lea el número dos
 * veces —una en la zona impresa y otra en la MRZ— y coincidan es una comprobación independiente
 * casi gratis. Si sólo se pidiera la MRZ, un error de transcripción coherente consigo mismo
 * pasaría; pedir las dos y contrastarlas lo caza.
 */
final readonly class LectorDeDocumentoIdentidad
{
    private const INSTRUCCION = <<<'TXT'
        Esto es el escaneo de un documento de identidad (pasaporte, DNI, cédula o carné).

        Transcribe lo que VES. No corrijas, no completes y no deduzcas: si un carácter no se lee,
        deja el campo vacío. Un dato inventado con la forma correcta es peor que un hueco, porque
        se guarda igual y nadie vuelve a mirarlo.

        Si el documento tiene banda legible por máquina (las dos líneas de caracteres con «<» al
        pie de un pasaporte), transcríbela EXACTA en mrzLinea1 y mrzLinea2: cada carácter, los «<»
        incluidos, sin espacios y sin arreglar nada de lo que te parezca un error. Esas dos líneas
        llevan dígitos de control y se comprueban aparte, así que una transcripción fiel vale más
        que una transcripción bonita.

        Rellena además los campos sueltos leyéndolos de la zona IMPRESA del documento, no de la
        banda: sirven para contrastar las dos lecturas.

        Fechas en formato AAAA-MM-DD. Países en ISO-3166 de tres letras. Sexo M o F.

        En «bordeSuperior», di en qué lado de la IMAGEN cae la parte de ARRIBA del documento — la
        cabecera, donde pone el país o el título. Responde sólo: arriba, derecha, abajo o
        izquierda. Si el documento se ve derecho, es «arriba». Fíjate en el texto, no en la forma
        de la foto.
        TXT;

    /** @var array<string, mixed> */
    private const ESQUEMA = [
        'type' => 'object',
        'properties' => [
            'tipo' => ['type' => 'string', 'enum' => ['DNI', 'CE', 'RUC', 'PASAPORTE', 'CI', 'OTRO']],
            'numero' => ['type' => 'string'],
            'nombres' => ['type' => 'string'],
            'apellidos' => ['type' => 'string'],
            'paisEmisor' => ['type' => 'string'],
            'nacionalidad' => ['type' => 'string'],
            // ⚠️ Sin `enum`, aunque los valores sean M y F. Un `enum` NO admite la cadena vacía
            // —Google devuelve 400: «enum[2]: cannot be empty»— y quitar el vacío de la lista
            // dejaría al modelo obligado a elegir uno de los dos: en un documento donde el sexo
            // no se lee, eso es **obligarle a adivinar**. Se acepta texto libre y se valida abajo.
            'sexo' => ['type' => 'string'],
            'nacimiento' => ['type' => 'string'],
            'vencimiento' => ['type' => 'string'],
            'mrzLinea1' => ['type' => 'string'],
            'mrzLinea2' => ['type' => 'string'],
            // 🔥 **Se pregunta DÓNDE está la cabecera, no cuántos grados hay que girar.** La
            // pregunta anterior —«cuántos grados en sentido horario»— obliga al modelo a razonar
            // sobre una convención de giro, y ahí falla: **dos escaneos en la misma posición
            // salían como 90 y 270**. Dónde cae un borde es una pregunta posicional, que es lo
            // que un modelo de visión sí resuelve. Los grados los calcula {@see self::rotacion()}.
            'bordeSuperior' => ['type' => 'string', 'enum' => ['arriba', 'derecha', 'abajo', 'izquierda']],
        ],
        // Todos requeridos y vacíos cuando no se lean: un campo AUSENTE y un campo VACÍO se
        // distinguen mal al leer el JSON, y la diferencia no aporta nada aquí.
        'required' => ['tipo', 'numero', 'nombres', 'apellidos', 'paisEmisor', 'nacionalidad', 'sexo', 'nacimiento', 'vencimiento', 'mrzLinea1', 'mrzLinea2', 'bordeSuperior'],
    ];

    public function __construct(private LectorDeImagenInterface $lector) {}

    /**
     * Llama al proveedor. **Es lo único caro de esta clase**, y por eso está separado.
     *
     * @return array<string, mixed> La lectura cruda, tal cual, para guardarla.
     */
    public function extraer(string $bytes, string $mime): array
    {
        return $this->lector->leer($bytes, $mime, self::INSTRUCCION, self::ESQUEMA);
    }

    /** Atajo para quien no necesita cachear: extraer e interpretar de una vez. */
    public function leer(string $bytes, string $mime): DatosDeDocumento
    {
        return $this->interpretar($this->extraer($bytes, $mime));
    }

    /**
     * Convierte la lectura cruda en datos con criterio: comprueba la MRZ, decide quién manda y
     * levanta los avisos.
     *
     * 🔑 **Separado de {@see self::extraer()} porque no cuesta nada y el documento no cambia.** La
     * lectura se guarda una vez (`CotizacionFilearchivo::$datosLeidos`) y esto se vuelve a correr
     * cada vez que hace falta: gratis, sin red, y **con el criterio de hoy**. Si mañana se afina
     * una regla, los 400 documentos ya leídos se reinterpretan sin pagar una sola llamada.
     *
     * @param array<string, mixed> $crudo
     */
    public function interpretar(array $crudo): DatosDeDocumento
    {

        $mrz = Mrz::desde($this->texto($crudo, 'mrzLinea1'), $this->texto($crudo, 'mrzLinea2'));
        $avisos = [];

        foreach ($mrz !== null ? $mrz->problemas : [] as $problema) {
            $avisos[] = sprintf('la MRZ no cuadra en %s: revísalo a mano', $problema);
        }

        // Lo impreso, que es la segunda lectura independiente.
        $numeroImpreso = $this->texto($crudo, 'numero');
        $nacimientoImpreso = $this->fecha($crudo, 'nacimiento');
        $vencimientoImpreso = $this->fecha($crudo, 'vencimiento');

        // ⚠️ La MRZ manda **sólo si cuadra**. Una MRZ con los dígitos rotos es una transcripción
        // mala, y preferirla a lo impreso sería elegir el dato del que ya sabemos que falla.
        $fiable = $mrz?->esCoherente() === true;

        if ($fiable) {
            foreach ([
                'el número' => [$numeroImpreso, $mrz->numero],
                'la fecha de nacimiento' => [$nacimientoImpreso?->format('Y-m-d'), $mrz->nacimiento?->format('Y-m-d')],
                'la fecha de vencimiento' => [$vencimientoImpreso?->format('Y-m-d'), $mrz->vencimiento?->format('Y-m-d')],
            ] as $que => [$impreso, $deLaMrz]) {
                if ($impreso !== null && $impreso !== '' && $deLaMrz !== null && $this->distinto($impreso, $deLaMrz)) {
                    $avisos[] = sprintf('%s impreso (%s) no coincide con la MRZ (%s)', $que, $impreso, $deLaMrz);
                }
            }
        }

        $vencimiento = $fiable ? ($mrz->vencimiento ?? $vencimientoImpreso) : $vencimientoImpreso;
        if ($vencimiento !== null && $vencimiento < new DateTimeImmutable('today')) {
            $avisos[] = sprintf('está VENCIDO desde el %s', $vencimiento->format('d/m/Y'));
        }

        return new DatosDeDocumento(
            tipo: $this->tipo($crudo, $mrz),
            numero: $fiable ? $mrz->numero : ($numeroImpreso !== '' ? $numeroImpreso : null),
            nombres: $this->preferir($this->texto($crudo, 'nombres'), $fiable ? $mrz->nombres : null),
            apellidos: $this->preferir($this->texto($crudo, 'apellidos'), $fiable ? $mrz->apellidos : null),
            paisEmisor: $this->pais($this->texto($crudo, 'paisEmisor'), $fiable ? $mrz->paisEmisor : null),
            nacionalidad: $nacionalidad = $this->pais($this->texto($crudo, 'nacionalidad'), $fiable ? $mrz->nacionalidad : null),
            sexo: $fiable && $mrz->sexo !== null ? $mrz->sexo : $this->sexo($crudo),
            nacimiento: $fiable ? ($mrz->nacimiento ?? $nacimientoImpreso) : $nacimientoImpreso,
            vencimiento: $vencimiento,
            nacionalidadIso2: $this->aIso2($nacionalidad),
            rotacion: $this->rotacion($crudo),
            mrz: $mrz,
            avisos: $avisos,
        );
    }

    /**
     * Una MRZ que empieza por `P` ES un pasaporte, lo diga el modelo o no: está en el formato,
     * no en la apariencia.
     */
    /** @param array<string, mixed> $crudo */
    private function tipo(array $crudo, ?Mrz $mrz): ?DocumentoTipoEnum
    {
        if ($mrz !== null) {
            return DocumentoTipoEnum::PASAPORTE;
        }

        return DocumentoTipoEnum::tryFrom(strtoupper($this->texto($crudo, 'tipo')));
    }

    /** Compara ignorando lo que sólo es forma de escribir: espacios, guiones y caja. */
    private function distinto(string $a, string $b): bool
    {
        $limpiar = static fn (string $v): string => strtoupper(preg_replace('/[^A-Z0-9]/i', '', $v) ?? '');

        return $limpiar($a) !== $limpiar($b);
    }

    /** Lo impreso lee mejor los nombres (la MRZ recorta a 39 y quita tildes); la MRZ es la red. */
    private function preferir(string $impreso, ?string $deLaMrz): ?string
    {
        return trim($impreso) !== '' ? trim($impreso) : ($deLaMrz !== null && $deLaMrz !== '' ? $deLaMrz : null);
    }

    /**
     * De «dónde cae la cabecera» a «cuántos grados hay que girar en sentido horario».
     *
     * 🔥 **Esta conversión estaba en el modelo y por eso fallaba.** Se le preguntaba directamente
     * por los grados y **dos escaneos en la misma posición contestaban 90 y 270**. Girar es una
     * convención con dos sentidos posibles; dónde cae un borde es un hecho que se ve. Se le
     * pregunta el hecho y la convención se aplica aquí, donde es una tabla de cuatro filas que no
     * cambia de opinión.
     *
     * Girar la imagen en sentido horario lleva `arriba → derecha → abajo → izquierda → arriba`.
     * Así que si la cabecera está a la **derecha**, hace falta el giro que la lleve de vuelta
     * arriba: 270°, no 90°.
     *
     * @param array<string, mixed> $crudo
     */
    private function rotacion(array $crudo): int
    {
        return match (strtolower($this->texto($crudo, 'bordeSuperior'))) {
            'izquierda' => 90,
            'abajo' => 180,
            'derecha' => 270,
            default => 0,   // «arriba», y también lo que no se reconozca: ante la duda, no girar
        };
    }

    /**
     * ISO-3 del documento → ISO-2, que es la **clave** de `MaestroPais` (`PE`, `US`).
     *
     * ⚠️ **Los dos lados hablan códigos distintos y ninguno lo dice.** El pasaporte y la MRZ dan
     * tres letras; `maestro_pais` tiene el ISO-2 como id. Comparar `PER` con `PE` no falla:
     * **siempre difiere**, así que sin este puente cada documento sacaría una discrepancia de
     * nacionalidad falsa y la cola de trabajo se volvería inservible.
     *
     * `null` cuando el código no existe en ISO (un `UTO` de ejemplo, o una lectura torcida). No se
     * inventa nada: quien llama lo tratará como «no se pudo comprobar», no como «no coincide».
     */
    private function aIso2(?string $iso3): ?string
    {
        if ($iso3 === null || !Countries::alpha3CodeExists($iso3)) {
            return null;
        }

        return Countries::getAlpha2Code($iso3);
    }

    /** ISO de tres letras, o nada: un país a medias no casa con `MaestroPais` y confunde. */
    private function pais(string $impreso, ?string $deLaMrz): ?string
    {
        foreach ([$deLaMrz, $impreso] as $candidato) {
            $iso = strtoupper(trim((string) $candidato));
            if (preg_match('/^[A-Z]{3}$/', $iso) === 1) {
                return $iso;
            }
        }

        return null;
    }

    /**
     * M o F, o nada. Lo que venga en texto libre —«Masculino», «MALE», «V»— se queda en la
     * primera letra sólo si es una de las dos; cualquier otra cosa es no haberlo leído.
     *
     * @param array<string, mixed> $crudo
     */
    private function sexo(array $crudo): ?string
    {
        $inicial = strtoupper(substr(trim($this->texto($crudo, 'sexo')), 0, 1));

        return in_array($inicial, ['M', 'F'], true) ? $inicial : null;
    }

    /** @param array<string, mixed> $crudo */
    private function texto(array $crudo, string $clave): string
    {
        return is_string($crudo[$clave] ?? null) ? trim($crudo[$clave]) : '';
    }

    /** @param array<string, mixed> $crudo */
    private function fecha(array $crudo, string $clave): ?DateTimeImmutable
    {
        $valor = $this->texto($crudo, $clave);
        if ($valor === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $fecha instanceof DateTimeImmutable ? $fecha : null;
    }
}
