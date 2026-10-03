<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Finanzas\Entity\FinEnlacePago;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
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
 * El importe va en `variables_plantilla` porque depende del cobro y no de la reserva; el enlace a
 * la cuenta (`account_url` / `account_path`) lo pone el resolver de la reserva como en el resto
 * de plantillas. Los canales los decide la plantilla, como siempre: WhatsApp si hay un número que
 * funcione, Beds24 en las de OTA.
 */
final readonly class PagoRecibido
{
    public const string PLANTILLA = 'pago_recibido';

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
        // La reserva que pagó, estampada: en un hilo con varias, sin esto el enlace a la cuenta
        // saldría con la del contexto de la conversación, que puede ser otra.
        $mensaje->setAsunto(PmsConversacionEnlace::CONTEXT_TYPE, (string) $reserva->getId());
        $mensaje->setDirection(Message::DIRECTION_OUTGOING);
        // Sistema y no anfitrión: no lo escribe nadie, y no debe callar al agente.
        $mensaje->setSenderType(Message::SENDER_SYSTEM);
        $mensaje->setStatus(Message::STATUS_PENDING);
        $mensaje->setTemplate($plantilla);
        $mensaje->setLanguageCode($hilo->getIdioma()->getId() ?? 'es');
        $mensaje->setVariablesPlantilla(['detalle_pago' => self::detalle($enlace, self::idiomaDePlantilla($hilo))]);

        $hilo->addMessage($mensaje);
        $this->em->persist($mensaje);
        $this->em->flush();
    }

    /**
     * «USD 51.32 + USD 2.82 de comisión de la pasarela de pago»: el neto es lo que verá abonado en
     * su estado de cuenta, y la suma lo que verá en su banco. Así cuadra con los dos (Jorge,
     * 03/10/2026). Sin recargo, sólo el neto: «+ USD 0.00 de comisión» era cierto pero raro.
     *
     * Una sola variable y en el idioma del huésped, porque Meta no traduce variables: la frase va
     * escrita aquí en los siete, como los botones de los comandos de plantillas.
     */
    public static function detalle(FinEnlacePago $enlace, string $idioma): string
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

    /**
     * El idioma en que saldrá la plantilla: el del hilo si es de los que traducimos, y si no
     * inglés. ⚠️ Espejo de la regla de `WhatsappMetaSendMappingStrategy` y
     * `Beds24SendMappingStrategy` (`$templateLang`): si allí cambia, aquí también, o la frase saldría
     * en un idioma distinto del resto del mensaje.
     */
    private static function idiomaDePlantilla(MessageConversation $hilo): string
    {
        $idioma = $hilo->getIdioma();

        return $idioma->getPrioridad() > 0 ? strtolower((string) $idioma->getId()) : 'en';
    }

    private static function conMoneda(FinEnlacePago $enlace, string $monto): string
    {
        return trim(sprintf('%s %s', $enlace->getMonedaCodigo() ?? '', $monto));
    }
}
