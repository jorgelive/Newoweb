<?php

declare(strict_types=1);

namespace App\Tests\Pms\Dto;

use App\Pms\Dto\PmsOcupacionDto;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cuando lo único que ocupa la noche consultada es la entrada temprana o la salida tardía de una
 * estancia, el mensaje tiene que decirlo — y con ESA noche, no con las fechas de la estancia.
 */
final class PmsOcupacionDtoNocheExtraTest extends TestCase
{
    #[Test]
    public function la_entrada_temprana_ocupa_la_vispera(): void
    {
        $dto = $this->dto('entrada_temprana');

        self::assertSame('la entrada temprana de Anna Müller', $dto->quienOcupa());
        self::assertSame(['2027-02-01', '2027-02-02'], $dto->nochesOcupadas());
        self::assertSame('entrada_temprana', $dto->toArray()['noche_extra']);
    }

    #[Test]
    public function la_salida_tardia_ocupa_la_noche_de_salida(): void
    {
        $dto = $this->dto('salida_tardia');

        self::assertSame('la salida tardía de Anna Müller', $dto->quienOcupa());
        self::assertSame(['2027-02-05', '2027-02-06'], $dto->nochesOcupadas());
    }

    #[Test]
    public function la_estancia_misma_se_dice_como_siempre(): void
    {
        $dto = $this->dto(null);

        self::assertSame('Anna Müller', $dto->quienOcupa());
        self::assertSame(['2027-02-02', '2027-02-05'], $dto->nochesOcupadas());
        self::assertArrayNotHasKey('noche_extra', $dto->toArray());
    }

    private function dto(?string $nocheExtra): PmsOcupacionDto
    {
        return new PmsOcupacionDto(
            casita: 'Casita 1',
            casitaId: '019c026a-4889-7b57-a046-6e8b67476933',
            establecimiento: 'OpenPeru',
            huesped: 'Anna Müller',
            entra: '2027-02-02',
            sale: '2027-02-05',
            horaEntrada: '09:00',
            horaSalida: '17:00',
            estado: 'confirmada',
            esEstancia: true,
            esOta: false,
            localizador: 'UV5XPW',
            reservaId: null,
            eventoId: '019c026a-0000-7000-8000-000000000000',
            nocheExtra: $nocheExtra,
        );
    }
}
