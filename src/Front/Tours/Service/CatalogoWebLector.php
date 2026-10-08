<?php

declare(strict_types=1);

namespace App\Front\Tours\Service;

use App\Cotizacion\Entity\CotizacionCatalogo;
use App\Cotizacion\Service\TourTarjetaResolver;
use App\Dto\Lee;
use App\Front\Tours\Dto\CatalogoWeb;
use App\Front\Tours\Dto\MarcaWeb;
use App\Front\Tours\Dto\PrecioDesdeWeb;
use App\Front\Tours\Dto\TourFichaWeb;
use App\Front\Tours\Dto\TourTarjetaWeb;
use App\Front\Comun\Service\TextoI18n;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Lo que la web pública (openperu.pe) puede enseñar del catálogo de tours.
 *
 * Tres puertas, y las tres exigen lo mismo: catálogo **activo**, **publicado en la web**, con
 * **slug**, y sólo sus tours con `publicado = true`. No hay previsualización de operador: eso
 * es de `pax` (ver `CotizacionCatalogoPublicProvider`). Aquí una sesión abierta no cambia nada,
 * porque una página pública la cachean buscadores y previsualizadores sin saber quién miraba.
 *
 * Las reglas de la tarjeta (portada, precio oculto, días) NO viven aquí: vienen hechas de
 * `TourTarjetaResolver::tarjetas()`. Este lector sólo elige idioma y da forma.
 */
final class CatalogoWebLector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TourTarjetaResolver $tarjetas,
    ) {
    }

    /**
     * Los catálogos de la portada, en su orden. Uno sin tours publicados no sale: sería una
     * sección vacía con título.
     *
     * @return list<CatalogoWeb>
     */
    public function publicados(string $idioma): array
    {
        /** @var list<CotizacionCatalogo> $catalogos */
        $catalogos = $this->em->createQuery(<<<'DQL'
            SELECT k FROM App\Cotizacion\Entity\CotizacionCatalogo k
            WHERE k.activo = true AND k.publicadoWeb = true AND k.slug IS NOT NULL
            ORDER BY k.orden ASC, k.createdAt ASC
        DQL)->getResult();

        $web = [];
        foreach ($catalogos as $catalogo) {
            $c = $this->aWeb($catalogo, $idioma);
            if ($c !== null) {
                $web[] = $c;
            }
        }
        return $web;
    }

    public function catalogo(string $slug, string $idioma): ?CatalogoWeb
    {
        $catalogo = $this->em->getRepository(CotizacionCatalogo::class)->findOneBy([
            'slug' => $slug,
            'activo' => true,
            'publicadoWeb' => true,
        ]);

        return $catalogo !== null ? $this->aWeb($catalogo, $idioma) : null;
    }

    public function ficha(string $slugCatalogo, int $propuesta, string $idioma): ?TourFichaWeb
    {
        $catalogo = $this->catalogo($slugCatalogo, $idioma);
        $tour = $catalogo?->tour($propuesta);
        if ($catalogo === null || $tour === null) {
            return null;
        }

        return new TourFichaWeb($catalogo, $tour, $this->tarjetas->imagenesDeTour($tour->id, $tour->destacados));
    }

    /**
     * Slug de un texto para la URL: «Montaña de Colores» → «montana-de-colores».
     * Es cosmético; el tour se identifica por su número de propuesta.
     */
    public static function slug(string $texto): string
    {
        $slug = (new AsciiSlugger())->slug($texto)->lower()->toString();
        return $slug !== '' ? $slug : 'tour';
    }

    private function aWeb(CotizacionCatalogo $catalogo, string $idioma): ?CatalogoWeb
    {
        $slug = $catalogo->getSlug();
        if ($slug === null) {
            return null;
        }

        $tours = [];
        foreach ($this->tarjetas->tarjetas($catalogo, false) as $t) {
            $titulo = TextoI18n::en($t['titulo'], $idioma) ?? ('Tour ' . $t['propuesta']);
            $resumenHtml = TextoI18n::en($t['resumen'], $idioma);

            $precios = [];
            // Ya vienen EFECTIVOS (override o calculado): ver TourTarjetaResolver::preciosDesdeEfectivos().
            // El resolver ya descartó los valores no numéricos: un «Desde S/ » vacío vende algo que no existe.
            foreach ($t['preciosDesde'] as $p) {
                $precios[] = new PrecioDesdeWeb(TextoI18n::en($p['titulo'], $idioma), $p['valor'], $p['moneda']);
            }

            $tours[] = new TourTarjetaWeb(
                id: $t['id'],
                propuesta: $t['propuesta'],
                titulo: $titulo,
                slug: self::slug($titulo),
                resumenHtml: $resumenHtml,
                resumenPlano: $resumenHtml !== null ? self::plano($resumenHtml) : null,
                numDias: $t['numDias'],
                precios: $precios,
                imagenUrl: Lee::texto(Lee::mapa($t['imagenPortada'])['imageUrl'] ?? null),
                paxBaseGrupo: $t['paxBaseGrupo'],
                destacados: $t['destacados'],
            );
        }

        if ($tours === []) {
            return null;
        }

        return new CatalogoWeb(
            slug: $slug,
            localizador: $catalogo->getLocalizador() ?? '',
            titulo: TextoI18n::en($catalogo->getTituloWeb(), $idioma) ?? ($catalogo->getNombre() ?? 'Tours'),
            descripcionHtml: TextoI18n::en($catalogo->getDescripcionWeb(), $idioma),
            tours: $tours,
            marca: MarcaWeb::de($catalogo->getMarcaWeb()),
        );
    }

    /** HTML del editor → texto para la tarjeta y la meta descripción. */
    private static function plano(string $html): ?string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
            str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], ' ', $html)
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return $texto !== '' ? $texto : null;
    }
}
