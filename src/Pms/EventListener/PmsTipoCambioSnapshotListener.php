<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Service\Finance\TipoCambioDelDia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Al nacer un cargo, un cobro o una ficha, le pone el tipo de cambio del día.
 *
 * ── Qué significa el campo, que no es lo que parece ─────────────────────────
 * `tipo_cambio` **no** es «el número que hace falta para convertir». Es **cuánto valía el dólar
 * el día en que ese dinero se movió**: un hecho histórico del registro, que sigue siendo cierto
 * y sigue siendo útil aunque nadie convierta nada. Por eso se guarda SIEMPRE — coincida o no la
 * moneda del registro con la de la ficha— y no sólo cuando hace falta para una suma.
 *
 * Con él se puede reconstruir cualquier cuenta a posteriori, decirle a un huésped a cuánto se le
 * cambió, y cuadrar una reserva que se cobró en dos monedas. Sin él, esa información no existe
 * en ninguna parte: SUNAT publica la cotización de cada día, pero nadie sabe cuál se aplicó.
 *
 * ── Por qué un listener y no una llamada en cada creador ────────────────────
 * Mismo argumento que {@see PmsLimpiezaAsignacionListener}, y aquí está medido: hay **seis**
 * sitios que crean registros financieros —`PmsCargosAutomaticosService`,
 * `PmsPagoOtaAutomaticoService`, `Beds24InvoiceReceivePersister`, `RegistrarCargoSkill`,
 * `RegistrarPagoSkill`, `PmsReservaOrigenCobroResolver`— más el POST de la API, y el 15/08/2026
 * **dos de ellos no lo sellaban**. Poner el defecto en uno solo garantiza que los otros sigan
 * naciendo cojos, y el hueco no se ve: un registro sin tipo de cambio no da error, simplemente
 * aporta 0 al total en la otra moneda y desaparece. Es el fallo que se destapó con la reserva
 * HMN4BP8J25 —«en dólares cuadra y en soles no»— y el que dejó 6 registros históricos sin sellar.
 *
 * En `prePersist` entra por todos los caminos a la vez.
 *
 * ── Es un DEFECTO, no una regla ─────────────────────────────────────────────
 * Sólo escribe si el campo viene vacío. Quien registre un cobro indicando el cambio que de
 * verdad se aplicó —el del mostrador, que no siempre es el de SUNAT— manda, y esto no le pisa la
 * decisión. También es lo que impide que choque con `assertTipoCambioNoBloqueado()`, el candado
 * que ya protege este campo una vez puesto.
 *
 * ── El día que se toma es el día del dinero ─────────────────────────────────
 * En un cobro, `fechaPago`: un pago de hace tres días se registra hoy pero ocurrió entonces, y
 * el cambio bueno es el de entonces. En un cargo no hay fecha propia, así que es hoy — que es su
 * fecha de creación. Mismo criterio que `PmsCompletarTipoCambioCommand`, que es quien completa
 * los históricos; si aquí cambia, allí también.
 *
 * ── Nunca rompe un guardado ─────────────────────────────────────────────────
 * `TipoCambioDelDia::venta()` cachea en base, cae a SUNAT y de ahí al último disponible, y no
 * lanza nunca. Si aun así devuelve `null`, el registro nace sin tipo de cambio y se persiste
 * igual: un problema con la cotización no puede impedir anotar un cobro que ya se recibió.
 *
 * ── La CABECERA también, y por un motivo distinto ───────────────────────────
 * En un cargo o un cobro el tipo de cambio es memoria. En `PmsInformacionFinanciera` es el
 * **cambio de cuadre**: el que responde «te pago en soles lo que falta, ¿cuánto es?» y con el que
 * se llevan a una sola cifra los saldos de las dos monedas.
 *
 * Se añadió el 16/08/2026, al revisar (§12.4.1b). La migración `Version20260816010000` rellenó las 317
 * fichas existentes, así que en local no se veía nada — pero **la ficha nueva nacía con el campo
 * vacío**, y sin él el cuadre descarta en silencio la moneda que no es la base (el
 * `COALESCE(..., 0)` de `PmsEstadoPagoEventosService::subconsultaDeCuadre()`). En una ficha en
 * soles con cargos en dólares, saldar los soles daba cuadre 0 y con él `pago-total`, que abre los
 * códigos de acceso de la casa. Aquello se veía en producción dentro de una semana, no hoy.
 *
 * Aquí se sella el defecto; el guardia `monedas_sin_convertir` de ese mismo SQL es la segunda
 * línea, para cuando la cotización no esté disponible y esto devuelva `null`.
 */
#[AsEntityListener(event: Events::prePersist, method: 'sellarCargo', entity: PmsCargoFinanciero::class)]
#[AsEntityListener(event: Events::prePersist, method: 'sellarPago', entity: PmsPagoFinanciero::class)]
#[AsEntityListener(event: Events::prePersist, method: 'sellarFicha', entity: PmsInformacionFinanciera::class)]
final class PmsTipoCambioSnapshotListener
{
    public function __construct(
        private readonly TipoCambioDelDia $tipoCambio,
    ) {}

    public function sellarCargo(PmsCargoFinanciero $cargo): void
    {
        if ($cargo->getTipoCambio() !== null) {
            return;
        }

        // Sin fecha: `venta()` toma hoy en zona Lima, que es la fecha de creación del cargo.
        $cargo->setTipoCambio($this->tipoCambio->venta());
    }

    public function sellarPago(PmsPagoFinanciero $pago): void
    {
        if ($pago->getTipoCambio() !== null) {
            return;
        }

        $pago->setTipoCambio($this->tipoCambio->venta($pago->getFechaPago()));
    }

    public function sellarFicha(PmsInformacionFinanciera $info): void
    {
        if ($info->getTipoCambio() !== null) {
            return;
        }

        // El día en que se abre la ficha, que es cuando se pacta el trato. El operador lo puede
        // cambiar después desde el panel: a diferencia del de un cargo, éste no es un hecho
        // histórico sino el cambio con el que se decide cerrar la cuenta.
        $info->setTipoCambio($this->tipoCambio->venta());
    }
}
