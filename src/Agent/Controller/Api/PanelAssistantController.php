<?php

declare(strict_types=1);

namespace App\Agent\Controller\Api;

use App\Agent\Service\PanelAssistant;
use App\Agent\Tool\AgentActor;
use App\Entity\User;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Endpoint del asistente interno del panel.
 *
 * `ROLE_USER` explícito además del `access_control` del host de util: la regla del firewall
 * protege por host, y este endpoint gasta dinero en cada llamada — conviene que su requisito
 * de acceso se lea en el propio archivo y no dependa de dónde acabe montado.
 *
 * Ver docs/Mensajeria.md §11.
 */
#[Route('/agent')]
#[IsGranted('ROLE_USER')]
final class PanelAssistantController extends AbstractController
{
    public function __construct(
        private readonly PanelAssistant $asistente,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/consulta', name: 'app_agent_consulta', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->asistente->estaDisponible()) {
            return $this->json(
                ['error' => 'El asistente no está configurado en este entorno.'],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            return $this->json(['error' => 'Sesión no válida.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        $pregunta = is_array($payload) ? (string) ($payload['pregunta'] ?? '') : '';

        try {
            // El actor lleva los roles del usuario: el registro decide con ellos qué
            // herramientas se le ofrecen al modelo. Limpieza y administración no ven lo mismo.
            $resultado = $this->asistente->preguntar($pregunta, AgentActor::delPanel($usuario));
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (Throwable $e) {
            // El mensaje del proveedor puede llevar detalles de la petición: se registra, no
            // se devuelve.
            $this->logger->error('Asistente del panel: ' . $e->getMessage(), ['exception' => $e]);

            return $this->json(
                ['error' => 'El asistente no está disponible ahora mismo.'],
                JsonResponse::HTTP_BAD_GATEWAY
            );
        }

        return $this->json($resultado);
    }
}
