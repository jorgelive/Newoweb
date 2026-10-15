<?php

declare(strict_types=1);

namespace App\Tests\Front\Tours;

use App\Cotizacion\Entity\CotizacionCatalogo;
use App\Front\Tours\Controller\TourController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validation;

/**
 * Los catálogos viven en la raíz de la web (15/10/2026): la ruta tiene que rechazar las palabras
 * que ya son de otra cosa, y la entidad no puede dejar que un catálogo se llame así. Son dos listas
 * —una expresión en el atributo de la ruta, una constante en la entidad— y esto las mantiene
 * casadas.
 */
final class SlugsDeCatalogoTest extends TestCase
{
    private static function casaComoCatalogo(string $tramo): bool
    {
        return preg_match('#^/(' . TourController::SLUG_CATALOGO . ')$#', '/' . $tramo) === 1;
    }

    #[Test]
    public function la_ruta_rechaza_todas_las_palabras_reservadas_de_la_entidad(): void
    {
        foreach (CotizacionCatalogo::SLUGS_RESERVADOS as $reservada) {
            self::assertFalse(self::casaComoCatalogo($reservada), "«{$reservada}» casaría como catálogo y taparía su ruta.");
        }
    }

    #[Test]
    public function la_ruta_acepta_los_slugs_de_verdad_aunque_empiecen_como_una_reservada(): void
    {
        foreach (['machu-picchu', 'ofertas-cusco', 'viajes-de-promocion', 'traslados', 'estancias', 'english-tours', 'legales-2027'] as $slug) {
            self::assertTrue(self::casaComoCatalogo($slug), $slug);
        }
    }

    #[Test]
    public function la_entidad_no_deja_publicar_con_una_reservada(): void
    {
        // Sólo la comprobación del slug: el validador completo de la entidad trae `UniqueEntity`,
        // que necesita el contenedor.
        $catalogo = (new CotizacionCatalogo())->setSlug('legal');
        $soloSlug = new Callback(static function (mixed $objeto, ExecutionContextInterface $contexto): void {
            if ($objeto instanceof CotizacionCatalogo) {
                $objeto->validarPublicacionWeb($contexto);
            }
        });

        $violaciones = Validation::createValidator()->validate($catalogo, $soloSlug);
        $mensajes = [];
        foreach ($violaciones as $v) {
            $mensajes[] = (string) $v->getMessage();
        }

        self::assertContains('«legal» está reservada por la web: elige otra dirección.', $mensajes);
    }
}
