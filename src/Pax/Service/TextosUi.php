<?php

declare(strict_types=1);

namespace App\Pax\Service;

use App\Pax\Entity\UiI18n;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lee las cadenas de `pax_ui_i18n` **desde PHP**.
 *
 * ── Por qué no existía hasta el 30/08/2026 ──────────────────────────────────
 * Estas cadenas nacieron para `pax`, y ahí el reparto es otro: el provider se las lleva todas
 * de una vez y **el front resuelve** con el idioma que tiene el selector delante. Nadie las
 * había leído nunca desde el servidor porque nadie había tenido que redactar nada allí.
 *
 * El mensaje de pago sí: se compone al enviar, en PHP, y sus rótulos —los medios, el recargo,
 * el saldo— son exactamente estas claves, ya traducidas a los siete idiomas. Sin este lector la
 * única salida era `FinMedioCobroTipo::label()`, que está en español y sólo en español: saldría
 * la prosa de la plantilla en inglés y el bloque de importes en castellano, en el mismo mensaje.
 *
 * ── Qué NO hace ─────────────────────────────────────────────────────────────
 * No traduce. Sólo elige la traducción que ya escribió `AutoTranslate` al guardarse la cadena.
 * Traducir al vuelo lo que va a leer un huésped es exactamente lo que dejó un giro de Western
 * Union sin poder cobrar — ver §22.24 de `docs/Mensajeria.md`.
 */
final class TextosUi
{
    /**
     * Todas las cadenas, indexadas por clave e idioma. Se cargan **una vez** y enteras: son
     * ~210 filas en total y un mensaje toca quince claves — quince consultas para eso sería el
     * N+1 que el resto del módulo evita con tanto cuidado.
     *
     * @var array<string, array<string, string>>|null
     */
    private ?array $mapa = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * La cadena en el idioma pedido, con sus `{{ marcadores }}` sustituidos.
     *
     * ⚠️ **Cae al español, no al inglés**, al revés que `MessageTemplate::extract()`. No es una
     * incoherencia: son dos orígenes distintos. Una plantilla la escribe una persona y puede
     * nacer en inglés; estas cadenas llevan `#[AutoTranslate(sourceLanguage: 'es')]`, así que el
     * español es el ÚNICO idioma que siempre está. Caer al inglés aquí es caer a algo que puede
     * no existir.
     *
     * ⚠️ Sin la clave devuelve **la clave**, no cadena vacía. Un `res_medio_yape` en mitad de un
     * WhatsApp es feo y se arregla el mismo día; un hueco en la lista de medios no lo nota nadie
     * y el huésped se queda sin saber que podía pagar por ahí.
     *
     * @param array<string, string|int|float> $marcadores Ej. `['pct' => '5.5']` para `{{ pct }}`.
     */
    public function texto(string $clave, string $idioma, array $marcadores = []): string
    {
        $porIdioma = $this->mapa()[$clave] ?? null;

        if ($porIdioma === null) {
            return $clave;
        }

        $texto = $porIdioma[$idioma] ?? $porIdioma['es'] ?? $clave;

        foreach ($marcadores as $nombre => $valor) {
            // Con y sin espacios: las cadenas del catálogo están escritas «{{ pct }}» y nadie
            // garantiza que la siguiente se escriba igual desde el panel.
            $texto = str_replace(['{{ ' . $nombre . ' }}', '{{' . $nombre . '}}'], (string) $valor, $texto);
        }

        return $texto;
    }

    /** ¿Existe esta clave? Para quien prefiera callar antes que enseñar un rótulo crudo. */
    public function existe(string $clave): bool
    {
        return isset($this->mapa()[$clave]);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function mapa(): array
    {
        if ($this->mapa !== null) {
            return $this->mapa;
        }

        $this->mapa = [];

        /** @var list<UiI18n> $filas */
        $filas = $this->em->getRepository(UiI18n::class)->findAll();

        foreach ($filas as $fila) {
            $porIdioma = [];

            foreach ($fila->getContenido() as $entrada) {
                $idioma = $entrada['language'] ?? null;
                $contenido = $entrada['content'] ?? null;

                // Una entrada a medias —idioma sin texto— no se guarda: dejarla haría que el
                // `?? $porIdioma['es']` no se dispare y el huésped viera un hueco donde había
                // español disponible.
                if (is_string($idioma) && $idioma !== '' && is_string($contenido) && $contenido !== '') {
                    $porIdioma[$idioma] = $contenido;
                }
            }

            $this->mapa[$fila->getId()] = $porIdioma;
        }

        return $this->mapa;
    }
}
