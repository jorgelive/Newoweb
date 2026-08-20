<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\CanalesDisponibles;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Qué canales ofrecerle al operador en este hilo.
 *
 * Sustituye a la regla copiada a mano en `ChatView.vue` — ver {@see CanalesDisponibles} para el
 * porqué. Se pregunta al abrir un chat, una vez por conversación: ponerlo como campo serializado
 * habría metido un N+1 en un listado de 300 y pico hilos para ahorrar una petición por clic.
 */
#[AsController]
final class CanalesDeConversacionController extends AbstractController
{
    public function __construct(
        private readonly CanalesDisponibles $canales
    ) {}

    /**
     * Cuerpo: `{"canales": [{"id", "nombre", "disponible", "motivo"}]}`. La forma exacta y el
     * significado de los códigos de motivo están en {@see CanalesDisponibles::para()}.
     */
    public function __invoke(MessageConversation $data, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_SHOW, null, 'Acceso denegado a las conversaciones.');

        // El asunto es opcional: mientras el chat no diga a cuál va el mensaje, se juzga con la
        // unión de los del hilo, que no acota de más. Ver `EnlacesDeConversacion`.
        $tipo = trim((string) $request->query->get('asuntoType', ''));
        $id   = trim((string) $request->query->get('asuntoId', ''));

        return new JsonResponse([
            'canales' => $this->canales->para($data, $tipo !== '' ? $tipo : null, $id !== '' ? $id : null),
        ]);
    }
}
