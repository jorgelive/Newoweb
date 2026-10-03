<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Finanzas\Entity\FinEnlacePago;
use App\Message\Entity\Message;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * La confirmación al huésped de que su pago con tarjeta entró, con el enlace a su cuenta al día.
 *
 * Sale de `FinEnlacePagoService::confirmarPago()` (vía `PmsReservaOrigenCobroResolver`), así que
 * cubre los tres caminos de cobro y una sola vez por enlace. Es un HECHO, no una fecha: por eso no
 * es una regla del motor —que cuelga mensajes de hitos y deja uno por regla y reserva, y el
 * adelanto y el saldo son dos pagos de la misma reserva—.
 *
 * Los importes van en `variables_plantilla` porque dependen del cobro y no de la reserva; el enlace a
 * la cuenta (`account_url` / `account_path`) lo pone el resolver de la reserva como en el resto
 * de plantillas. Los canales los decide la plantilla, como siempre: WhatsApp si hay un número que
 * funcione, Beds24 en las de OTA.
 */
final readonly class PagoRecibido
{
    public const string PLANTILLA = 'pago_recibido';

    public function __construct(
        private EntityManagerInterface $em,
        private EnlacesDeConversacion $enlaces,
        private LoggerInterface $logger,
    ) {}

    public function confirmar(PmsReserva $reserva, FinEnlacePago $enlace): void
    {
        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::PLANTILLA]);
        $hilo = $this->enlaces->hiloTitularDe(PmsConversacionEnlace::CONTEXT_TYPE, (string) $reserva->getId());

        if ($plantilla === null || $hilo === null) {
            $this->logger->warning('[pms] pago confirmado sin mensaje al huésped', [
                'reserva' => (string) $reserva->getId(),
                'enlace' => (string) $enlace->getId(),
                'motivo' => $plantilla === null ? 'sin plantilla ' . self::PLANTILLA : 'sin conversación',
            ]);

            return;
        }

        $mensaje = new Message();
        $mensaje->setConversation($hilo);
        $mensaje->setDirection(Message::DIRECTION_OUTGOING);
        // Sistema y no anfitrión: no lo escribe nadie, y no debe callar al agente.
        $mensaje->setSenderType(Message::SENDER_SYSTEM);
        $mensaje->setStatus(Message::STATUS_PENDING);
        $mensaje->setTemplate($plantilla);
        $mensaje->setLanguageCode($hilo->getIdioma()->getId() ?? 'es');
        $mensaje->setVariablesPlantilla([
            'importe_abonado' => self::abonado($enlace),
            'comision_pasarela' => self::comision($enlace),
        ]);

        $hilo->addMessage($mensaje);
        $this->em->persist($mensaje);
        $this->em->flush();
    }

    /**
     * Lo que abona la reserva —el neto, la cifra que verá en su estado de cuenta—. Con la comisión
     * suman lo que se cobró a la tarjeta, que es lo que verá en su banco: así cuadra con los dos.
     * Sin palabras, sólo código y número, para que valga igual en los siete idiomas.
     */
    public static function abonado(FinEnlacePago $enlace): string
    {
        return self::conMoneda($enlace, $enlace->getMontoNeto());
    }

    /** El recargo de la pasarela: total menos neto. Con recargo cero sale «USD 0.00», que es cierto. */
    public static function comision(FinEnlacePago $enlace): string
    {
        return self::conMoneda($enlace, bcsub($enlace->getMontoTotal(), $enlace->getMontoNeto(), 2));
    }

    private static function conMoneda(FinEnlacePago $enlace, string $monto): string
    {
        return trim(sprintf('%s %s', $enlace->getMonedaCodigo() ?? '', $monto));
    }
}
