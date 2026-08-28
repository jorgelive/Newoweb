<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Finanzas\Contract\FinOrigenCobroResolverInterface;
use App\Finanzas\Dto\FinOrigenCobroDto;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Repository\PmsReservaRepository;
use App\Pms\Service\Message\TelefonoDeContacto;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cómo se cobra una reserva de alojamiento del PMS.
 *
 * Vive en `src/Pms/` y no en `src/Finanzas/` a propósito: el módulo que sabe qué es un
 * saldo de reserva es el PMS. Finanzas sólo declara la interfaz. Cuando exista el módulo
 * de tours, su resolver será el gemelo de este archivo y Finanzas no cambiará una línea.
 *
 * @see \App\Finanzas\Contract\FinOrigenCobroResolverInterface
 */
final class PmsReservaOrigenCobroResolver implements FinOrigenCobroResolverInterface
{
    public function __construct(
        private readonly PmsReservaRepository $reservas,
        private readonly TelefonoDeContacto $telefonos,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function soporta(): FinOrigenCobro
    {
        return FinOrigenCobro::PMS_RESERVA;
    }

    public function resolver(Uuid $origenId, ?string $moneda = null): ?FinOrigenCobroDto
    {
        $reserva = $this->reservas->find($origenId);

        if (!$reserva instanceof PmsReserva) {
            return null;
        }

        $info = $reserva->getInformacionFinanciera();

        // 💱 EL SALDO DE UNA MONEDA, no un total convertido.
        //
        // Una pasarela cobra en UNA divisa. Si la reserva debe US$ 65 y S/ 50, cobrarle «un
        // total» exigiría convertir — justo lo que se retiró (§12.2b). Se emite un enlace por
        // moneda y cada uno pide exactamente lo que se debe en ella.
        //
        // Sin `$moneda`, la de mayor saldo: es lo que respondía antes para los llamantes que no
        // saben de esto, y es la respuesta útil cuando sólo hay una.
        $porMoneda = $info === null ? [] : PmsTotalesPorMoneda::de($info)->porMoneda;
        $eleccion = $moneda !== null && isset($porMoneda[$moneda])
            ? $moneda
            : $this->monedaConMasSaldo($porMoneda);

        // Sin cabecera financiera no hay saldo que cobrar todavía: se devuelve 0 y el
        // servicio lo rechaza con un mensaje claro en vez de reventar por null.
        $saldo = $eleccion !== null ? $porMoneda[$eleccion]['saldo'] : '0.00';
        $moneda = $eleccion
            ?? $info?->getMoneda()?->getId()
            ?? $reserva->getMoneda()?->getId()
            ?? 'USD';

        return new FinOrigenCobroDto(
            descripcion: $this->describir($reserva),
            referencia: (string) $reserva->getLocalizador(),
            saldoPendiente: $saldo,
            moneda: $moneda,
            clienteNombre: $reserva->getNombreApellido(),
            clienteEmail: $reserva->getEmailCliente(),
            clienteTelefono: $this->telefonos->para($reserva),
        );
    }

    /**
     * La moneda con más saldo pendiente, o `null` si no hay ninguna con deuda.
     *
     * Con una sola moneda —lo normal— devuelve esa. Con dos, la que más se debe: es la que un
     * operador que no ha elegido querría cobrar primero.
     *
     * @param array<string, array{cargos: string, pagos: string, saldo: string}> $porMoneda
     */
    private function monedaConMasSaldo(array $porMoneda): ?string
    {
        $mejor = null;
        $mayor = 0.0;

        foreach ($porMoneda as $moneda => $cifras) {
            $saldo = (float) $cifras['saldo'];

            if ($saldo > $mayor) {
                $mayor = $saldo;
                $mejor = $moneda;
            }
        }

        return $mejor;
    }

    /**
     * Crea el `PmsPagoFinanciero` del cobro por pasarela.
     *
     * Detalles que no son obvios y que hacen que el saldo cuadre:
     *
     * - Se imputa el **neto** (`montoNeto`), no lo que se pasó por la tarjeta. El recargo
     *   cubre a la pasarela, no abona la estancia — es la misma regla que ya documenta
     *   `PmsPagoFinanciero::$monto`.
     * - **NO se marca `esAutomatico`**, aunque lo genere el sistema. Esa bandera no
     *   significa "lo creó el sistema" sino "el sistema lo REGENERA solo": es el depósito
     *   espejo de las OTA de pago total, y de ella cuelgan dos guardas —no borrable
     *   (`PmsInformacionFinancieraCoherenciaListener`) y no editable
     *   (`assertPagoAutomaticoNoEditable`)— que existen para que el operador no pelee
     *   contra un registro que va a reaparecer.
     *
     *   Un cobro por pasarela no reaparece: es un hecho consumado. Marcarlo `esAutomatico`
     *   lo bloquearía con un mensaje sobre "depósitos del canal" que no viene a cuento.
     *
     *   ⚠️ Que no sea automático **no significa que se pueda borrar**: desde el 28/08/2026
     *   `getMotivoNoBorrable()` lo veta por `enlacePagoId`, y por otra razón —la pasarela no
     *   se entera de que aquí se borró una fila—. Para deshacerlo está la devolución
     *   (`registrarDevolucion()`), que lo deja en cero. Son dos vetos distintos con motivos
     *   distintos, y el texto que ve el operador lo dice.
     *
     *   La trazabilidad no se pierde: el enlace apunta al pago con `movimientoGeneradoId`,
     *   el pago al enlace con `enlacePagoId`, y lleva además la referencia de la transacción
     *   y la nota con la pasarela.
     * - **Sin cobrador**: nadie recibió este dinero en mano, entra directo a la cuenta.
     *   Ver la nota de `PmsPagoFinanciero::$cobrador`.
     * - No se toca `total_pagos` ni el estado de pago de la reserva: de eso ya se encarga
     *   `PmsInformacionFinancieraCoherenciaListener` al persistir el pago.
     */
    public function registrarCobro(FinEnlacePago $enlace): ?Uuid
    {
        $reserva = $this->reservas->find($enlace->getOrigenId());

        if (!$reserva instanceof PmsReserva) {
            return null;
        }

        $info = $reserva->getInformacionFinanciera();

        if ($info === null) {
            return null;
        }

        $pago = new PmsPagoFinanciero();
        $pago
            ->setInformacionFinanciera($info)
            ->setMoneda($enlace->getMoneda())
            ->setMonto($enlace->getMontoNeto())
            ->setMedioPago(PmsMedioPago::TARJETA_CREDITO)
            ->setComisionPorcentaje($enlace->getRecargoPorcentaje())
            ->setFechaPago($enlace->getPagadoEn() ?? new DateTimeImmutable())
            // De dónde vino el dinero, en un campo y no sólo en las notas: es lo que hace que
            // `PmsPagoFinanciero::getMotivoNoBorrable()` pueda vetar el borrado sin preguntarle
            // nada a Finanzas. Un texto en `notas` no es una regla, es una frase.
            ->setEnlacePagoId($enlace->getId())
            ->setReferencia($enlace->getTransaccionUuid() ?? $enlace->getOrdenId())
            ->setNotas(sprintf(
                'Cobro por %s. Enlace de pago %s.',
                $enlace->getPasarela()->etiqueta(),
                $enlace->getOrdenId() ?? (string) $enlace->getId(),
            ));

        $this->em->persist($pago);

        // Sin flush: la transacción la cierra `FinEnlacePagoService::confirmarPago()`, para
        // que el enlace y su pago entren juntos o no entre ninguno. El id ya existe (el
        // constructor de la entidad lo genera), así que puede devolverse antes del INSERT.
        return $pago->getId();
    }

    /**
     * La reserva deja de tener ese dinero: el cobro pasa a **cero con una nota**.
     *
     * ── Por qué a cero y no un cargo de devolución ──────────────────────────────
     * Las dos formas devuelven el mismo saldo, y ésta deja la cuenta del huésped más limpia:
     * un contra-cargo mete una línea POSITIVA en su desglose —parece que se le cobra algo más—
     * y hay que explicarla. El cobro a cero no aparece en ningún sitio donde estorbe y el
     * saldo sube solo.
     *
     * ── Por qué NO se borra la fila ─────────────────────────────────────────────
     * El cobro existió. Borrarlo dejaría el enlace apuntando a una fila inexistente y la
     * reserva sin rastro de que hubo un pago y una devolución — que es exactamente lo que
     * `getMotivoNoBorrable()` vino a impedir. Se marca, no se borra: la regla del módulo.
     *
     * ⚠️ El importe se cambia por el ORM, no por SQL, **a propósito**: el listener de
     * coherencia se engancha al flush y es quien recalcula `total_pagos` y el saldo de la
     * cabecera. Un `UPDATE` directo dejaría la fila a cero y los totales diciendo lo de antes.
     *
     * ⚠️ La comisión también se pone a cero. Con el importe en cero, un `comisionPorcentaje`
     * vivo describe un porcentaje de nada: sobra, y lo que calcula sobre él sale raro.
     */
    public function registrarDevolucion(FinEnlacePago $enlace, string $motivo): void
    {
        $pago = $this->pagoDelEnlace($enlace);

        if ($pago === null) {
            // El asiento ya no está (reserva borrada, o un enlace anterior al campo). El
            // dinero ya se devolvió: no hay nada que deshacer aquí y reventar sería peor.
            //
            // Pero se DEJA ESCRITO: sin esta línea el saldo de la reserva se queda mintiendo
            // y no hay forma de saber por qué. La migración rellenó `enlace_pago_id` hacia
            // atrás, así que el caso es raro — razón de más para que, cuando pase, conste.
            $this->logger->warning(
                '[pms] devolución sin asiento que deshacer: el pago del enlace ya no existe',
                ['enlace' => (string) $enlace->getId()],
            );

            return;
        }

        // Idempotencia: si ya está a cero, esto ya corrió. No se apila una segunda nota.
        if ((float) $pago->getMonto() === 0.0) {
            return;
        }

        $pago
            ->setMonto('0.00')
            ->setComisionPorcentaje(null)
            ->setNotas(trim(sprintf(
                "%s\n\nDEVUELTO el %s: %s %s reintegrados por %s. El cobro queda en 0 y el saldo "
                . "vuelve a la reserva. Motivo: %s\n"
                // El estado de pago de la estancia NO baja solo (§12.9: registrar un pago
                // confirma, quitarlo no des-confirma), y de él dependen los códigos de acceso
                // de la guía. Se dice AQUÍ porque es la única línea que el operador va a leer.
                . "⚠️ Revisa el estado de pago de la estancia: no baja solo, y de él dependen "
                . "los códigos de acceso.",
                (string) $pago->getNotas(),
                (new DateTimeImmutable())->format('d/m/Y'),
                $enlace->getMonedaSimbolo() ?? $enlace->getMonedaCodigo() ?? '',
                $enlace->getMontoNeto(),
                $enlace->getPasarela()->etiqueta(),
                $motivo !== '' ? $motivo : 'no indicado',
            )));

        // Sin flush: lo cierra `FinEnlacePagoService::reembolsar()`, para que el estado del
        // enlace y el asiento del módulo entren juntos o no entre ninguno.
    }

    /**
     * El `PmsPagoFinanciero` que generó este enlace.
     *
     * Se busca por `enlacePagoId` —la marca que persiste el propio `registrarCobro()`— y no
     * por `movimientoGeneradoId` del enlace: es la misma relación, pero indexada y del lado
     * del PMS, que es quien tiene que encontrarla.
     */
    private function pagoDelEnlace(FinEnlacePago $enlace): ?PmsPagoFinanciero
    {
        return $this->em->getRepository(PmsPagoFinanciero::class)
            ->findOneBy(['enlacePagoId' => $enlace->getId()]);
    }

    private function describir(PmsReserva $reserva): string
    {
        $unidad = $reserva->getUnidadesAggregate() ?: $reserva->getNombreHabitacion();
        $llegada = $reserva->getFechaLlegada()?->format('d/m/Y');
        $salida = $reserva->getFechaSalida()?->format('d/m/Y');

        $descripcion = trim(sprintf('%s — %s', $reserva->getNombreHotel(), $unidad), ' —');

        if ($llegada && $salida) {
            $descripcion .= sprintf(' (%s al %s)', $llegada, $salida);
        }

        return substr($descripcion, 0, 255);
    }
}
