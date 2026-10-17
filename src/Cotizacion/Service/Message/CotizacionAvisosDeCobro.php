<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Message;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionConversacionEnlace;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Finanzas\CotizacionEnlaceService;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Service\Aviso\DetalleDeCobro;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Los dos avisos al cliente de un cobro de turismo por WhatsApp (16/10/2026), gemelos de los de
 * alojamiento (`PagoRecibido`, `SaldoPendiente`):
 *
 * - **«Hemos recibido tu pago»** al confirmarse un cobro con tarjeta. Reutiliza `pago_recibido`:
 *   su texto no habla de alojamiento y su botón admite la ruta del expediente. Lo llama
 *   `CotizacionOrigenCobroResolver::confirmarAlCliente()`, una vez por enlace.
 * - **«Ya puedes pagar el saldo»** media hora después de pagado el adelanto, con su plantilla
 *   propia `saldo_pendiente_viaje` —la de alojamiento dice «tu llegada» y «tu guía»—. Lo llama
 *   `app:cotizacion:saldo-tras-adelanto`, una vez por expediente.
 *
 * Los dos son HECHOS, no fechas: no son reglas del motor. Van al hilo TITULAR del expediente, con el
 * expediente estampado como asunto (las variables las pone `CotizacionMessageDataResolver`). Sin
 * plantilla o sin hilo no se manda nada: queda en el log, y el cobro ya está hecho.
 */
final readonly class CotizacionAvisosDeCobro
{
    public const string PLANTILLA_PAGO = 'pago_recibido';
    public const string PLANTILLA_SALDO = 'saldo_pendiente_viaje';

    public function __construct(
        private EntityManagerInterface $em,
        private EnlacesDeConversacion $enlaces,
        private CotizacionEnlaceService $enlacesDeCotizacion,
        private LoggerInterface $logger,
    ) {
    }

    public function pagoRecibido(CotizacionFile $file, FinEnlacePago $enlace): void
    {
        $preparado = $this->preparar($file, self::PLANTILLA_PAGO);

        if ($preparado === null) {
            return;
        }

        [$plantilla, $hilo] = $preparado;
        $this->dejarEnCola($file, $plantilla, $hilo, [
            'detalle_pago' => DetalleDeCobro::de($enlace, $hilo->getIdioma()->idiomaDePlantilla()),
        ]);
    }

    /**
     * Avisa del saldo, si hay un enlace del sistema vivo por él y no se avisó antes.
     *
     * @return bool `true` si se dejó el mensaje en cola.
     */
    public function saldoPendiente(Cotizacion $cotizacion): bool
    {
        $file = $cotizacion->getFile();

        // El enlace vivo del sistema. Sin él no hay nada que avisar: el mensaje mandaría a pagar algo
        // que no existe. En tramos, la suma de los vivos.
        $vivos = array_values(array_filter(
            $this->enlacesDeCotizacion->pagables($cotizacion),
            static fn (FinEnlacePago $e): bool => $e->getCreadoPor() === null,
        ));

        if ($file === null || $vivos === []) {
            return false;
        }

        $preparado = $this->preparar($file, self::PLANTILLA_SALDO);

        if ($preparado === null) {
            return false;
        }

        [$plantilla, $hilo] = $preparado;

        // Una sola vez por expediente: si el saldo cambia, el enlace se releva, pero el aviso ya se
        // dio y su botón lleva a la portada del expediente, que enseña siempre el enlace vivo.
        $yaAvisado = $this->em->getRepository(Message::class)->count([
            'template' => $plantilla,
            'asuntoType' => CotizacionConversacionEnlace::CONTEXT_TYPE,
            'asuntoId' => (string) $file->getId(),
        ]);

        if ($yaAvisado > 0) {
            return false;
        }

        // El NETO: con transferencia no hay comisión, y la de la tarjeta se ve al pagar.
        $neto = array_sum(array_map(static fn (FinEnlacePago $e): float => (float) $e->getMontoNeto(), $vivos));
        $this->dejarEnCola($file, $plantilla, $hilo, [
            'importe_saldo' => trim(sprintf('%s %s', $vivos[0]->getMonedaCodigo() ?? '', number_format($neto, 2, '.', ''))),
        ]);

        return true;
    }

    /** @return array{0: MessageTemplate, 1: MessageConversation}|null */
    private function preparar(CotizacionFile $file, string $codigo): ?array
    {
        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $codigo]);
        $hilo = $this->enlaces->hiloTitularDe(CotizacionConversacionEnlace::CONTEXT_TYPE, (string) $file->getId());

        if ($plantilla === null || $hilo === null) {
            $this->logger->warning('[cotizacion] cobro sin aviso al cliente', [
                'expediente' => (string) $file->getLocalizador(),
                'plantilla' => $codigo,
                'motivo' => $plantilla === null ? 'sin plantilla' : 'sin conversación',
            ]);

            return null;
        }

        return [$plantilla, $hilo];
    }

    /** @param array<string, string> $variables */
    private function dejarEnCola(CotizacionFile $file, MessageTemplate $plantilla, MessageConversation $hilo, array $variables): void
    {
        $mensaje = new Message();
        $mensaje->setConversation($hilo);
        // El expediente estampado: en un hilo con varios asuntos, sin esto las variables saldrían
        // del contexto de la conversación, que puede ser otro.
        $mensaje->setAsunto(CotizacionConversacionEnlace::CONTEXT_TYPE, (string) $file->getId());
        $mensaje->setDirection(Message::DIRECTION_OUTGOING);
        // Sistema y no anfitrión: no lo escribe nadie, y no debe callar al agente.
        $mensaje->setSenderType(Message::SENDER_SYSTEM);
        $mensaje->setStatus(Message::STATUS_PENDING);
        $mensaje->setTemplate($plantilla);
        $mensaje->setLanguageCode($hilo->getIdioma()->getId() ?? 'es');
        $mensaje->setVariablesPlantilla($variables);

        $hilo->addMessage($mensaje);
        $this->em->persist($mensaje);
        $this->em->flush();
    }
}
