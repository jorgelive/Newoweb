<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Entity\Message;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use App\Pms\Finanzas\PmsPrepagoEnlaceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * El aviso al huésped de que ya puede pagar el SALDO, tras pagar el adelanto (10/10/2026).
 *
 * Lo llama `app:pms:prepago:saldo-tras-adelanto` cuando el enlace del saldo ya existe, media hora
 * después de que el adelanto se pagara por enlace. Como `PagoRecibido`, es un HECHO y no una
 * fecha: no es una regla del motor.
 *
 * ⚠️ **Una sola vez por reserva.** Si el saldo cambia —un cargo extra— el enlace se releva, pero
 * el aviso ya se dio: el huésped tiene el botón a su guía, que siempre enseña el enlace vivo.
 *
 * Sin horario de silencio: el huésped puede estar en cualquier país, y acaba de pagar hace media
 * hora, así que estaba despierto.
 */
final readonly class SaldoPendiente
{
    public const string PLANTILLA = 'saldo_pendiente';

    public function __construct(
        private EntityManagerInterface $em,
        private EnlacesDeConversacion $enlaces,
        private PmsPrepagoEnlaceService $prepago,
        private LoggerInterface $logger,
    ) {}

    /** @return bool `true` si se dejó el mensaje en cola. */
    public function avisar(PmsReserva $reserva): bool
    {
        $id = (string) $reserva->getId();

        // El enlace vivo del sistema. Si no lo hay —se pagó, se anuló, no quedaba saldo—, no hay
        // nada que avisar: el mensaje mandaría a pagar algo que no existe.
        $vivo = null;
        foreach ($this->prepago->pagables($reserva) as $enlace) {
            if ($enlace->getCreadoPor() === null) {
                $vivo = $enlace;
                break;
            }
        }

        if ($vivo === null) {
            return false;
        }

        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::PLANTILLA]);
        $hilo = $this->enlaces->hiloTitularDe(PmsConversacionEnlace::CONTEXT_TYPE, $id);

        if ($plantilla === null || $hilo === null) {
            $this->logger->warning('[pms] saldo tras el adelanto sin aviso al huésped', [
                'reserva' => $id,
                'motivo' => $plantilla === null ? 'sin plantilla ' . self::PLANTILLA : 'sin conversación',
            ]);

            return false;
        }

        $yaAvisado = $this->em->getRepository(Message::class)->count([
            'template' => $plantilla,
            'asuntoType' => PmsConversacionEnlace::CONTEXT_TYPE,
            'asuntoId' => $id,
        ]);

        if ($yaAvisado > 0) {
            return false;
        }

        $mensaje = new Message();
        $mensaje->setConversation($hilo);
        // Estampada, como en `PagoRecibido`: en un hilo con varias reservas, el botón a la guía
        // tiene que llevar a ésta.
        $mensaje->setAsunto(PmsConversacionEnlace::CONTEXT_TYPE, $id);
        $mensaje->setDirection(Message::DIRECTION_OUTGOING);
        $mensaje->setSenderType(Message::SENDER_SYSTEM);
        $mensaje->setStatus(Message::STATUS_PENDING);
        $mensaje->setTemplate($plantilla);
        $mensaje->setLanguageCode($hilo->getIdioma()->getId() ?? 'es');
        // El NETO del enlace: con transferencia no hay comisión, y la de la tarjeta se ve al pagar.
        $mensaje->setVariablesPlantilla([
            'importe_saldo' => trim(sprintf('%s %s', $vivo->getMonedaCodigo() ?? '', $vivo->getMontoNeto())),
        ]);

        $hilo->addMessage($mensaje);
        $this->em->persist($mensaje);
        $this->em->flush();

        return true;
    }
}
