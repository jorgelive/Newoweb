<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Agent\Vision\LectorDeImagenInterface;
use DateTimeImmutable;

/**
 * Lee un trámite migratorio —hoy el **E-Ticket** de República Dominicana— y devuelve sus campos.
 *
 * ── Calcado de {@see LectorDeDocumentoIdentidad}, y ése es el punto ─────────
 * Mismo reparto en dos: {@see self::extraer()} cuesta dinero y se guarda una vez en
 * `CotizacionFilearchivo::$datosLeidos`; {@see self::interpretar()} es gratis y se vuelve a correr
 * **con el criterio de hoy** cada vez que haga falta. Afinar una regla mañana no obliga a releer
 * los 87 documentos ya subidos ni a pagar una sola llamada más.
 *
 * ⚠️ **El almacén no hubo que tocarlo.** `datosLeidos` es `array<string, mixed>` y `registrarLectura()`
 * no sabe qué es un pasaporte: el patrón ya era genérico, sólo no se había usado para otra cosa.
 *
 * ── PDF y foto entran por la misma puerta ───────────────────────────────────
 * {@see LectorDeImagenInterface::leer()} acepta `application/pdf` igual que `image/jpeg`, así que
 * no hay dos caminos que mantener. Medido sobre los 87 subidos: **81 traen capa de texto y 6 son
 * capturas de pantalla**; los dos casos se mandan igual y el modelo devuelve la misma forma.
 *
 * ⚠️ Extraer el texto del PDF a mano sería más barato para esos 81, pero **no es gratis**: el texto
 * va en hexadecimal con la fuente embebida, así que haría falta una librería más, un segundo camino
 * que probar y una forma nueva de fallar para el 7 % restante. Si algún día el gasto lo justifica,
 * el sitio es aquí y el resto no se entera.
 *
 * ── Lo que NO se le pregunta al modelo ──────────────────────────────────────
 * 🔑 No se le pregunta si el trámite es correcto. Se le piden los campos **tal como están escritos**
 * y quien juzga es {@see CotejoDeEticket}, que es código y se puede probar. Un modelo al que se le
 * pide un veredicto contesta con la misma seguridad cuando acierta y cuando se lo inventa.
 */
