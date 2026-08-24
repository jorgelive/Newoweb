<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Service\Padron\PadronPlantillaGenerador;
use App\Cotizacion\Entity\CotizacionFile;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
        methods: ['GET', 'POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para exportar padrones.')]
    public function exportar(
        string $id,
        Request $peticion,
        PadronPlantillaGenerador $generador,
        EntityManagerInterface $em,
    ): Response {
        $file = $em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));

        if ($file === null) {
            return new Response('No encontré el expediente.', Response::HTTP_NOT_FOUND);
        }

        // ⚠️ Los ids van en el CUERPO y por eso hay POST, aunque no escriba nada.
        //
        // Son UUID de 36 caracteres: 131 personas son 4 700 caracteres de URL, por encima de lo
        // que aguantan varios proxys —y lo que se corta ahí no da un error, da una exportación a
        // la que le faltan filas—. Y en la URL quedarían además en los logs de acceso.
        //
        // Filtrar en el servidor repitiendo los filtros del panel se descartó: serían dos
        // implementaciones de la misma pregunta, y la que se quedase corta lo haría en silencio.
        // El panel ya sabe exactamente quién cumple; sólo tiene que decirlo.
        $soloEstos = null;
        if ($peticion->isMethod('POST')) {
            /** @var array{ids?: list<string>} $cuerpo */
            $cuerpo = $peticion->toArray();
            $ids = array_values(array_filter(
                array_map(static fn (mixed $v): string => trim((string) $v), $cuerpo['ids'] ?? []),
                static fn (string $v): bool => $v !== '',
            ));

            if ($ids === []) {
                return new Response('No mandaste a nadie que exportar.', Response::HTTP_BAD_REQUEST);
            }

            $soloEstos = $ids;
        }

        $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $file->getNombreGrupo()) ?? 'padron';
        $sufijo = $soloEstos === null ? '' : sprintf('-filtrado-%d', count($soloEstos));

        return $this->comoDescarga(
            $generador->exportar($file, $soloEstos),
            sprintf('padron-%s%s.xlsx', trim($nombre, '-'), $sufijo),
        );
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
