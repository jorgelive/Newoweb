<?php

declare(strict_types=1);

namespace App\Tests\Pms\Dto;

use App\Pms\Dto\Beds24BookingDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El único camino por el que un booking de Beds24 se convierte en DTO —el pull y el webhook—.
 *
 * Ver `docs/PmsBeds24ReservasSync.md` §12.20: el pull iba por el serializer y guardaba `''` donde
 * el webhook guardaba `null`.
 */
#[CoversClass(Beds24BookingDto::class)]
final class Beds24BookingDtoTest extends TestCase
{
    public function testUnVacioEsNullYElTextoSeRecorta(): void
    {
        $dto = Beds24BookingDto::fromArray([
            'id' => 123, 'roomId' => '45', 'comments' => '', 'arrivalTime' => '   ',
            'rateDescription' => "  Tarifa flexible\n", 'custom1' => '', 'notes' => null,
        ]);

        self::assertNull($dto->comments);
        self::assertNull($dto->arrivalTime);
        self::assertNull($dto->custom1);
        self::assertNull($dto->notes);
        self::assertSame('Tarifa flexible', $dto->rateDescription);
        self::assertSame(45, $dto->roomId);
    }

    /**
     * Un array donde se esperaba texto era, con `(string)`, la palabra «Array» guardada en la
     * reserva. Ahora es «no llegó nada».
     */
    public function testUnArrayDondeSeEsperabaTextoNoSeConvierteEnLaPalabraArray(): void
    {
        $dto = Beds24BookingDto::fromArray(['id' => 1, 'comments' => ['raro' => 'x'], 'firstName' => ['Ana']]);

        self::assertNull($dto->comments);
        self::assertNull($dto->firstName);
    }

    /** El `masterId` sale del grupo cuando viene (API v2), y de la raíz si no (v1). */
    public function testElMasterSaleDelGrupoAntesQueDeLaRaiz(): void
    {
        self::assertSame(91702519, Beds24BookingDto::fromArray(['id' => 2, 'bookingGroup' => ['master' => '91702519'], 'masterId' => 7])->masterId);
        self::assertSame(7, Beds24BookingDto::fromArray(['id' => 2, 'masterId' => 7])->masterId);
        self::assertSame([], Beds24BookingDto::fromArray(['id' => 2])->bookingGroup);
    }
}
