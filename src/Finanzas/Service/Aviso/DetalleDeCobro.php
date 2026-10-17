<?php

declare(strict_types=1);

namespace App\Finanzas\Service\Aviso;

use App\Finanzas\Entity\FinEnlacePago;

/**
 * Cómo se le describe a un cliente lo que acaba de pagar con un enlace: el neto y la comisión de la
 * pasarela, en su idioma.
 *
 * Estuvo en `App\Pms\Service\Message\PagoRecibido` hasta el 16/10/2026, cuando lo necesitaron
 * también los cobros de turismo (`CotizacionPagoRecibido`). Describir un cobro con su comisión es
 * conocimiento de Finanzas, no del PMS, y una copia en cada módulo acabaría diciendo la comisión de
 * dos formas. `PagoRecibido::detalle()` sigue existiendo y delega aquí.
 */
final class DetalleDeCobro
{
    /** «%1$s + %2$s de comisión…»: neto y comisión, cada uno con su moneda. */
    private const array COMISION = [
        'es' => '%s + %s de comisión de la pasarela de pago',
        'en' => '%s + a %s payment gateway fee',
        'pt' => '%s + %s de taxa da plataforma de pagamento',
        'fr' => '%s + %s de frais de plateforme de paiement',
        'it' => '%s + %s di commissione del gateway di pagamento',
        'de' => '%s + %s Gebühr des Zahlungsanbieters',
        'nl' => '%s + %s transactiekosten van de betaalprovider',
    ];

    /**
     * «USD 51.32 + USD 2.82 de comisión de la pasarela de pago»: el neto es lo que verá abonado en
     * su estado de cuenta (o en su viaje), y la suma lo que verá en su banco. Así cuadra con los dos (Jorge,
     * 03/10/2026). Sin recargo, sólo el neto: «+ USD 0.00 de comisión» era cierto pero raro.
     *
     * Una sola variable y en el idioma del huésped, porque Meta no traduce variables: la frase va
     * escrita aquí en los siete, como los botones de los comandos de plantillas.
     */
    public static function de(FinEnlacePago $enlace, string $idioma): string
    {
        $total = $enlace->getMontoTotal();
        $neto = $enlace->getMontoNeto();

        // Las columnas son `decimal` y siempre traen dígitos, pero el tipo sólo dice `string` y
        // `bcsub()` lanza con otra cosa. Se lanza con los dos valores: `confirmarPago()` lo deja en
        // el log, y una comisión inventada sería una cifra plausible y falsa delante del huésped.
        if (!is_numeric($total) || !is_numeric($neto)) {
            throw new \LogicException(sprintf('Enlace %s con importes que no son números (total «%s», neto «%s»).', $enlace->getId(), $total, $neto));
        }

        $comision = bcsub($total, $neto, 2);
        $abonado = self::conMoneda($enlace, $neto);

        if (bccomp($comision, '0', 2) <= 0) {
            return $abonado;
        }

        return sprintf(self::COMISION[$idioma] ?? self::COMISION['en'], $abonado, self::conMoneda($enlace, $comision));
    }

    private static function conMoneda(FinEnlacePago $enlace, string $monto): string
    {
        return trim(sprintf('%s %s', $enlace->getMonedaCodigo() ?? '', $monto));
    }
}
