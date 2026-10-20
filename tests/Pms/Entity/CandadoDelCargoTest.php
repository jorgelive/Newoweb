<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Pms\Entity\PmsCargoFinanciero;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El candado sólo frena a la sincronización de Beds24: en un cargo manual no protege nada, y el
 * panel lo enseñaba como «Fijado» sin forma de volver a ponerlo una vez soltado (20/10/2026).
 */
final class CandadoDelCargoTest extends TestCase
{
    #[Test]
    public function un_cargo_del_canal_se_fija_y_se_suelta(): void
    {
        $cargo = new PmsCargoFinanciero('123456');

        self::assertTrue($cargo->setFijadoPorOperador(true)->isFijadoPorOperador());
        self::assertFalse($cargo->setFijadoPorOperador(false)->isFijadoPorOperador());
    }

    #[Test]
    public function un_cargo_manual_no_se_fija(): void
    {
        self::assertFalse((new PmsCargoFinanciero())->setFijadoPorOperador(true)->isFijadoPorOperador());
    }
}