final readonly class LectorDeEticket
{
    private const INSTRUCCION = <<<'TXT'
        Esto es un E-Ticket de migración de República Dominicana: el formulario electrónico de
        entrada y/o salida que exige la Dirección General de Migración, con un código QR.

        NO es un billete de avión. Si lo que ves es una tarjeta de embarque o un boleto de una
        aerolínea, devuelve todos los campos vacíos y `esEticket` en false.

        Transcribe lo que está ESCRITO, carácter a carácter. No corrijas, no completes y no
        deduzcas: si un dato no se lee o no aparece, déjalo vacío. Un campo vacío es una respuesta
        útil; un campo adivinado no se distingue de uno leído y nadie lo vuelve a mirar.

        El formulario lleva una TABLA DE PASAJEROS con una fila por persona, y muy a menudo hay
        MÁS DE UNA: una familia rellena un solo trámite para todos. Devuelve en `pasajeros` una
        entrada por cada fila de esa tabla, en el orden en que salen, aunque sólo haya una. No
        resumas, no juntes dos filas y no te quedes con la primera.

        El documento puede traer una sección de ENTRADA, una de SALIDA, o las dos. Es muy frecuente
        que traiga sólo una. Contesta `traeEntrada` y `traeSalida` según qué secciones EXISTEN en el
        documento, aunque no consigas leer sus datos: «la sección no está» y «la sección está pero
        no la puedo leer» son cosas distintas.

        Las fechas siempre en formato AAAA-MM-DD.

        El número de vuelo es el de la aerolínea, como «CM177», «DM6771» o «H2 5002». Si aparece con
        espacios, transcríbelo con espacios.
        TXT;

    /** @var array<string, mixed> */
    private const ESQUEMA = [
        'type' => 'object',
        'properties' => [
            'esEticket' => ['type' => 'boolean'],
            'codigo' => ['type' => 'string'],
            // Una fila por persona. Ver `PasajeroDelTramite`: el trámite es de VARIOS.
            'pasajeros' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'nombre' => ['type' => 'string'],
                        'pasaporte' => ['type' => 'string'],
                        'nacionalidad' => ['type' => 'string'],
                    ],
                    'required' => ['nombre', 'pasaporte', 'nacionalidad'],
                ],
            ],
            // Dos preguntas separadas por sección: si existe, y qué dice. Ver `DatosDeEticket`.
            'traeEntrada' => ['type' => 'boolean'],
            'fechaEntrada' => ['type' => 'string'],
            'vueloEntrada' => ['type' => 'string'],
            'traeSalida' => ['type' => 'boolean'],
            'fechaSalida' => ['type' => 'string'],
            'vueloSalida' => ['type' => 'string'],
        ],
        // Todos requeridos y vacíos cuando no se lean: un campo AUSENTE y uno VACÍO se distinguen
        // mal al leer el JSON, y la diferencia no aporta nada aquí. Misma decisión que en identidad.
        'required' => [
            'esEticket', 'codigo', 'pasajeros',
            'traeEntrada', 'fechaEntrada', 'vueloEntrada',
            'traeSalida', 'fechaSalida', 'vueloSalida',
        ],
        // ⚠️ **Sin `additionalProperties`**: el dialecto de esquema de Google AI no lo conoce y
        // devuelve `400 Unknown name "additionalProperties"` — la llamada entera, no el campo.
        // El lector de identidad tampoco lo lleva, y no por olvido.
    ];

    public function __construct(private LectorDeImagenInterface $lector) {}

    /**
     * La llamada que cuesta dinero. Lo que devuelve es lo que se guarda tal cual.
     *
     * @return array<string, mixed>
     */
    public function extraer(string $bytes, string $mime): array
    {
        return $this->lector->leer($bytes, $mime, self::INSTRUCCION, self::ESQUEMA);
    }

    /** Atajo para quien no necesita cachear: extraer e interpretar de una vez. */
    public function leer(string $bytes, string $mime): DatosDeEticket
    {
        return $this->interpretar($this->extraer($bytes, $mime));
    }

    /**
     * Convierte la lectura cruda en datos con criterio. Gratis, sin red, repetible.
     *
     * @param array<string, mixed> $crudo
     */
    public function interpretar(array $crudo): DatosDeEticket
    {
        $avisos = [];

        // ⚠️ Lo primero, porque cambia el significado de todo lo demás. Medio grupo sube su billete
        // de avión creyendo que es esto —«e-ticket» significa eso para cualquiera— y un billete
        // tiene número de vuelo y fecha: sin esta pregunta, cotejaría razonablemente bien y daría
        // por hecho un trámite que nadie hizo.
        if (($crudo['esEticket'] ?? null) === false) {
            return new DatosDeEticket(
                avisos: ['esto no parece un E-Ticket migratorio: puede ser un billete de avión o una tarjeta de embarque'],
                noEsElTramite: true,
            );
        }

        $traeEntrada = ($crudo['traeEntrada'] ?? null) === true;
        $traeSalida = ($crudo['traeSalida'] ?? null) === true;

        // 🔥 El fallo más común del trámite, y se dice aquí para que lo diga igual quien lo lea por
        // la pantalla, por la hoja o por el comando.
        if ($traeEntrada && !$traeSalida) {
            $avisos[] = 'sólo trae la ENTRADA: falta rellenar la salida';
        }

        if ($traeSalida && !$traeEntrada) {
            $avisos[] = 'sólo trae la SALIDA: falta rellenar la entrada';
        }

        $fechaEntrada = $this->fecha($crudo, 'fechaEntrada');
        $fechaSalida = $this->fecha($crudo, 'fechaSalida');

        // Una sección que existe y no se pudo leer NO es una sección que falta: se dice distinto,
        // porque lo que hay que hacer es distinto —mirar el escaneo, no rehacer el trámite—.
        if ($traeEntrada && $fechaEntrada === null) {
            $avisos[] = 'la sección de entrada está pero no se pudo leer su fecha';
        }

        if ($traeSalida && $fechaSalida === null) {
            $avisos[] = 'la sección de salida está pero no se pudo leer su fecha';
        }

        if ($fechaEntrada !== null && $fechaSalida !== null && $fechaSalida < $fechaEntrada) {
            $avisos[] = 'la salida es anterior a la entrada';
        }

        return new DatosDeEticket(
            codigo: $this->opcional($crudo, 'codigo'),
            pasajeros: $this->pasajeros($crudo),
            fechaEntrada: $fechaEntrada,
            vueloEntrada: $this->opcional($crudo, 'vueloEntrada'),
            fechaSalida: $fechaSalida,
            vueloSalida: $this->opcional($crudo, 'vueloSalida'),
            traeEntrada: $traeEntrada,
            traeSalida: $traeSalida,
            avisos: $avisos,
        );
    }

    /**
     * La tabla de personas del trámite, **con respaldo para lo ya guardado**.
     *
     * ⚠️ Las 121 lecturas que ya estaban pagadas el 16/09/2026 se guardaron con la forma vieja
     * —`nombres`/`apellidos`/`pasaporte` sueltos—, y {@see self::interpretar()} corre sobre ellas
     * cada vez que se rejuzga. Sin este respaldo, afinar la regla habría dejado sin nombre ni
     * pasaporte a todo el expediente: el almacén guarda lo CRUDO precisamente para que el criterio
     * pueda cambiar sin volver a pagar, y eso sólo se sostiene si lo viejo se sigue sabiendo leer.
     *
     * Una lectura vieja da una lista de uno, que es exactamente como se comportaba antes.
     *
     * @param array<string, mixed> $crudo
     *
     * @return list<PasajeroDelTramite>
     */
    private function pasajeros(array $crudo): array
    {
        $filas = $crudo['pasajeros'] ?? null;

        if (!is_array($filas)) {
            $nombre = trim(($this->opcional($crudo, 'nombres') ?? '').' '.($this->opcional($crudo, 'apellidos') ?? ''));

            $viejo = new PasajeroDelTramite(
                $nombre === '' ? null : $nombre,
                $this->opcional($crudo, 'pasaporte'),
                $this->opcional($crudo, 'nacionalidad'),
            );

            return $viejo->estaVacio() ? [] : [$viejo];
        }

        $pasajeros = [];

        foreach ($filas as $fila) {
            if (!is_array($fila)) {
                continue;
            }

            /** @var array<string, mixed> $fila */
            $uno = new PasajeroDelTramite(
                $this->opcional($fila, 'nombre'),
                $this->opcional($fila, 'pasaporte'),
                $this->opcional($fila, 'nacionalidad'),
            );

            if (!$uno->estaVacio()) {
                $pasajeros[] = $uno;
            }
        }

        return $pasajeros;
    }

    /** @param array<string, mixed> $crudo */
    private function opcional(array $crudo, string $clave): ?string
    {
        $valor = is_string($crudo[$clave] ?? null) ? trim($crudo[$clave]) : '';

        return $valor === '' ? null : $valor;
    }

    /** @param array<string, mixed> $crudo */
    private function fecha(array $crudo, string $clave): ?DateTimeImmutable
    {
        $valor = $this->opcional($crudo, $clave);

        if ($valor === null) {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $fecha instanceof DateTimeImmutable ? $fecha : null;
    }
}
