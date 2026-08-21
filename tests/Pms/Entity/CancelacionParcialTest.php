<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Perder una casita y MUDARSE de casita no son lo mismo.
 *
 * El sincronizador comparaba los NOMBRES de las casitas que cubrían los hitos antes y después:
 * `array_diff(['Casita 2'], ['Casita 5'])` da «perdió la Casita 2» cuando al huésped sólo se le
 * cambió de puerta — una operación de rutina antes de la llegada.
 *
 * Y no era un aviso que se pudiera retirar: los hitos históricos **sobreviven a todo recálculo
 * por diseño**, así que el hecho falso se queda escrito.
 *
 * Lo que de verdad distingue una pérdida es que la reserva pase a cubrirse con MENOS casitas.
 * Este test fija esa regla; el sincronizador la aplica en `PmsSincronizadorDeEnlace`.
 */
final class CancelacionParcialTest extends TestCase
{
    /**
     * La misma condición que aplica el sincronizador, aislada para poder fijarla.
     *
     * @param list<string> $antes
     * @param list<string> $ahora
     */
    private static function hayPerdida(array $antes, array $ahora): bool
    {
        $perdidas = array_diff($antes, $ahora);

        return $perdidas !== [] && $ahora !== [] && count($ahora) < count($antes);
    }

    #[Test]
    public function mudarse_de_casita_no_es_perder_nada(): void
    {
        // El caso que ensuciaba historiales: mismas fechas, otra puerta.
        self::assertFalse(self::hayPerdida(['Casita 2'], ['Casita 5']));
    }

    #[Test]
    public function que_te_cancelen_una_de_dos_si_es_perder(): void
    {
        self::assertTrue(self::hayPerdida(['Casita 2', 'Casita 4'], ['Casita 4']));
    }

    #[Test]
    public function mudarse_teniendo_dos_tampoco_es_perder(): void
    {
        // Dos casitas, una se reasigna: la cuenta no baja, el huésped no perdió nada.
        self::assertFalse(self::hayPerdida(['Casita 2', 'Casita 4'], ['Casita 5', 'Casita 4']));
    }

    #[Test]
    public function quedarse_sin_ninguna_no_es_parcial_sino_total(): void
    {
        // La cancelación total la trata otra rama, con su aviso genérico: aquí no se anota.
        self::assertFalse(self::hayPerdida(['Casita 2'], []));
    }

    #[Test]
    public function anadir_una_casita_no_anota_nada(): void
    {
        self::assertFalse(self::hayPerdida(['Casita 2'], ['Casita 2', 'Casita 4']));
    }

    #[Test]
    public function sin_cambios_no_anota_nada(): void
    {
        // Corre en CADA recálculo de la reserva: si esto anotara, ensuciaría el historial solo.
        self::assertFalse(self::hayPerdida(['Casita 2'], ['Casita 2']));
    }
}
