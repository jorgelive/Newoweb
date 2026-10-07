<?php

declare(strict_types=1);

namespace App\Tests\Front\Comun;

use App\Front\Comun\Service\SitioWeb;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Las páginas comunes (libro, legales) eligen cabecera y textos por el dominio pedido.
 */
final class SitioWebTest extends TestCase
{
    public function testElDominioDecideLaWeb(): void
    {
        $sitios = new SitioWeb('openperu.pe');

        self::assertSame(SitioWeb::TOURS, $sitios->de(Request::create('https://openperu.pe/libro-de-reclamaciones')));
        // Un dominio que no es de ninguna web pública no hereda la de tours por descarte.
        self::assertNull($sitios->de(Request::create('https://pax.openperu.pe/libro-de-reclamaciones')));
        self::assertSame('front/tours/layout.html.twig', SitioWeb::layout(SitioWeb::TOURS));
    }
}
