<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\FusionadorDeHilos;
use App\Security\Roles;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Une dos hilos desde el panel.
 *
 * 🔥 **La fusión era la salida que el propio sistema recomienda por escrito** —«si son la misma
 * persona, únelas con `app:message:fusionar-hilos`»— y sólo existía en la consola. Quien se topa
 * con el choque es un operador en una pantalla, así que en la práctica esa salida no existía: se
 * acababa borrando un hilo o dejando el número atascado para siempre.
 *
 * ── Dos pasos, y el primero no escribe ──────────────────────────────────────
 * `GET …/fusion/previa` dice qué se va a unir —cuántos mensajes, cuántos asuntos, quién
 * sobrevive—; `POST …/fusion` lo aplica. Fusionar **no se deshace**: los mensajes quedan en una
 * sola línea de tiempo y no hay forma de saber cuál venía de dónde.
 *
 * ⚠️ **Quién sobrevive lo decide la ANTIGÜEDAD, no quien pulsa.** El hilo más viejo es el que
 * tiene el historial largo y los enlaces que ya funcionan; absorberlo en el nuevo movería más
 * cosas y rompería más referencias. Dejar elegir invitaría a acertar por casualidad.
 */
#[AsController]
#[Route('/platform/message/conversations', name: 'app_message_fusion_')]
final class FusionarHilosController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly FusionadorDeHilos $fusionador,
    ) {
    }

    #[Route('/{id}/fusion/previa', name: 'previa', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function previa(string $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_WRITE, null, 'Acceso denegado a las conversaciones.');

        [$superviviente, $absorbido, $error] = $this->parejaDe($id, (string) $request->query->get('con', ''));

        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'superviviente' => $this->ficha($superviviente),
            'absorbido' => $this->ficha($absorbido),
        ]);
    }

    #[Route('/{id}/fusion', name: 'aplicar', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function aplicar(string $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_WRITE, null, 'Acceso denegado a las conversaciones.');

        /** @var array<string, mixed> $cuerpo */
        $cuerpo = json_decode($request->getContent(), true) ?: [];

        [$superviviente, $absorbido, $error] = $this->parejaDe($id, (string) ($cuerpo['con'] ?? ''));

        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $movidos = $this->fusionador->unir($superviviente, $absorbido);

        return $this->json([
            'movidos' => $movidos,
            'supervivienteId' => (string) $superviviente->getId(),
        ]);
    }

    /**
     * Los dos hilos, ya ordenados: el más VIEJO sobrevive.
     *
     * @return array{0: MessageConversation, 1: MessageConversation, 2: null}|array{0: null, 1: null, 2: string}
     */
    private function parejaDe(string $unId, string $otroId): array
    {
        if (!Uuid::isValid($unId) || !Uuid::isValid($otroId)) {
            return [null, null, 'Falta alguna de las dos conversaciones.'];
        }

        if ($unId === $otroId) {
            return [null, null, 'Son la misma conversación.'];
        }

        $repo = $this->em->getRepository(MessageConversation::class);
        $uno = $repo->find(Uuid::fromString($unId));
        $otro = $repo->find(Uuid::fromString($otroId));

        if ($uno === null || $otro === null) {
            return [null, null, 'No encuentro alguna de las dos conversaciones.'];
        }

        // ⚠️ Los hilos de `staff` quedan fuera, y no es una precaución: `EscalarAlEquipoSkill`
        // busca los avisos recientes filtrando por `contextType = 'staff'`. Al fusionar, esos
        // mensajes dejarían de encontrarse y el enfriamiento fallaría ABIERTO — la guardia
        // volvería a sonar entera, de noche y sin causa evidente.
        foreach ([$uno, $otro] as $hilo) {
            if ($hilo->getContextType() === 'staff') {
                return [null, null, 'Uno de los hilos es del equipo y ésos no se fusionan todavía: rompería el enfriamiento de la guardia.'];
            }
        }

        $viejo = ($uno->getCreatedAt()?->getTimestamp() ?? 0) <= ($otro->getCreatedAt()?->getTimestamp() ?? 0) ? $uno : $otro;
        $nuevo = $viejo === $uno ? $otro : $uno;

        return [$viejo, $nuevo, null];
    }

    /** @return array{id: string, nombre: string|null, mensajes: int, asuntos: int, desde: string|null} */
    private function ficha(MessageConversation $hilo): array
    {
        $id = (string) $hilo->getId();

        return [
            'id' => $id,
            'nombre' => $hilo->getGuestName(),
            'mensajes' => (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM msg_message WHERE conversation_id = UNHEX(REPLACE(?, \'-\', \'\'))',
                [$id],
            ),
            'asuntos' => (int) $this->db->fetchOne(
                'SELECT (SELECT COUNT(*) FROM pms_conversacion_enlace WHERE conversacion_id = UNHEX(REPLACE(?, \'-\', \'\')))
                      + (SELECT COUNT(*) FROM cotizacion_conversacion_enlace WHERE conversacion_id = UNHEX(REPLACE(?, \'-\', \'\')))',
                [$id, $id],
            ),
            'desde' => $hilo->getCreatedAt()?->format('Y-m-d'),
        ];
    }
}
