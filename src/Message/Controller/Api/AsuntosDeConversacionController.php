<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly EnlacesDeConversacion $enlaces
    ) {}

    public function __invoke(MessageConversation $data): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_SHOW, null, 'Acceso denegado a las conversaciones.');

        $asuntos = array_map(
            static fn (ConversacionEnlaceInterface $e): array => [
                'negocio'     => $e->getNegocio(),
                'contextType' => $e->getContextType(),
                'contextId'   => $e->getContextId(),
                'etiqueta'    => $e->getEtiqueta(),
                'esTitular'   => $e->esTitular(),
                'origen'      => $e->getOrigen(),
            ],
            $this->enlaces->de($data)
        );

        return new JsonResponse(['asuntos' => $asuntos]);
    }
}
