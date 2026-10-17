<?php

declare(strict_types=1);

namespace App\Cotizacion\Finanzas;

use App\Cotizacion\Dto\CuentaDelExpediente;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionPago;
use App\Cotizacion\Enum\CotizacionMedioPagoEnum;
use App\Cotizacion\Service\Message\CotizacionAvisosDeCobro;
use App\Finanzas\Contract\FinOrigenCobroResolverInterface;
use App\Finanzas\Dto\FinOrigenCobroDto;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cómo se cobra una cotización de turismo con un enlace de pago (16/10/2026).
 *
 * El gemelo de `PmsReservaOrigenCobroResolver`: Finanzas declara el contrato y el módulo que sabe
 * qué es un saldo de viaje lo implementa aquí. Finanzas no cambió una línea.
 *
 * ── El documento es la COTIZACIÓN, y por ella se llega al expediente ────────
 * `origenId` es el id de la fila de la cotización —la versión publicada que el cliente tiene
 * delante—. Cada propuesta tiene así sus enlaces, y el pago que entra se apunta en el EXPEDIENTE
 * (`CotizacionPago`) con el número de propuesta, que es lo que sobrevive a clonados y operativas.
 *
 * ── El saldo es en DÓLARES ──────────────────────────────────────────────────
 * Los totales de una cotización están en USD. Si se pide en soles, es el mismo saldo al tipo de
 * cambio de la cotización —el que vio el cliente—, y el pago entra con ese cambio para que
 * descuente exactamente lo que se le cobró. Lo pagado se cuenta con la MISMA regla que util y pax
 * ({@see CuentaDelExpediente::pagadoDePropuesta()}).
 */
