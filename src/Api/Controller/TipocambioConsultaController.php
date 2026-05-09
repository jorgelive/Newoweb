<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Entity\Maestro\MaestroTipocambio;
use App\Service\TipocambioManager;
use DateTime;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

#[AsController]
class TipocambioConsultaController extends AbstractController
{
    /**
     * Controlador para consulta de tipo de cambio vía POST.
     * Delegamos la lógica de obtención (API SUNAT o BD) al TipocambioManager.
     */
    public function __invoke(Request $request, TipocambioManager $tcManager): JsonResponse
    {
        // 1. Extraer datos del payload JSON
        $data = json_decode($request->getContent(), true) ?? [];
        $fechaStr = $data['fecha'] ?? 'now';

        // 2. Normalizar fecha a zona horaria operativa (Cusco/Lima)
        try {
            $fecha = new DateTime($fechaStr, new DateTimeZone('America/Lima'));
        } catch (\Exception $e) {
            throw new BadRequestHttpException('El formato de fecha es inválido. Utilice YYYY-MM-DD.');
        }

        // 3. Ejecutar lógica de negocio a través del Service
        $tipocambio = $tcManager->getTipodecambio($fecha);

        if (!$tipocambio instanceof MaestroTipocambio) {
            throw new ServiceUnavailableHttpException(
                null,
                'No se pudo sincronizar el tipo de cambio con SUNAT ni encontrar un histórico válido en la base de datos.'
            );
        }

        // 4. Retornamos un JsonResponse nativo y explícito (Rápido y sin conflictos)
        return $this->json([
            'fecha'  => $tipocambio->getFecha()?->format('Y-m-d'),
            'compra' => $tipocambio->getCompra(),
            'venta'  => $tipocambio->getVenta(),
            'moneda' => $tipocambio->getMoneda()?->getId()
        ]);
    }
}