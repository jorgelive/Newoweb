<?php

declare(strict_types=1);

namespace App\Agent\Controller\Api;

use App\Dto\Lee;
use App\Agent\Service\MotorFueraDeServicioException;
use App\Agent\Service\PanelAssistant;
use App\Agent\Service\SondeoDeMotores;
use App\Agent\Access\AgentActorFactory;
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
        private readonly AgentActorFactory $actores,
        private readonly LoggerInterface $logger,
        private readonly SondeoDeMotores $sondeo,
    ) {}

    /**
     * Proveedores y modelos que el operador puede elegir.
     *
     * Se sirve desde el backend en vez de fijarlo en el front porque la lista depende de qué
     * credenciales tiene ESTE entorno: en local suele haber sólo uno.
     */
    #[Route('/motores', name: 'app_agent_motores', methods: ['GET'])]
    public function motores(): JsonResponse
    {
        return $this->json(['motores' => $this->asistente->catalogoDeMotores()]);
    }

    /**
     * El botón «comprobar» del desplegable: pregunta a cada motor si responde, reactiva los que
     * vuelven (tras recargar créditos, sin esperar a la siguiente media hora del cron) y devuelve el
     * catálogo al día. Una generación mínima por motor. Ver `SondeoDeMotores`.
     */
    #[Route('/motores/comprobar', name: 'app_agent_motores_comprobar', methods: ['POST'])]
    public function comprobarMotores(): JsonResponse
    {
        $resultado = $this->sondeo->sondear();

        return $this->json([
            'motores' => $this->asistente->catalogoDeMotores(),
            'responden' => array_keys(array_filter($resultado, static fn (array $r): bool => $r['fallo'] === null)),
        ]);
    }

    #[Route('/consulta', name: 'app_agent_consulta', methods: ['POST'])]
    public function consulta(Request $request): JsonResponse
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
        $pregunta = is_array($payload) ? (Lee::texto($payload['pregunta'] ?? null) ?? '') : '';

        // El hilo lo mantiene el cliente y viaja en cada petición: así el endpoint no guarda
        // estado y el operador ve exactamente el mismo contexto que el modelo.
        // ⚠️ Llega del navegador, así que se normaliza a la forma del contrato en vez de
        // pasarlo tal cual: un turno sin `rol` o sin `texto` se descarta. Antes entraba entero
        // y el motor lo recorría confiando en que las claves estuvieran.
        $historial = [];

        if (is_array($payload) && is_array($payload['historial'] ?? null)) {
            foreach ($payload['historial'] as $turno) {
                if (!is_array($turno) || !is_string($turno['rol'] ?? null) || !is_string($turno['texto'] ?? null)) {
                    continue;
                }

                $historial[] = ['rol' => $turno['rol'], 'texto' => $turno['texto']];
            }
        }

        // Proveedor y modelo llegan por petición para poder comparar dos motores sobre la
        // misma pregunta sin desplegar. Vacío = los de por defecto del entorno. Ambos se
        // validan contra la lista blanca del registro: esto gasta dinero de verdad.
        $proveedor = is_array($payload) && is_string($payload['proveedor'] ?? null)
            ? $payload['proveedor']
            : null;
        $modelo = is_array($payload) && is_string($payload['modelo'] ?? null)
            ? $payload['modelo']
            : null;

        try {
            // El actor lleva los roles EFECTIVOS del usuario —literales más los heredados por
            // la jerarquía de security.yaml—: el registro decide con ellos qué herramientas se
            // le ofrecen al modelo. Limpieza y administración no ven lo mismo. Va por la
            // factoría y no por `AgentActor::delPanel()` a secas porque ese atajo se queda en
            // los roles literales, y entonces quien tiene ROLE_RESERVAS_DELETE no puede usar
            // una skill que pida ROLE_RESERVAS_WRITE, aunque en el panel sí pueda hacerlo.
            $resultado = $this->asistente->preguntar(
                $pregunta,
                $this->actores->delPanel($usuario),
                $historial,
                $proveedor,
                $modelo
            );
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (MotorFueraDeServicioException $e) {
            // El front lee `motorFueraDeServicio` para volver a pedir el catálogo: el motor ya sale
            // deshabilitado y el operador no repite la pregunta contra el mismo.
            $this->logger->error('Asistente del panel: ' . $e->getMessage(), ['exception' => $e->getPrevious()]);

            return $this->json(
                ['error' => $e->getMessage() . ' Elige otro o pulsa «comprobar» tras recargarlo.', 'motorFueraDeServicio' => true],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE
            );
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
