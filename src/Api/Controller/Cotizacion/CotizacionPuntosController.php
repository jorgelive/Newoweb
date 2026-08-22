<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Service\CotizacionPuntosDelServicio;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * De dónde a dónde va cada servicio de una cotización.
 *
 * Va por su propio endpoint y no como campo de la cotización porque **no es parte del
 * documento**: es una lectura derivada del catálogo que se refresca sola cuando alguien corrige
 * un segmento. Meterlo en `cotizacion:read` lo habría metido también en el `PUT` de vuelta y en
 * la vista del cliente, que no tiene nada que hacer con esto.
 *
 * `RESERVAS_SHOW` — el mismo permiso con el que se lee la cotización. Un endpoint auxiliar con
 * el listón más bajo que el recurso del que deriva es una fuga por la puerta de atrás.
 */
#[Route('/cotizacion/user/puntos', name: 'cotizacion_user_puntos')]
#[IsGranted(Roles::RESERVAS_SHOW)]
class CotizacionPuntosController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CotizacionPuntosDelServicio $puntos,
    ) {}

    #[Route('/{cotizacionId}', name: '_get', methods: ['GET'])]
    public function puntos(string $cotizacionId): JsonResponse
    {
        // Un id que no es UUID revienta en la conversión de DBAL —500, no 404—, así que se
        // comprueba antes. Un 500 por una URL mal escrita ensucia el log de errores y esconde
        // los fallos de verdad.
        $cotizacion = Uuid::isValid($cotizacionId)
            ? $this->em->find(Cotizacion::class, $cotizacionId)
            : null;

        if ($cotizacion === null) {
            return $this->json(['error' => 'Cotización no encontrada'], 404);
        }

        return $this->json(['servicios' => $this->puntos->paraCotizacion($cotizacion)]);
    }
}
