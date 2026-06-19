<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Travel\Enum\ComponenteTipoEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador AJAX para exponer metadatos de Enums al frontend.
 * Se agrupa bajo el prefijo 'user' para heredar las reglas del firewall.
 */
#[Route('/cotizacion/user/maestros-enum', name: 'cotizacion_user_maestros_enum')]
class MaestrosEnumAjaxController extends AbstractController
{
    /**
     * Expone las reglas de negocio de los tipos de componentes (si requieren hora, prioridad, etc).
     * Este endpoint es consumido por Pinia para mantener una "Single Source of Truth".
     *
     * @return JsonResponse Retorna el diccionario de tipos de componentes.
     */
    #[Route('/componente-tipos', name: '_componente_tipos', methods: ['GET'])]
    public function getComponenteTipos(): JsonResponse
    {
        $data = [];

        foreach (ComponenteTipoEnum::cases() as $case) {
            $data[] = [
                'id' => $case->value,
                'requiereHoraExacta' => $case->requiereHoraExacta(),
                'prioridad' => $case->prioridad(),
            ];
        }

        // Cacheamos la respuesta por 1 hora en el navegador ya que la estructura del Enum rara vez cambia
        $response = new JsonResponse($data);
        $response->setSharedMaxAge(3600);

        return $response;
    }
}