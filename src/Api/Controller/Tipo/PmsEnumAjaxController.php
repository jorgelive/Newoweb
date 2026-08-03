<?php

declare(strict_types=1);

namespace App\Api\Controller\Tipo;

use App\Pms\Enum\PmsMedioPago;
use App\Pms\Enum\PmsTipoCargo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador AJAX para exponer metadatos de los Enums del PMS al frontend.
 * Espejo de TravelEnumAjaxController: se agrupa bajo el prefijo 'user' para
 * heredar las reglas del firewall.
 *
 * Objetivo: que las etiquetas, colores e iconos de los enums vivan SOLO en PHP.
 * El frontend (util/) los consume desde aquí en vez de duplicar diccionarios en
 * TypeScript, que era la fuente habitual de desincronización.
 */
#[Route('/tipo/user/enum/pms', name: 'tipo_user_enum_pms')]
class PmsEnumAjaxController extends AbstractController
{
    /**
     * Tipos de cargo financiero (alojamiento, limpieza, servicio, otro).
     * Consumido por el panel financiero de la reserva en la SPA.
     */
    #[Route('/tipos-cargo', name: '_tipos_cargo', methods: ['GET'])]
    public function getTiposCargo(): JsonResponse
    {
        $data = [];

        foreach (PmsTipoCargo::cases() as $case) {
            $data[] = [
                'id' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ];
        }

        return $this->cacheable($data);
    }

    /**
     * Medios de pago admitidos, con el % de comisión por defecto de cada uno
     * (5.5% en tarjeta de crédito, 0 en el resto).
     */
    #[Route('/medios-pago', name: '_medios_pago', methods: ['GET'])]
    public function getMediosPago(): JsonResponse
    {
        $data = [];

        foreach (PmsMedioPago::cases() as $case) {
            $data[] = [
                'id' => $case->value,
                'label' => $case->label(),
                'icono' => $case->icono(),
                'comisionPorcentaje' => $case->comisionPorcentaje(),
            ];
        }

        return $this->cacheable($data);
    }

    /**
     * Cachea 1 hora en el navegador: la estructura de un Enum rara vez cambia
     * (mismo criterio que TravelEnumAjaxController).
     */
    private function cacheable(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->setSharedMaxAge(3600);

        return $response;
    }
}
