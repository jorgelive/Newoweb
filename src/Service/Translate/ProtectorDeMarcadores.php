<?php

declare(strict_types=1);

namespace App\Service\Translate;

/**
 * Pone a salvo los `{{ marcadores }}` mientras el texto pasa por el traductor.
 *
 * ── El problema, medido ─────────────────────────────────────────────────────
 * Google Translate NO deja quieto lo que hay dentro de las llaves: lo TRADUCE.
 * Comprobado contra la API real del proyecto, español → inglés:
 *
 *     «<p>Puedes pagar aqui:</p><p>{{ medios_pago }}</p>»
 *       → «<p>You can pay here:</p><p>{{ payment_methods }}</p>»
 *
 * Y ése es el peor fallo posible aquí, porque **no se pierde el marcador: le cambia el
 * nombre**. El interpolador busca `medios_pago`, no encuentra nada, y el huésped acaba viendo
 * `{{ payment_methods }}` en crudo en su guía. Nada revienta; simplemente sale mal, y sólo en
 * los idiomas que nadie relee.
 *
 * ── Por qué no basta el `translate="no"` de Google ──────────────────────────
 * Envolver el marcador en `<span translate="no">` funciona —también comprobado— pero sólo en
 * los campos que viajan como `text/html`, y el atributo `AutoTranslate` usa `text` por defecto:
 * media docena de campos de cotizaciones son texto plano. Además obligaría a que cada persona
 * que escriba un marcador se acuerde del envoltorio, y olvidarlo no falla en voz alta: aparece
 * traducido semanas después.
 *
 * Enmascarar aquí cubre los dos formatos y no depende de que nadie se acuerde de nada.
 *
 * ── El centinela NO puede parecer una palabra ───────────────────────────────
 * Se probaron cinco candidatos contra los seis idiomas de destino y los que parecen palabras
 * inglesas se rompen: Google los traduce o los «corrige». El detalle y la tabla, en
 * {@see self::CENTINELA_INICIO}.
 *
 * ⚠️ El traductor PUEDE reordenarlos —al cambiar el orden de las palabras— y eso está bien: se
 * restaura por índice, no por posición. Lo que no puede es perderlos, y de eso se ocupa
 * {@see self::estaIntacto()}.
 */
final class ProtectorDeMarcadores
{
    /**
     * Los marcadores del proyecto: `{{ clave }}`, con o sin espacios.
     *
     * Sólo la sintaxis de llave DOBLE. La de llave simple (`{codigo_puerta}`) no la resuelve
     * ningún interpolador vivo —quedó en algún texto de ayuda del panel— y protegerla sería
     * dar por bueno algo que no funciona.
     */
    private const string PATRON = '/\{\{\s*[\w.]+\s*\}\}/u';

    /**
     * `__PH0__`, `__PH1__`… Ni palabra ni símbolo raro, y esa es toda la gracia.
     *
     * ⚠️ **NO usar una palabra inglesa aquí.** Es lo primero que se le ocurre a cualquiera
     * —«el traductor respetará mejor el inglés»— y es exactamente al revés: si parece una
     * palabra, Google la trata como tal y la traduce o la «corrige». Medido en HTML contra la
     * API real, sobre los seis idiomas de destino:
     *
     * | Centinela      | en | pt  | fr  | de  | it  | nl  |
     * |----------------|----|-----|-----|-----|-----|-----|
     * | `NOTRANSLATE0` | ok | MAL | MAL | ok  | ok  | MAL |
     * | `PLACEHOLDER0` | ok | ok  | ok  | MAL | MAL | MAL |
     * | `__PH0__`      | ok | ok  | ok  | ok  | ok  | ok  |
     * | `⟦0⟧`          | ok | ok  | ok  | ok  | ok  | ok  |
     * | `<ph0/>`       | ok | ok  | ok  | ok  | ok  | ok  |
     *
     * El neerlandés devolvió `NOTTRASLATE0` —le comió una letra, como quien arregla una
     * errata—. Entre los tres que aguantan se elige éste por diagnóstico: si algún día se
     * escapa a una guía, `__PH0__` se entiende de un vistazo, `⟦0⟧` parece basura y `<ph0/>`
     * puede escaparse como HTML en un campo de texto plano.
     */
    private const string CENTINELA_INICIO = '__PH';

    private const string CENTINELA_FIN = '__';

    /**
     * Cambia cada marcador por su centinela.
     *
     * @return array{0: string, 1: list<string>} El texto enmascarado y los marcadores en orden
     */
    public function enmascarar(string $texto): array
    {
        $encontrados = [];

        $enmascarado = preg_replace_callback(
            self::PATRON,
            static function (array $m) use (&$encontrados): string {
                $i = count($encontrados);
                $encontrados[] = $m[0];

                return self::centinela($i);
            },
            $texto
        );

        return [$enmascarado ?? $texto, $encontrados];
    }

    /**
     * Devuelve cada marcador a su sitio.
     *
     * El sufijo `__` cierra el número, así que `__PH1__` no puede confundirse con `__PH11__`.
     * Aun así se restaura del último al primero: es gratis y no depende de esa forma.
     *
     * @param list<string> $marcadores
     */
    public function restaurar(string $traducido, array $marcadores): string
    {
        for ($i = count($marcadores) - 1; $i >= 0; --$i) {
            $traducido = str_replace(self::centinela($i), $marcadores[$i], $traducido);
        }

        return $traducido;
    }

    /**
     * ¿Volvieron TODOS los centinelas de la traducción?
     *
     * Se comprueba antes de dar por buena la traducción. Si el traductor se comió uno, guardar
     * ese texto significa publicar una guía a la que le falta —por ejemplo— el widget de los
     * medios de pago, sin que nadie se entere hasta que un huésped pregunte cómo pagar.
     * Preferimos quedarnos con la traducción anterior, o sin ella.
     *
     * @param list<string> $marcadores
     */
    public function estaIntacto(string $traducido, array $marcadores): bool
    {
        foreach (array_keys($marcadores) as $i) {
            if (!str_contains($traducido, self::centinela($i))) {
                return false;
            }
        }

        return true;
    }

    private static function centinela(int $i): string
    {
        return self::CENTINELA_INICIO . $i . self::CENTINELA_FIN;
    }
}
