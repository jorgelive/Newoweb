<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Los ASUNTOS que cuelgan de un hilo: sus reservas, sus expedientes de viaje.
 *
 * ── Por qué hace falta en el panel ──────────────────────────────────────────
 * Desde que los hilos se fusionan por persona, una conversación puede llevar varias reservas
 * —16 hilos ya lo hacen— y en cuanto Travel entre, reservas y viajes a la vez. El chat no tiene
 * forma de decir a cuál va un mensaje, y de ahí cuelgan dos cosas que hoy no funcionan:
 *
 * 1. El corte de canales por asunto (`MessageDispatcher::acotarAlAsunto()`) no se aplica: sin
 *    asunto usa la unión del hilo, que no acota nada.
 * 2. Un mensaje del viaje puede salir marcado Beds24 y aterrizar en el hilo de la reserva.
 *
 * ── Qué se devuelve, y qué no ───────────────────────────────────────────────
 * La etiqueta la redacta el DOMINIO ({@see ConversacionEnlaceInterface::getEtiqueta()}), que es
 * quien sabe qué se puede enseñar: la casita y las fechas sí, el localizador y el saldo no. El
 * núcleo la transporta sin leerla.
 *
 * `esTitular` viaja porque el panel tiene que poder distinguir el hilo que ATIENDE el asunto del
 * de un acompañante — al segundo se le contesta, pero no se le programa nada.
 */
#[AsController]
final class AsuntosDeConversacionController extends AbstractController
{
    public function __construct(
        private readonly EnlacesDeConversacion $enlaces,
        private readonly EntityManagerInterface $em,
    ) {}

    /** GET — los asuntos del hilo. */
    public function listar(MessageConversation $data): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_SHOW, null, 'Acceso denegado a las conversaciones.');

        return new JsonResponse(['asuntos' => $this->comoLista($data)]);
    }

    /** @return list<array{negocio: string, contextType: string, contextId: string, etiqueta: string, esTitular: bool, origen: string|null}> */
    private function comoLista(MessageConversation $conversacion): array
    {
        return array_map(
            static fn (ConversacionEnlaceInterface $e): array => [
                'negocio'     => $e->getNegocio(),
                'contextType' => $e->getContextType(),
                'contextId'   => $e->getContextId(),
                'etiqueta'    => $e->getEtiqueta(),
                'esTitular'   => $e->esTitular(),
                'origen'      => $e->getOrigen(),
            ],
            $this->enlaces->de($conversacion)
        );
    }

    /**
     * PATCH — mueve el papel de TITULAR de un asunto a este hilo.
     *
     * Es la decisión de «a quién de los dos se le programan los envíos» cuando una reserva la
     * atienden dos personas desde números distintos. Al otro se le sigue contestando; lo que no
     * recibe es la agenda automática.
     */
    public function cambiarTitular(MessageConversation $data, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_WRITE, null, 'No tienes permiso para editar la conversación.');

        /** @var array<string, mixed> $cuerpo */
        $cuerpo = json_decode($request->getContent() ?: '{}', true) ?: [];
        $tipo = trim((string) ($cuerpo['contextType'] ?? ''));
        $id   = trim((string) ($cuerpo['contextId'] ?? ''));

        if ($tipo === '' || $id === '') {
            return new JsonResponse(['error' => 'Falta el asunto.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->enlaces->cambiarTitular($data, $tipo, $id)) {
            // No se inventa el enlace: un titular sin enlace sería un asunto atendido desde una
            // conversación que no lo tiene colgado.
            return new JsonResponse(['error' => 'Ese asunto no cuelga de esta conversación.'], Response::HTTP_NOT_FOUND);
        }

        $this->em->flush();

        return new JsonResponse(['asuntos' => $this->comoLista($data)]);
    }
}
