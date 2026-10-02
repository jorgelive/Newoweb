<?php

declare(strict_types=1);

namespace App\Message\DispatchHandler;

use App\Message\Command\MessageCrearAvisoFusionCommand;
use App\Message\Dispatch\AvisarFusionSugeridaDispatch;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Aviso\AvisoAlEquipo;
use App\Message\Service\Aviso\AvisoConRespaldo;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Avisa al equipo de que un hilo tiene un teléfono o un correo que ya es de otro.
 *
 * El texto dice lo que hay en juego cuando lo hay: si el hilo tiene avisos programados que no
 * pueden salir (`sin_canal`), los cuenta. Es la diferencia entre «ordenar el chat» y «este huésped
 * no va a recibir su guía de llegada», y el equipo decide antes con lo segundo delante.
 */
#[AsMessageHandler]
final readonly class AvisarFusionSugeridaDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AvisoConRespaldo $avisos,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(AvisarFusionSugeridaDispatch $dispatch): void
    {
        $hilo = $this->em->getRepository(MessageConversation::class)->find($dispatch->conversacionId);

        if (!$hilo instanceof MessageConversation) {
            return;
        }

        // Worker de vida larga: el hilo pudo quedar en caché, y entre el choque y este momento
        // alguien pudo haberlos unido o descartado.
        $this->em->refresh($hilo);
        $sugerida = $hilo->fusionSugerida();

        if ($sugerida === null) {
            return;
        }

        $huesped = $this->unaLinea($hilo->getGuestName() ?: 'Un huésped');
        $otro = $this->unaLinea($sugerida->nombre ?: 'otra persona');
        $dato = $sugerida->tipo === 'telefono' ? 'teléfono +' . $sugerida->valor : 'correo ' . $sugerida->valor;
        $atascados = $this->avisosSinCanal($hilo);

        $texto = sprintf("🔗 %s: su %s ya está en la conversación de %s.\n\n", $huesped, $dato, $otro)
            . ($atascados > 0
                ? sprintf("Mientras no se unan, %s programado%s no puede%s salir.\n\n", $atascados === 1 ? '1 aviso' : $atascados . ' avisos', $atascados === 1 ? '' : 's', $atascados === 1 ? '' : 'n')
                : '')
            . 'Si son la misma persona, ábrelo y pulsa «Unir». Si no, «No es la misma persona».';

        try {
            $this->avisos->notificar(new AvisoAlEquipo(
                rol: Roles::CUSTOMER_SUPPORT,
                texto: $texto,
                plantillaCodigo: MessageCrearAvisoFusionCommand::CODIGO,
                variables: [
                    'huesped' => $huesped,
                    'otro' => $otro,
                    'chat_path' => 'chat?id=' . $dispatch->conversacionId,
                ],
                metadata: [
                    'aviso_fusion_sugerida' => true,
                    'conversacion' => $dispatch->conversacionId,
                    'con' => $sugerida->con,
                ],
            ), titulo: '🔗 ¿Es la misma persona?', url: '/chat?id=' . $dispatch->conversacionId);
        } catch (Throwable $e) {
            // La sugerencia ya está guardada y el banner la enseña: un aviso fallido no rompe nada.
            $this->logger->error('[fusión sugerida] No se pudo avisar al equipo: ' . $e->getMessage());
        }
    }

    private function avisosSinCanal(MessageConversation $hilo): int
    {
        $total = $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Message::class, 'm')
            ->where('m.conversation = :hilo')
            ->andWhere('m.status = :sinCanal')
            ->setParameter('hilo', $hilo)
            ->setParameter('sinCanal', Message::STATUS_SIN_CANAL)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($total) ? (int) $total : 0;
    }

    /** Meta no admite saltos de línea en los parámetros. */
    private function unaLinea(string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }
}
