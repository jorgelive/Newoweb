<?php

declare(strict_types=1);

namespace App\Tests\Entity\Maestro;

use App\Entity\Maestro\MaestroIdioma;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OrdenarParaFormularioTest extends TestCase
{
    public function testOrdenaPorJerarquiaYConservaLasDemasClaves(): void
    {
        $ordenado = MaestroIdioma::ordenarParaFormulario([
            ['language' => 'en', 'content' => 'Hello', '_hash' => 'abc'],
            ['language' => 'es', 'content' => 'Hola'],
            ['language' => 'nl', 'content' => 'Hallo'],
        ]);

        self::assertSame(['es', 'en', 'nl'], array_column($ordenado, 'language'));
        self::assertSame('abc', $ordenado[1]['_hash'] ?? null, 'la huella de la autotraducción no se pierde');
    }

    /** `content` sale como texto o null, que es lo que promete el tipo. */
    public function testElContenidoQueFaltaONoEsTextoEsNull(): void
    {
        $ordenado = MaestroIdioma::ordenarParaFormulario([
            ['language' => 'es'],
            ['language' => 'en', 'content' => ['raro']],
        ]);

        self::assertNull($ordenado[0]['content']);
        self::assertNull($ordenado[1]['content']);
    }

    /**
     * Antes la validación vivía dentro del comparador de `usort()`, y con UNA fila no se compara
     * nada: una lista de un elemento mal formado pasaba sin mirar.
     */
    public function testUnaSolaFilaSinIdiomaTambienSeRechaza(): void
    {
        $this->expectException(RuntimeException::class);

        MaestroIdioma::ordenarParaFormulario([['content' => 'Hola']]);
    }

    public function testVacioEsVacio(): void
    {
        self::assertSame([], MaestroIdioma::ordenarParaFormulario([]));
    }
}
