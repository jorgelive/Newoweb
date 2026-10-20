<?php

declare(strict_types=1);

namespace App\Tests\Pms\Enum;

use App\Pms\Enum\PmsTipoCargo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cómo se clasifica un invoiceItem de Beds24, con las cuatro descripciones que manda de verdad
 * (medido el 20/10/2026 sobre 354 cargos).
 */
final class PmsTipoCargoTest extends TestCase
{
    /** @return iterable<string, array{?string, ?int, PmsTipoCargo}> */
    public static function casos(): iterable
    {
        yield 'alojamiento por subType' => ['[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]', 8, PmsTipoCargo::ALOJAMIENTO];
        yield 'cancel fee con subType 8' => ['Cancel Fee', 8, PmsTipoCargo::PENALIZACION];
        yield 'limpieza' => ['suplemento de limpieza', 11, PmsTipoCargo::LIMPIEZA];
        yield 'cargo por servicio' => ['cargo por servicio', 11, PmsTipoCargo::SERVICIO];
        yield 'nada reconocible' => ['Propina', null, PmsTipoCargo::OTRO];
    }

    #[Test]
    #[DataProvider('casos')]
    public function clasifica(?string $descripcion, ?int $subTipo, PmsTipoCargo $esperado): void
    {
        self::assertSame($esperado, PmsTipoCargo::desdeBeds24($descripcion, $subTipo));
    }

    #[Test]
    public function el_servicio_se_llama_cargo_por_servicio(): void
    {
        self::assertSame('Cargo por servicio', PmsTipoCargo::SERVICIO->label());
    }
}
