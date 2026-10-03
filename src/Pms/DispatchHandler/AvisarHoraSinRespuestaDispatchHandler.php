<?php

declare(strict_types=1);

namespace App\Pms\DispatchHandler;

use App\Agent\Skill\Pms\EscalarAlEquipoSkill;
use App\Message\Entity\Message;
use App\Message\Service\Aviso\AvisoAlEquipo;
use App\Message\Service\Aviso\AvisoConRespaldo;
use App\Pms\Dispatch\AvisarHoraSinRespuestaDispatch;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Reserva\EstanciasDelBorde;
use App\Pms\Service\Reserva\HoraDeLaEstancia;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Un huésped pidió salir tarde o llegar antes y, 2 horas después, no ha dicho la hora: un aviso.
 *
 * Se descarta si entretanto escribió el huésped —dijo la hora, y entonces `confirmar_hora` ya
 * avisó; o dijo otra cosa, y la lleva el agente— o le contestó alguien del equipo: en los dos
 * casos ya hay quien se ocupa, y otro aviso sería ruido (Jorge: «hay mucho aviso»).
 *
 * Va por la plantilla del escalado, que ya está aprobada y dice lo que pasa: el huésped espera una
 * respuesta del equipo. Ver `PedirHoraActionHandler`.
 */
#[AsMessageHandler]
final readonly class AvisarHoraSinRespuestaDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AvisoConRespaldo $avisos,
        private HoraDeLaEstancia $horas,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(AvisarHoraSinRespuestaDispatch $dispatch): void
    {
        $boton = $this->em->getRepository(Message::class)->find($dispatch->mensajeBotonId);
        $conversacion = $boton instanceof Message ? $boton->getConversation() : null;
        $desde = $boton?->getCreatedAt();

        if ($conversacion === null || $desde === null) {
            return;
        }

        $despues = $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Message::class, 'm')
            ->where('m.conversation = :hilo')
            ->andWhere('m.createdAt > :desde')
            ->andWhere('m.direction = :entrante OR m.senderType = :equipo')
            // ⚠️ El id con su tipo: la entidad a secas se compara como texto contra binary(16) y
            // devuelve cero filas sin error (CLAUDE.md, «Los UUID se guardan en binary(16)»).
            ->setParameter('hilo', $conversacion->getId(), 'uuid')
            ->setParameter('desde', $desde)
            ->setParameter('entrante', Message::DIRECTION_INCOMING)
            ->setParameter('equipo', Message::SENDER_HOST)
            ->getQuery()
            ->getSingleScalarResult();

        if (is_numeric($despues) && (int) $despues > 0) {
            $this->logger->info('Hora sin respuesta: ya escribió el huésped o le contestó el equipo; no se avisa.', ['conversacion' => (string) $conversacion->getId()]);

            return;
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($conversacion->getContextId());
        $estancias = $reserva instanceof PmsReserva ? EstanciasDelBorde::de($reserva->getEventosActivosGuia(), $dispatch->esSalida) : [];

        if ($reserva === null || $estancias === []) {
            return;
        }

        $quien = $this->quien($reserva, $estancias);
        $borde = $dispatch->esSalida ? $estancias[0]->getFin() : $estancias[0]->getInicio();
        $motivo = sprintf(
            '%s (%s del %s, %s) y no dijo a qué hora',
            $dispatch->esSalida ? 'Pidió salir más tarde' : 'Pidió llegar antes',
            $dispatch->esSalida ? 'check-out' : 'check-in',
            $borde?->format('d/m') ?? '',
            $this->horas->limite($estancias[0], $dispatch->esSalida),
        );
        $chat = 'chat?id=' . $conversacion->getId();

        try {
            $this->avisos->notificar(new AvisoAlEquipo(
                rol: Roles::CUSTOMER_SUPPORT,
                texto: sprintf("🕐 %s: %s. Escríbele para saberla.", $quien, mb_strtolower(mb_substr($motivo, 0, 1)) . mb_substr($motivo, 1)),
                plantillaCodigo: EscalarAlEquipoSkill::PLANTILLA_AVISO,
                variables: ['huesped' => $quien, 'motivo' => $motivo, 'chat_path' => $chat],
                metadata: ['aviso_hora_sin_respuesta' => true, 'conversacion' => (string) $conversacion->getId()],
            ), titulo: '🕐 Hora sin respuesta', url: '/' . $chat);
        } catch (Throwable $e) {
            $this->logger->error('[hora sin respuesta] No se pudo avisar: ' . $e->getMessage());
        }
    }

    /**
     * «Franco Mendoza (Casita 6, W2YRVK)»; con varias casitas, todas.
     *
     * @param list<PmsEventoCalendario> $estancias
     */
    private function quien(PmsReserva $reserva, array $estancias): string
    {
        $nombre = trim((string) $reserva->getNombreCliente() . ' ' . (string) $reserva->getApellidoCliente());
        $casitas = array_values(array_unique(array_map(
            static fn (PmsEventoCalendario $e): string => $e->getPmsUnidad()?->getNombre() ?? 'sin casita',
            $estancias
        )));

        return sprintf('%s (%s, %s)', $nombre !== '' ? $nombre : 'Un huésped', implode(' y ', $casitas), (string) $reserva->getLocalizador());
    }
}
