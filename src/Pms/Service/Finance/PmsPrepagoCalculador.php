<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Enum\PmsPoliticaPrepago;
use App\Pms\Enum\PmsQueSePide;
use App\Pms\Enum\PmsTipoCargo;
use DateTimeImmutable;

/**
 * Cuánto hay que pedirle por adelantado a esta reserva, según la política de su
 * establecimiento virtual.
 *
 * ### Qué NO hace
 *
 * No cobra, no crea enlaces y no toca el saldo. Devuelve una cifra y la clave del texto que
 * la explica; quien la enseñe o la cobre decide después. Es a propósito: el cálculo es la
 * pieza que más se va a leer y la que menos debe tener efectos.
 *
 * ### El caso que se salta, y por qué
 *
 * En los canales que cobran por nosotros ({@see PmsChannel::CANAL_PAGO_TOTAL}: Airbnb, VRBO)
 * **no hay prepago que pedir** — el huésped ya le pagó a la OTA, y el estado de cuenta ni
 * siquiera le enseña importes (`soloProgreso`). Pedirle un adelanto ahí es reclamarle dinero
 * dos veces. Se detecta por el canal, igual que el resto del resumen del huésped.
 *
 * ### La base del cálculo
 *
 * Se reutiliza `getDesglosePorTipo()` de la cabecera, que es donde viven las cuatro reglas
 * del desglose (anulación, `esCargo()`, `totalLinea ?? monto` y la conversión a la moneda
 * base). Calcular aquí por nuestra cuenta habría duplicado esas reglas y garantizado que se
 * separaran con el tiempo.
 *
 * Las políticas «de noches» miran solo {@see PmsTipoCargo::ALOJAMIENTO}; las «del total»,
 * todo lo facturado. La diferencia son unos pocos dólares y el propio enum explica por qué
 * existe igualmente.
 */
