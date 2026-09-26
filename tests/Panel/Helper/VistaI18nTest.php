<?php

declare(strict_types=1);

namespace App\Tests\Panel\Helper;

use App\Panel\Helper\VistaI18n;
use PHPUnit\Framework\TestCase;

final class VistaI18nTest extends TestCase
{
    public function testPrefiereElEspanol(): void
    {
        self::assertSame('Hola', VistaI18n::espanol([
            ['language' => 'en', 'content' => 'Hello'],
            ['language' => 'es', 'content' => 'Hola'],
        ]));
    }

    public function testSinEspanolEnsenaElPrimero(): void
    {
        self::assertSame('Hello', VistaI18n::espanol([
            ['language' => 'en', 'content' => 'Hello'],
            ['language' => 'fr', 'content' => 'Bonjour'],
        ]));
    }

    public function testLoQueNoEsUnaListaDeTraduccionesEsVacio(): void
    {
        self::assertSame('', VistaI18n::espanol(null));
        self::assertSame('', VistaI18n::espanol([]));
        self::assertSame('', VistaI18n::espanol('texto suelto'));
        self::assertSame('', VistaI18n::espanol([['language' => 'es', 'content' => null]]));
        self::assertSame('', VistaI18n::espanol([['language' => 'es', 'content' => ['anidado']]]));
    }
}
