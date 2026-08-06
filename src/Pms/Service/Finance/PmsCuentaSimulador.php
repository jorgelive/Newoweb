<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Pms\Entity\PmsInformacionFinanciera;

/**
 * Cómo quedaría la cuenta si se aplicara un movimiento. **No toca nada.**
 *
 * Existe para que la previsualización de las skills de escritura enseñe la foto completa
 * —cargos, pagado y saldo, antes y después— en vez de una sola línea de texto. Un operador
 * aprueba mejor viendo el estado resultante que leyendo «se cargarán 20».
 *
 * ### Por qué es un servicio y no un método en cada skill
 *
 * Lo usan {@see \App\Agent\Skill\Pms\RegistrarPagoSkill} y
 * {@see \App\Agent\Skill\Pms\RegistrarCargoSkill}, y mañana cualquier endpoint del panel que
 * quiera un «previsualizar» de verdad. Si cada una hiciera su resta, bastaría con que una
 * redondeara distinto para que la previsualización dejara de coincidir con lo que se guarda —
 * que es exactamente el fallo que una previsualización existe para evitar.
 *
 * 🪞 El saldo se calcula **igual** que `PmsInformacionFinanciera::getSaldo()`: cargos menos
 * pagos con `number_format(…, 2)`. Si allí cambia la fórmula, aquí también.
 */
final readonly class PmsCuentaSimulador
{
    /**
     * @param float $deltaCargos Lo que se sumaría a los cargos, en la moneda de la cabecera.
     * @param float $deltaPagos  Lo que se sumaría a lo pagado, en la moneda de la cabecera.
     *
     * @return array{
     *     moneda: string,
     *     antes: array{cargos: string, pagado: string, saldo: string},
     *     despues: array{cargos: string, pagado: string, saldo: string},
     *     saldo_a_favor_del_huesped: bool
     * }
     */
    public function simular(
        PmsInformacionFinanciera $info,
        float $deltaCargos = 0.0,
        float $deltaPagos = 0.0
    ): array {
        $cargosAntes = (float) $info->getTotalCargos();
        $pagosAntes  = (float) $info->getTotalPagos();

        $cargosDespues = $cargosAntes + $deltaCargos;
        $pagosDespues  = $pagosAntes + $deltaPagos;

        $saldoDespues = $cargosDespues - $pagosDespues;

        return [
            'moneda' => $info->getMoneda()?->getId() ?? '',
            'antes' => [
                'cargos' => $this->cifra($cargosAntes),
                'pagado' => $this->cifra($pagosAntes),
                // El de la entidad, no uno recalculado: es el que el operador ve en el panel.
                'saldo'  => $info->getSaldo(),
            ],
            'despues' => [
                'cargos' => $this->cifra($cargosDespues),
                'pagado' => $this->cifra($pagosDespues),
                'saldo'  => $this->cifra($saldoDespues),
            ],
            // Se devuelve como booleano y no se deja deducir del signo: quien lee esto es un
            // modelo, y un «-5.89» no siempre se interpreta como lo que es.
            'saldo_a_favor_del_huesped' => $saldoDespues < 0.0,
        ];
    }

    /** Mismo formato que `PmsInformacionFinanciera::getSaldo()`. */
    private function cifra(float $valor): string
    {
        return number_format($valor, 2, '.', '');
    }
}
