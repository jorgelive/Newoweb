<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

/**
 * El reparto por casitas tal como lo escribió el modelo: lo que se entendió y lo que no.
 *
 * ### ⚠️ Por qué es una clase y no un array con la forma prometida en un docblock
 *
 * Era un array `{reparto, ilegibles}` y el contrato se rompió por el sitio más tonto: la
 * salida temprana del texto vacío devolvía `[]`, sin ninguna de las dos claves. Leer una
 * clave que no existe es sólo un *warning* que evalúa a `null`, `null !== []` es verdadero,
 * y el llamador entraba en su rama de error hasta que `implode(null)` tiraba TypeError. La
 * skill entera moría en el caso NORMAL —preguntar disponibilidad sin decir reparto— y el
 * agente de WhatsApp se quedaba callado.
 *
 * Ni `php -l`, ni los tests, ni PHPStan lo pescaron: un docblock es una promesa que nadie
 * ejecuta, y las formas de array PHPStan las compara en nivel 3, no en el 2 del proyecto. La
 * clase cierra el hueco por tres lados, comprobados uno a uno:
 *
 * - La forma incompleta es **irrepresentable**: constructor privado, propiedades tipadas —
 *   no hay manera de fabricar un objeto al que le falte una lista.
 * - Un `return [];` contra el tipo nativo es TypeError **en esa línea y a la primera
 *   ejecución** —el test del texto vacío la ejecuta—, no un warning silencioso que evalúa a
 *   `null` tres líneas más abajo, en otro método y con otro error.
 * - Leerle una propiedad que no tiene es `property.notFound` de PHPStan **ya en nivel 2**;
 *   la clave de array equivocada pasaba callada en cualquier nivel bajo.
 */
final readonly class DistribucionLeida
{
    /**
     * @param list<array{0: string, 1: int}> $reparto pares casita → personas ya entendidos
     * @param list<string> $ilegibles los trozos que no encajaron, tal cual llegaron
     */
    private function __construct(
        public array $reparto,
        public array $ilegibles,
    ) {}

    /**
     * «2:7, Casita 5:2» → reparto `[['2', 7], ['5', 2]]`.
     *
     * Tolerante con lo que escriba el modelo —con o sin la palabra «Casita», con espacios,
     * con `=` en vez de `:`— porque el formato exacto es lo primero que se le olvida.
     *
     * ⚠️ Lo que no encaja NO se descarta en silencio: vuelve en `ilegibles` y quien llama
     * corta con un error. El descarte callado convertía «2:7 y 5:2» —sin coma— en un reparto
     * de una sola casita, y «Casita 2: 7 personas» en ninguna, cotizando por un camino
     * distinto sin que nadie lo notara.
     */
    public static function deTexto(string $texto): self
    {
        $reparto = [];
        $ilegibles = [];

        foreach (preg_split('/[,;]+/', trim($texto)) ?: [] as $par) {
            $par = trim($par);

            if ($par === '') {
                continue;
            }

            // El separador es `:` o `=`. El guion NO: «Casitas 2-7: 10» es «de la 2 a la 7»
            // para un humano, y aceptándolo se cotizaba SOLO la 7 con las 10 personas y la
            // cotización salía marcada como completa.
            if (preg_match('/^([\w\s]*?)\s*[:=]\s*(\d{1,3})$/u', $par, $m) !== 1) {
                $ilegibles[] = $par;
                continue;
            }

            $casita = trim(preg_replace('/casitas?/iu', '', $m[1]) ?? '');
            $pax = (int) $m[2];

            if ($casita === '' || $pax < 1) {
                $ilegibles[] = $par;
                continue;
            }

            $reparto[] = [$casita, $pax];
        }

        return new self($reparto, $ilegibles);
    }
}
