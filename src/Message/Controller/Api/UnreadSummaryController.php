<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Service\NoLeidos\ResumenNoLeidosService;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Resumen global de mensajes sin leer.
 *
 * ¿Por qué existe?: el badge del icono y los contadores del portal se calculaban
 * recorriendo la lista de conversaciones que el chat tenía cargada en memoria, y
 * esa lista está PAGINADA. Una conversación con no leídos que quedara fuera de la
 * primera página —por ejemplo una de hace tres meses— sencillamente no se contaba:
 * el número dependía de cuánto scroll hubiera hecho el operador, no de los datos.
 *
 * El cálculo vive en `ResumenNoLeidosService` porque el mismo total lo necesita el
 * listener que despacha los push. Ver docs/PwaNotificaciones.md.
 */
#[AsController]
final class UnreadSummaryController extends AbstractController
{
    public function __construct(
        private readonly ResumenNoLeidosService $resumen
    ) {}

    public function __invoke(): JsonResponse
    {
        // El permiso se comprueba AQUÍ, no solo en el atributo `security` de la
        // operación. Con `read: false` API Platform no ejecuta la etapa que evalúa
        // ese atributo, y el endpoint estuvo devolviendo nombres de huéspedes y
        // contadores sin sesión mientras la colección normal sí redirigía a login.
        // Esta línea no depende del cableado de etapas del framework.
        $this->denyAccessUnlessGranted(Roles::MENSAJES_SHOW, null, 'Acceso denegado a las conversaciones.');

        return new JsonResponse($this->resumen->resumen());
    }
}
