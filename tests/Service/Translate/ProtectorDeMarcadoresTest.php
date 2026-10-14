<?php

declare(strict_types=1);

namespace App\Tests\Service\Translate;

use App\Service\Translate\ProtectorDeMarcadores;
use PHPUnit\Framework\TestCase;

final class ProtectorDeMarcadoresTest extends TestCase
{
    public function testEnmascaraLosMarcadoresConTipoYValor(): void
    {
        // El enlace de una ficha a otra se traducía: salía `{{ data sheet: luggage-flexible-hours }}`.
        $texto = '<p>¿Llegas antes?</p><p>{{ ficha: equipaje-horarios-flexibles }}</p><p>{{ hora_checkin }}</p>';

        [$enmascarado, $marcadores] = (new ProtectorDeMarcadores())->enmascarar($texto);

        self::assertSame(['{{ ficha: equipaje-horarios-flexibles }}', '{{ hora_checkin }}'], $marcadores);
        self::assertStringNotContainsString('ficha', $enmascarado);
        self::assertSame($texto, (new ProtectorDeMarcadores())->restaurar($enmascarado, $marcadores));
    }

    public function testNoSeComeElTextoEntreDosMarcadores(): void
    {
        [, $marcadores] = (new ProtectorDeMarcadores())->enmascarar('{{ video: https://x.test/a.mp4 }} y luego {{ croquis }}');

        self::assertSame(['{{ video: https://x.test/a.mp4 }}', '{{ croquis }}'], $marcadores);
    }
}
