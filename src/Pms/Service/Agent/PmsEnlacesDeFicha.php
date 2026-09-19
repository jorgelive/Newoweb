<?php

declare(strict_types=1);

namespace App\Pms\Service\Agent;

use App\Agent\Contract\ResolutorDeEnlacesInterface;
use App\Entity\Maestro\MaestroIdioma;
use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `{{ ficha: calefactor }}` → «(esto está en el tema «Alquiler de calefacción», que puedes
 * consultar)».
 *
 * ── Por qué el título y no el código ────────────────────────────────────────
 * El código es nuestro; el modelo busca temas por PALABRAS. Con el título puede además
 * **seguirlo**: pedir esa ficha con `consultar_guia` y responder con su contenido, que es
 * exactamente lo que en la guía web hace el botón.
 *
 * ── Por qué está aquí y no dentro de la skill de guía ───────────────────────
 * Lo estaba, y funcionaba, hasta que el conocimiento genérico quiso remitir a una ficha: la
 * alternativa era copiar el texto de la guía dentro de una entrada de conocimiento —el precio
 * del calefactor en dos sitios, divergiendo el día que cambie— o no remitir. Sacándolo aquí lo
 * usan las dos, y una tercera cosa que remita mañana no tiene que volver a escribirlo.
 *
 * ⚠️ Un código que no existe **no se anuncia**: una ficha borrada o un dedazo dejarían al modelo
 * buscando humo, así que el marcador se borra en silencio. Es preferible un texto que no remita
 * a uno que remita a nada.
 */
final readonly class PmsEnlacesDeFicha implements ResolutorDeEnlacesInterface
{
    /** La misma sintaxis que pinta el front: {@see \App\Pms\Guia\PmsGuiaInterpolador}. */
    private const string MARCADOR = '/\{\{\s*ficha\s*:\s*([a-z0-9-]+)\s*\}\}/i';

    public function __construct(private EntityManagerInterface $em) {}

    public function resolver(string $texto, string $idioma): string
    {
        if (!str_contains($texto, '{{')) {
            return $texto;
        }

        return (string) preg_replace_callback(
            self::MARCADOR,
            function (array $m) use ($idioma): string {
                $destino = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['codigo' => $m[1]]);
                $titulo = $destino !== null ? MaestroIdioma::textoEn($destino->getTitulo(), $idioma) : null;

                return $titulo === null || $titulo === ''
                    ? ' '
                    : sprintf(' (esto está en el tema «%s», que puedes consultar) ', $titulo);
            },
            $texto
        );
    }
}
