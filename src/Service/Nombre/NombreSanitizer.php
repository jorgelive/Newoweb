<?php

declare(strict_types=1);

namespace App\Service\Nombre;

/**
 * Devuelve a su forma normal un nombre que llegó GRITADO.
 *
 * Existe para que la bienvenida no salga como «Hola QUISPE CONTRERAS, bienvenido a Centro Cusco
 * Inti». Es raro —2 de 123 reservas auditadas el 19/08/2026— pero cuando pasa se lee fatal, y es
 * lo primero que ve alguien que acaba de reservar.
 *
 * ### ⚠️ Sólo toca lo que está CLARAMENTE gritado
 *
 * Si el texto tiene una sola minúscula, se devuelve intacto. No es timidez: es lo único que
 * distingue un dato roto de uno bueno.
 *
 * - `Viana Da Silva`, `McDonald`, `O'Brien`, `de la Cruz` los escribió alguien **así a
 *   propósito**, y «arreglarlos» los rompe. Un `Da`→`da` en un apellido brasileño es
 *   exactamente el tipo de corrección que nadie pidió.
 * - `H` —apellido de una letra, como lo manda Airbnb cuando lo trunca— no está gritado: no hay
 *   nada que bajar. Un token de una sola letra se deja como está.
 *
 * ### Lo que NO puede hacer, y hay que saberlo
 *
 * **No restituye tildes.** `JOSÉ` conserva la suya y sale `José`, pero `JOSE` sale `Jose`: la
 * tilde no está en el dato y aquí no se adivina. Eso sí sabría hacerlo un modelo — ver la
 * discusión en `docs/Mensajeria.md`— pero es un refinamiento, no la diferencia entre un mensaje
 * presentable y uno que no lo es.
 */
final readonly class NombreSanitizer
{
    /**
     * Partículas que van en minúscula cuando NO abren el nombre.
     *
     * `y`/`e` son la conjunción de los compuestos españoles; el resto son los enlaces de
     * apellidos romances, holandeses y alemanes. Ninguna se toca en primera posición: quien se
     * apellida sólo `De la Cruz` abre con mayúscula.
     */
    private const array PARTICULAS = [
        'de', 'del', 'la', 'las', 'lo', 'los', 'y', 'e', 'da', 'das', 'do', 'dos',
        'di', 'du', 'le', 'van', 'von', 'der', 'den', 'ter', 'bin', 'al', 'i',
    ];

    /** Separadores que también abren palabra: `Jean-Pierre`, `O'Brien`, `D'Angelo`. */
    private const string SEPARADORES = "-'’";

    public function formatear(?string $texto): ?string
    {
        $limpio = trim((string) $texto);

        if ($limpio === '' || !$this->estaGritado($limpio)) {
            return $texto;
        }

        $palabras = preg_split('/\s+/u', $limpio) ?: [];
        $salida = [];

        foreach ($palabras as $i => $palabra) {
            $minuscula = mb_strtolower($palabra, 'UTF-8');

            // Una partícula que no abre el nombre se queda abajo. La primera palabra nunca:
            // `DE LA CRUZ` es `De la Cruz`, no `de la Cruz`.
            if ($i > 0 && in_array($minuscula, self::PARTICULAS, true)) {
                $salida[] = $minuscula;
                continue;
            }

            $salida[] = $this->conMayusculaTrasCadaSeparador($minuscula);
        }

        return implode(' ', $salida);
    }

    /**
     * ¿Está en mayúsculas de principio a fin?
     *
     * Se compara contra la versión en mayúsculas **y** se exige que tenga alguna letra: así un
     * `12` o un `---` no se consideran gritados, y una sola minúscula en cualquier parte basta
     * para dejarlo todo como estaba.
     *
     * Un texto de una sola letra (`H`) tampoco cuenta: no hay nada que normalizar en él.
     */
    private function estaGritado(string $texto): bool
    {
        if (mb_strlen($texto, 'UTF-8') < 2) {
            return false;
        }

        if (preg_match('/\p{L}/u', $texto) !== 1) {
            return false;
        }

        return $texto === mb_strtoupper($texto, 'UTF-8');
    }

    /** `o'brien` → `O'Brien`, `jean-pierre` → `Jean-Pierre`, `quispe` → `Quispe`. */
    private function conMayusculaTrasCadaSeparador(string $palabra): string
    {
        $salida = '';
        $abrePalabra = true;

        foreach (preg_split('//u', $palabra, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $letra) {
            $salida .= $abrePalabra ? mb_strtoupper($letra, 'UTF-8') : $letra;
            $abrePalabra = mb_strpos(self::SEPARADORES, $letra) !== false;
        }

        return $salida;
    }
}