final readonly class PmsPrepagoCalculador
{
    /**
     * Adelanto o total, y el corte es **el día de check-in incluido**.
     *
     * ⚠️ Regla de negocio del 28/08/2026: desde la mañana del día de llegada se pide el
     * TOTAL. Un adelanto pierde sentido cuando el huésped ya está entrando, y pedirlo
     * invita a que pague dos veces.
     *
     * ⚠️ **Vive aquí, y no en quien redacta el mensaje, desde el 06/09/2026.** Estuvo dentro
     * de `PmsSituacionDeCobroResolver` —o sea, sólo en el texto— mientras el emisor de
     * enlaces seguía preguntando por `pendiente()`, que no mira fechas. Resultado: pasado el
     * día de llegada el mensaje decía «paga el total» y el sistema emitía enlaces titulados
     * «Adelanto de reserva». Se comprobó en producción sobre dos reservas reales (PQK8EG y
     * 4P559S): en las dos, el operador emitió a mano el total el mismo día de la llegada y el
     * camino automático le puso enfrente un adelanto. Una regla escrita en un solo consumidor
     * no es una regla del negocio: es una regla de esa pantalla.
     *
     * No devuelve `NADA` nunca: ese caso lo decide antes quien pregunta —un canal que ya cobró
     * no llega hasta aquí.
     */
    public function queSePide(PmsInformacionFinanciera $finanzas): PmsQueSePide
    {
        $llegada = $finanzas->getReserva()?->getFechaLlegada();

        // Sin fecha no se puede decidir por tiempo: manda la política, que es lo que hacía el
        // código antes de esta regla.
        $yaLlegoElDia = $llegada !== null
            && (new DateTimeImmutable($llegada->format('Y-m-d'))) <= new DateTimeImmutable('today');

        if ($yaLlegoElDia) {
            return PmsQueSePide::TOTAL;
        }

        // `pendiente()` devuelve null en cuanto hay CUALQUIER pago: ese pago era el adelanto.
        return $this->pendiente($finanzas) !== null
            ? PmsQueSePide::ADELANTO
            : PmsQueSePide::TOTAL;
    }

    /**
     * El prepago que TODAVÍA hay que pedir, o `null` si ya no procede pedirlo.
     *
     * Es `calcular()` más una regla: **si hay algún pago registrado, ese pago ES el prepago**
     * —es lo primero que se cobra— y volver a pedirlo sería reclamarle al huésped algo que ya
     * hizo. `calcular()` responde «cuánto pide la política»; éste, «cuánto queda por pedir».
     *
     * Vive aquí y no en quien lo pinta porque lo consumen tres sitios —el estado de cuenta del
     * huésped, el resumen del panel y la skill `consultar_cuenta` del agente— y una regla
     * escrita tres veces se separa a la primera. El agente es el que más lo agradece: decirle
     * a alguien que ya pagó que tiene un prepago pendiente es una equivocación que se lee.
     *
     * Se mira si hay ALGÚN cobro, en cualquier moneda — el mismo criterio que el estado de cuenta
     * enseña como «pagado»: así lo que dice esta regla y lo que ve el huésped no pueden
     * discrepar. En los canales que cobran por nosotros da igual — `calcular()` ya devuelve
     * `null` antes de llegar aquí.
     *
     * @return array{monto: string, claveI18n: string, politica: string}|null
     */
    public function pendiente(PmsInformacionFinanciera $finanzas): ?array
    {
        // «¿Hay algún pago?» en CUALQUIER moneda. Con el escalar convertido, un cobro en soles
        // sin tipo de cambio aportaba 0 y esta guarda no lo veía: se le volvía a pedir el
        // adelanto a alguien que ya había pagado.
        if (array_filter(
            PmsTotalesPorMoneda::de($finanzas)->porMoneda,
            static fn (array $c): bool => (float) $c['pagos'] > 0.0,
        ) !== []) {
            return null;
        }

        return $this->calcular($finanzas);
    }

    /**
     * @return array{
     *     monto: string,
     *     claveI18n: string,
     *     politica: string
     * }|null  `null` cuando no procede pedir nada: sin política, canal que ya cobró, o
     *         una base de cero (reserva anulada, sin cargos todavía).
     */
    public function calcular(PmsInformacionFinanciera $finanzas): ?array
    {
        $reserva = $finanzas->getReserva();

        if ($reserva === null) {
            return null;
        }

        // El canal ya le cobró al huésped: no se le pide un adelanto encima.
        $canal = $reserva->getChannel()?->getId();
        if ($canal !== null && in_array($canal, PmsChannel::CANAL_PAGO_TOTAL, true)) {
            return null;
        }

        $politica = $reserva->getEstablecimientoVirtualPrincipal()?->getPoliticaPrepago()
            ?? PmsPoliticaPrepago::SIN_PREPAGO;

        if ($politica === PmsPoliticaPrepago::SIN_PREPAGO) {
            return null;
        }

        $base = $this->base($finanzas, $politica);

        if ($base <= 0.0) {
            return null;
        }

        // Por noche: se reparte la base entre las noches y se cobra una. Sin noches
        // conocidas —fechas incompletas— no se inventa un divisor: no procede.
        if ($politica->esPorNoche()) {
            $noches = $reserva->getNoches();

            if ($noches < 1) {
                return null;
            }

            $monto = $base / $noches;
        } else {
            $monto = $base * $politica->fraccion();
        }

        return [
            'monto' => number_format($monto, 2, '.', ''),
            'claveI18n' => (string) $politica->claveI18n(),
            'politica' => $politica->value,
        ];
    }

    /**
     * Importe sobre el que se aplica la política, en la moneda de la cabecera.
     *
     * Sale del desglose por tipo para no reimplementar sus reglas. `excluirEspejoCanal` va
     * en `false` porque este método no se alcanza en los canales que cobran por nosotros
     * —se descartan antes—, así que no hay espejo que excluir.
     */
    private function base(PmsInformacionFinanciera $finanzas, PmsPoliticaPrepago $politica): float
    {
        $desglose = $finanzas->getDesglosePorTipo();

        if ($politica->soloAlojamiento()) {
            return (float) ($desglose[PmsTipoCargo::ALOJAMIENTO->value] ?? '0');
        }

        return array_sum(array_map('floatval', $desglose));
    }
}
