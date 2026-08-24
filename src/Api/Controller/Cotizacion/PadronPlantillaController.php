<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Service\Padron\PadronPlantillaGenerador;
use App\Cotizacion\Entity\CotizacionFile;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
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
        return $this->comoDescarga($generador->generar(), PadronPlantillaGenerador::NOMBRE_ARCHIVO);
    }

    /**
     * La misma plantilla, **ya rellena con lo que hay cargado**.
     *
     * Es la forma cómoda de completar un padrón a medias: se baja lo que existe, se rellenan los
     * huecos —los vencimientos que faltan, los teléfonos— y se vuelve a subir.
     *
     * ⚠️ Trae la columna `Id`, y ahí está la gracia: al resubir, cada fila vuelve a SU persona
     * aunque le hayan cambiado el nombre y el documento a la vez.
     */
    #[Route(
        '/cotizacion/user/padron/exportar/{id}',
        name: 'cotizacion_padron_exportar',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para exportar padrones.')]
    public function exportar(
        string $id,
        PadronPlantillaGenerador $generador,
        EntityManagerInterface $em,
    ): Response {
        $file = $em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));

        if ($file === null) {
            return new Response('No encontré el expediente.', Response::HTTP_NOT_FOUND);
        }

        $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $file->getNombreGrupo()) ?? 'padron';

        return $this->comoDescarga($generador->exportar($file), sprintf('padron-%s.xlsx', trim($nombre, '-')));
    }

    private function comoDescarga(string $contenido, string $nombreArchivo): Response
    {
        return new Response($contenido, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $nombreArchivo),
            // Se regenera en cada petición a propósito: cachearla reintroduce el problema que
            // resuelve generarla.
            'Cache-Control' => 'no-store',
        ]);
    }
}