final readonly class CotizacionOrigenCobroResolver implements FinOrigenCobroResolverInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private CotizacionAvisosDeCobro $avisos,
    ) {
    }

    public function soporta(): FinOrigenCobro
    {
        return FinOrigenCobro::COTIZACION;
    }

    public function resolver(Uuid $origenId, ?string $moneda = null): ?FinOrigenCobroDto
    {
        $cotizacion = $this->em->find(Cotizacion::class, $origenId);
        $file = $cotizacion?->getFile();

        if ($cotizacion === null || $file === null) {
            return null;
        }

        $saldoUsd = self::saldoUsd($file, $cotizacion);
        $enSoles = $moneda === 'PEN';
        $saldo = $enSoles ? round($saldoUsd * self::tipoCambio($cotizacion), 2) : $saldoUsd;

        [$nombre, $apellido] = self::nombreDelCliente($file);

        return new FinOrigenCobroDto(
            descripcion: self::describir($file, $cotizacion),
            referencia: (string) $file->getLocalizador(),
            saldoPendiente: number_format($saldo, 2, '.', ''),
            moneda: $enSoles ? 'PEN' : 'USD',
            clienteNombre: $nombre,
            clienteApellido: $apellido,
            clienteEmail: $file->getContacto()?->getEmail() ?: $file->getEmail(),
            clienteTelefono: $file->getContacto()?->getTelefono() ?: $file->getTelefono(),
        );
    }

    /** Lo que falta de una propuesta, en USD: su total menos lo pagado con la regla de siempre. */
    public static function saldoUsd(CotizacionFile $file, Cotizacion $cotizacion): float
    {
        $total = (float) $cotizacion->origenFinancieroParaCliente()->getTotalVenta();
        $pagado = CuentaDelExpediente::pagadoDePropuesta($file, $cotizacion->getPropuesta())['usd'];

        return max(0.0, round($total - $pagado, 2));
    }

    /**
     * Apunta en el expediente el dinero que cobró la pasarela: un `CotizacionPago` con tarjeta.
     *
     * - El **neto** (`montoNeto`): el recargo cubre a la pasarela, no abona el viaje. Misma regla
     *   que alojamiento.
     * - En soles, con el **tipo de cambio de la cotización**: el saldo en soles se calculó con él,
     *   y así el pago descuenta exactamente lo que se le pidió.
     * - Sin `flush`: la transacción la cierra `FinEnlacePagoService::confirmarPago()`, para que el
     *   enlace y su pago entren juntos. El vínculo comercial (pagar hace cliente) lo pone
     *   `CotizacionFileConversacionListener` en ese mismo flush.
     */
    public function registrarCobro(FinEnlacePago $enlace): ?Uuid
    {
        $origenId = $enlace->getOrigenId();
        $cotizacion = $origenId !== null ? $this->em->find(Cotizacion::class, $origenId) : null;
        $file = $cotizacion?->getFile();

        if ($cotizacion === null || $file === null) {
            return null;
        }

        $moneda = $enlace->getMonedaCodigo() === 'PEN' ? 'PEN' : 'USD';

        $pago = (new CotizacionPago())
            ->setFile($file)
            ->setPropuesta($cotizacion->getPropuesta())
            ->setMonto($enlace->getMontoNeto())
            ->setMoneda($moneda)
            ->setTipoCambio($moneda === 'PEN' ? number_format(self::tipoCambio($cotizacion), 4, '.', '') : null)
            ->setMedio(CotizacionMedioPagoEnum::TARJETA)
            ->setFechaPago(($enlace->getPagadoEn() ?? new DateTimeImmutable())->setTime(0, 0))
            ->setReferencia(mb_substr((string) ($enlace->getTransaccionUuid() ?? $enlace->getOrdenId()), 0, 100) ?: null)
            ->setNota(sprintf('Cobro por %s. Enlace de pago %s.', $enlace->getPasarela()->etiqueta(), $enlace->getOrdenId() ?? (string) $enlace->getId()))
            ->setEnlacePagoId($enlace->getId());

        $this->em->persist($pago);

        return $pago->getId();
    }

    /**
     * La pasarela devolvió el dinero: el pago se ANULA con el motivo, no se borra. Idempotente: un
     * pago ya anulado no se toca.
     */
    public function registrarDevolucion(FinEnlacePago $enlace, string $motivo): void
    {
        $pago = $this->em->getRepository(CotizacionPago::class)->findOneBy(['enlacePagoId' => $enlace->getId()]);

        if (!$pago instanceof CotizacionPago || $pago->isAnulado()) {
            return;
        }

        $pago->anular(trim($motivo) !== '' ? 'Devolución por la pasarela: ' . trim($motivo) : 'Devolución por la pasarela', null, new DateTimeImmutable());
    }

    /**
     * «Hemos recibido tu pago» por WhatsApp, al hilo del expediente (plantilla `pago_recibido`, la de
     * alojamiento). Se llama después del flush y una vez por enlace; si falla, quien llama lo deja
     * en el log —el cliente ya pagó—.
     */
    public function confirmarAlCliente(FinEnlacePago $enlace): void
    {
        $origenId = $enlace->getOrigenId();
        $file = $origenId !== null ? $this->em->find(Cotizacion::class, $origenId)?->getFile() : null;

        if ($file === null) {
            $this->logger->warning('[cotizacion] pago confirmado sin expediente al que avisar', ['enlace' => (string) $enlace->getId()]);

            return;
        }

        $this->avisos->pagoRecibido($file, $enlace);
    }

    /** Soles por dólar de la cotización; 1 si no hay, para no dividir por cero ni inventar uno. */
    private static function tipoCambio(Cotizacion $cotizacion): float
    {
        $tc = (float) $cotizacion->getTipoCambio();

        return $tc > 0 ? $tc : 1.0;
    }

    /** «Nune & Todd · v2»: lo que el cliente lee en la página de pago. */
    private static function describir(CotizacionFile $file, Cotizacion $cotizacion): string
    {
        $quien = $file->getNombreGrupo() ?: $file->getPasajeroPrincipal() ?: (string) $file->getLocalizador();

        return mb_substr(sprintf('%s · propuesta %d', $quien, $cotizacion->getPropuesta()), 0, 160);
    }

    /**
     * Nombre y apellido para prellenar la pasarela. El contacto del maestro los guarda separados;
     * si no hay, el pasajero principal va entero como nombre —partirlo sería adivinar dónde empieza
     * el apellido—.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function nombreDelCliente(CotizacionFile $file): array
    {
        $contacto = $file->getContacto();

        if ($contacto !== null && ($contacto->getNombre() ?? '') !== '') {
            return [$contacto->getNombre(), $contacto->getApellido()];
        }

        return [$file->getPasajeroPrincipal() ?: $file->getNombreGrupo(), null];
    }
}
