<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Pms\Entity\PmsEventoBeds24Link;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Un link extra (la `black` de un horario extra) nunca es el principal de la estancia.
 *
 * Facturas, mensajes, la URL de Beds24 y el pull buscan «la reserva de la estancia» como el link
 * con `esPrincipal`. Si un extra pudiera serlo, cualquiera de ellos podría acabar leyendo la
 * `black` de la víspera como si fuera la reserva del huésped. Ver docs/PlanHorarioExtraSinEventos.md.
 */
final class LinkConRolTest extends TestCase
{
    #[Test]
    public function nace_de_estancia(): void
    {
        $link = new PmsEventoBeds24Link();

        self::assertSame(PmsEventoBeds24Link::ROL_ESTANCIA, $link->getRol());
        self::assertTrue($link->esDeEstancia());
        self::assertTrue($link->isMirror(), 'uno de estancia no principal es el espejo');
    }

    #[Test]
    public function un_extra_no_es_espejo(): void
    {
        $link = (new PmsEventoBeds24Link())->setRol(PmsEventoBeds24Link::ROL_EXTRA_ENTRADA);

        self::assertFalse($link->isEsPrincipal());
        self::assertFalse($link->isMirror());
        self::assertFalse($link->esDeEstancia());
    }

    #[Test]
    public function un_extra_no_puede_hacerse_principal(): void
    {
        $link = (new PmsEventoBeds24Link())->setRol(PmsEventoBeds24Link::ROL_EXTRA_SALIDA);

        $this->expectException(\LogicException::class);
        $link->hacerPrincipal();
    }

    #[Test]
    public function el_principal_no_puede_pasar_a_extra(): void
    {
        $link = (new PmsEventoBeds24Link())->hacerPrincipal();

        $this->expectException(\LogicException::class);
        $link->setRol(PmsEventoBeds24Link::ROL_EXTRA_ENTRADA);
    }

    #[Test]
    public function un_rol_desconocido_no_entra(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PmsEventoBeds24Link())->setRol('extension');
    }
}
