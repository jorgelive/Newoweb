<?php

declare(strict_types=1);

namespace App\Tests\Entity\Maestro;

use App\Entity\Maestro\MaestroIdioma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** La regla que estaba copiada en siete sitios: el suyo si lo traducimos, si no inglés. */
#[CoversClass(MaestroIdioma::class)]
final class MaestroIdiomaPlantillaTest extends TestCase
{
    public function testUnIdiomaQueTraducimosSaleEnElSuyo(): void
    {
        self::assertSame('fr', (new MaestroIdioma('FR', 'Francés'))->setPrioridad(5)->idiomaDePlantilla());
    }

    public function testUnoQueNoTraducimosSaleEnIngles(): void
    {
        self::assertSame('en', (new MaestroIdioma('ja', 'Japonés'))->setPrioridad(0)->idiomaDePlantilla());
    }
}
