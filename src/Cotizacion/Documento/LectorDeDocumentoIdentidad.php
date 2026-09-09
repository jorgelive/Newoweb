<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Agent\Vision\LectorDeImagenInterface;
use App\Enum\DocumentoTipoEnum;
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
            'sexo' => ['type' => 'string', 'enum' => ['M', 'F', '']],
            'nacimiento' => ['type' => 'string'],
            'vencimiento' => ['type' => 'string'],
            'mrzLinea1' => ['type' => 'string'],
            'mrzLinea2' => ['type' => 'string'],
        ],
        // Todos requeridos y vacíos cuando no se lean: un campo AUSENTE y un campo VACÍO se
        // distinguen mal al leer el JSON, y la diferencia no aporta nada aquí.
        'required' => ['tipo', 'numero', 'nombres', 'apellidos', 'paisEmisor', 'nacionalidad', 'sexo', 'nacimiento', 'vencimiento', 'mrzLinea1', 'mrzLinea2'],
    ];

    public function __construct(private LectorDeImagenInterface $lector) {}

    public function leer(string $bytes, string $mime): DatosDeDocumento
    {
        $crudo = $this->lector->leer($bytes, $mime, self::INSTRUCCION, self::ESQUEMA);

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
            nacionalidad: $this->pais($this->texto($crudo, 'nacionalidad'), $fiable ? $mrz->nacionalidad : null),
            sexo: $fiable && $mrz->sexo !== null ? $mrz->sexo : ($this->texto($crudo, 'sexo') ?: null),
            nacimiento: $fiable ? ($mrz->nacimiento ?? $nacimientoImpreso) : $nacimientoImpreso,
            vencimiento: $vencimiento,
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
