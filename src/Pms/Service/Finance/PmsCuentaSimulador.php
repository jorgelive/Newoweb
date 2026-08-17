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
 * ### Simula UNA moneda, la del movimiento
 *
 * Desde el 16/08/2026 la contabilidad va por moneda y no se convierte (§12.2b). Un pago en soles
 * mueve la fila de soles y no toca la de dólares, así que simular «la cuenta» entera sería
 * enseñar cifras que ese movimiento no cambia — y en una previsualización eso es peor que no
 * enseñar nada: el operador aprueba mirando lo que se mueve.
 *
 * Por eso recibe la moneda a la que se aplica el delta. Quien la conoce es la skill: es la moneda
 * en que entró el dinero, o aquella a la que se imputó.
 *
 * 🪞 Las cifras salen de {@see PmsTotalesPorMoneda}, el mismo objeto que alimenta el panel. Antes
 * se leían de `getTotalCargos()`/`getSaldo()`, que son los escalares convertidos del modelo viejo
 * — y encima rancios dentro de la petición que acababa de escribir.
 */
final readonly class PmsCuentaSimulador
{
    /**
     * @param float       $deltaCargos Lo que se sumaría a los cargos, en `$moneda`.
     * @param float       $deltaPagos  Lo que se sumaría a lo pagado, en `$moneda`.
     * @param string|null $moneda      Moneda del movimiento. `null` = la de cotización de la ficha.
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
        float $deltaPagos = 0.0,
        ?string $moneda = null,
    ): array {
        $moneda ??= $info->getMoneda()?->getId() ?? 'USD';
        $fila = PmsTotalesPorMoneda::de($info)->porMoneda[$moneda] ?? null;

        $cargosAntes = (float) ($fila['cargos'] ?? '0');
        $pagosAntes  = (float) ($fila['pagos'] ?? '0');

        $cargosDespues = $cargosAntes + $deltaCargos;
        $pagosDespues  = $pagosAntes + $deltaPagos;

        $saldoDespues = $cargosDespues - $pagosDespues;

        return [
            'moneda' => $moneda,
            'antes' => [
                'cargos' => $this->cifra($cargosAntes),
                'pagado' => $this->cifra($pagosAntes),
                'saldo'  => $this->cifra($cargosAntes - $pagosAntes),
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
