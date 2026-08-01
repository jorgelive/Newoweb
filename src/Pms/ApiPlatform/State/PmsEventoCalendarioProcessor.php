<?php

declare(strict_types=1);

namespace App\Pms\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * Processor de escritura para PmsEventoCalendario (POST/PATCH desde la API — app util/Vue).
 *
 * La integridad OTA (fechas/unidad/estado inmutables, auto-confirmación por pago,
 * recálculo de totales de la reserva) ya está blindada a nivel de Doctrine por
 * PmsEventoCalendarioSecurityListener, PmsEventoCalendarioIntegrityListener y
 * PmsReservaRecalculoListener — corre para cualquier entrypoint, no solo EasyAdmin.
 *
 * Lo único que SÍ es responsabilidad exclusiva de este processor es reconstruir
 * los links espejo de Beds24 (PmsEventoCalendarioFactory::hydrateLinksForUi), ya
 * que esa lógica no vive en ningún listener y solo la invocaban los controllers
 * de EasyAdmin.
 */
final class PmsEventoCalendarioProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PmsEventoCalendarioFactory $eventoFactory,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PmsEventoCalendario
    {
        if (!$data instanceof PmsEventoCalendario) {
            throw new LogicException('PmsEventoCalendarioProcessor solo procesa instancias de PmsEventoCalendario.');
        }

        if ($operation instanceof Post) {
            // Evento nuevo (bloqueo manual creado desde el calendario SPA):
            // este recurso solo puede crear eventos DIRECTOS. Se ignora cualquier
            // `channel` que venga en el payload y se fuerza "directo" — el frontend
            // no debe (ni puede) crear eventos OTA desde aquí bajo ninguna circunstancia.
            $data->setChannel($this->entityManager->getReference(PmsChannel::class, PmsChannel::CODIGO_DIRECTO));

            // Construye los links espejo de Beds24 para la unidad asignada.
            $this->eventoFactory->hydrateLinksForUi($data);
            $this->entityManager->persist($data);
        } else {
            $uow = $this->entityManager->getUnitOfWork();
            $meta = $this->entityManager->getClassMetadata(PmsEventoCalendario::class);

            $uow->computeChangeSet($meta, $data);
            $changes = $uow->getEntityChangeSet($data);

            // Si se movió de unidad física, reconstruye los links espejo de la nueva unidad.
            // (Un evento OTA con pmsUnidad modificado nunca llega vivo hasta aquí:
            // PmsEventoCalendarioSecurityListener lo rechaza antes del flush.)
            if (array_key_exists('pmsUnidad', $changes)) {
                $this->eventoFactory->hydrateLinksForUi($data);
            }
        }

        $this->entityManager->flush();

        return $data;
    }
}
