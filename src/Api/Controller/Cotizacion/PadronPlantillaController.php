<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Service\Padron\PadronPlantillaGenerador;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Descarga la plantilla del padrón.
 *
 * Se genera al vuelo desde {@see PadronPlantillaGenerador}, no es un archivo guardado: las columnas
 * salen de los enums, así que un tipo de documento o un eje nuevo aparecen en la plantilla el mismo
 * día que en el código. Una plantilla desactualizada es peor que ninguna — la rellenan igual y el
 * dato se pierde al importar.
 */
final class PadronPlantillaController extends AbstractController
{
    #[Route(
        '/cotizacion/user/padron/plantilla',
        name: 'cotizacion_padron_plantilla',
        methods: ['GET'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para importar padrones.')]
    public function __invoke(PadronPlantillaGenerador $generador): Response
    {
        return new Response($generador->generar(), Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf('attachment; filename="%s"', PadronPlantillaGenerador::NOMBRE_ARCHIVO),
            // Se regenera en cada petición a propósito: cachearla reintroduce el problema que
            // resuelve generarla.
            'Cache-Control' => 'no-store',
        ]);
    }
}
