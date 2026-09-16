<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

/**
 * ¿El nombre de un trámite es el mismo que el del documento de viaje, o hay un dedazo?
 *
 * ── Por qué no vale la comparación que ya había ─────────────────────────────
 * {@see Cotejo::mismaPersona()} pregunta «¿comparten alguna palabra?», que es lo correcto para
 * decidir **si dos fichas son de la misma persona**. Aquí la pregunta es otra: el trámite ya se sabe
 * de quién es —lo ancla el pasaporte—, y lo que hay que cazar es que lo escribieran mal. Con
 * «comparten alguna», `JOAQUIN ASCARSA` y `JOAQUIN ASCARZA` pasan sin más: comparten `JOAQUIN`.
 *
 * Y son casos reales: hubo que avisar a mano de un `Ascarsa` por `Ascarza` y un `juaquin` por
 * `joaquin`. En el mostrador de Migración un nombre que no es el del pasaporte es un problema.
 *
 * ── La regla, y cómo evita acusar en falso ──────────────────────────────────
 * ```
 *   palabra del trámite que NO está en la referencia
 *     ├── se parece a una de la referencia (≤ 2 cambios)  →  DEDAZO, se acusa
 *     └── no se parece a ninguna                          →  nota, no se acusa
 *   palabra de la referencia que falta en el trámite      →  nota, no se acusa
 * ```
 *
 * 🔑 **Sólo se acusa del parecido, y ésa es toda la precisión.** Un nombre de más o de menos tiene
 * mil explicaciones inocentes —el formulario corta, el segundo nombre no se usa, el apellido de
 * casada— y acusar de eso llenaría la hoja de ruido hasta que nadie la mirase. Pero una palabra que
 * se parece **y no es igual** es casi siempre un dedazo: `ASCARSA` está a un cambio de `ASCARZA` y a
 * doce de cualquier otra cosa.
 *
 * ⚠️ **Las tildes y la eñe ya no llegan aquí**: {@see PalabrasDelNombre} translitera. `ACUNA` y
 * `ACUÑA` son la misma palabra, que es lo correcto —el formulario dominicano no admite la eñe— y sin
 * eso medio grupo peruano saldría acusado.
 *
 * ⚠️ **Umbral 2 y no más.** A 3 empiezan a parecerse nombres que no tienen nada que ver (`MARIA` y
 * `MARIO` están a 1, pero `ROSA` y `ROJAS` a 2): pasar de ahí convierte la detección de dedazos en
 * generación de dudas. `levenshtein()` opera sobre bytes, y aquí llega ya transliterado a A-Z.
 */
final readonly class CotejoDeNombre
{
    private const DISTANCIA_MAXIMA = 2;

    /**
     * @param list<array{0: string, 1: string}> $dedazos  palabra del trámite → la que debería ser
     * @param list<string>                      $sobran   en el trámite y no en la referencia
     * @param list<string>                      $faltan   en la referencia y no en el trámite
     */
    private function __construct(
        public array $dedazos,
        public array $sobran,
        public array $faltan,
    ) {}

    public static function de(?string $delTramite, ?string $referencia): self
    {
        if ($delTramite === null || $referencia === null) {
            return new self([], [], []);
        }

        $suyas = PalabrasDelNombre::de($delTramite);
        $buenas = PalabrasDelNombre::de($referencia);

        if ($suyas === [] || $buenas === []) {
            return new self([], [], []);
        }

        $dedazos = [];
        $sobran = [];

        foreach (array_diff($suyas, $buenas) as $palabra) {
            $parecida = self::masParecida($palabra, array_values(array_diff($buenas, $suyas)));

            if ($parecida !== null) {
                $dedazos[] = [$palabra, $parecida];
                continue;
            }

            $sobran[] = $palabra;
        }

        // Las que ya se emparejaron como dedazo no se cuentan también como ausentes.
        $emparejadas = array_map(static fn (array $par): string => $par[1], $dedazos);
        $faltan = array_values(array_diff($buenas, $suyas, $emparejadas));

        return new self($dedazos, $sobran, $faltan);
    }

    /** ¿Hay algo que mandar a corregir con seguridad? */
    public function hayDedazo(): bool
    {
        return $this->dedazos !== [];
    }

    /** «ASCARSA (debería ser ASCARZA)», ya compuesto. */
    public function comoSeLee(): string
    {
        return implode(', ', array_map(
            static fn (array $par): string => sprintf('%s (debería ser %s)', $par[0], $par[1]),
            $this->dedazos,
        ));
    }

    /**
     * Cuántos cambios se toleran antes de llamarlo dedazo, según lo larga que sea la palabra.
     *
     * ⚠️ **Una tolerancia fija acusa a los nombres cortos.** `ANA` y `ANO` están a un cambio y no son
     * la misma persona; `JUAQUIN` y `JOAQUIN` también están a uno y sí lo son. Lo que separa los dos
     * casos es la longitud: sobre tres letras, un cambio es un tercio de la palabra; sobre siete, un
     * dedo que resbaló.
     *
     * Lo cazó su propio test, que es para lo que está: la primera versión dividía la longitud entre
     * tres y emparejaba `ANA` con `ANO`.
     */
    private static function toleranciaPara(int $longitud): int
    {
        return match (true) {
            $longitud < 4 => 0,                        // exactas o nada
            $longitud < 7 => 1,
            default => self::DISTANCIA_MAXIMA,
        };
    }

    /**
     * @param list<string> $candidatas
     */
    private static function masParecida(string $palabra, array $candidatas): ?string
    {
        $mejor = null;
        $mejorDistancia = self::DISTANCIA_MAXIMA + 1;

        foreach ($candidatas as $candidata) {
            $tope = self::toleranciaPara(min(strlen($palabra), strlen($candidata)));
            $distancia = levenshtein($palabra, $candidata);

            if ($distancia <= $tope && $distancia < $mejorDistancia) {
                $mejor = $candidata;
                $mejorDistancia = $distancia;
            }
        }

        return $mejor;
    }
}
