<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Padron\ReporteDeDocumentos;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Descarga el .xlsx de «quién ha subido sus documentos».
 *
 * Es el mismo par de verbos que la exportación del padrón, y por el mismo motivo: `GET` baja el
 * expediente entero, `POST` baja **sólo a los que van en el cuerpo**, que son los que el manifiesto
 * tiene filtrados en pantalla.
 *
 * ⚠️ Los ids van en el CUERPO. Son UUID de 36 caracteres: 133 personas son 4 800 caracteres de URL,
 * por encima de lo que aguantan varios proxys —y lo que se corta ahí no da un error, da una hoja a
 * la que le faltan filas—.
 *
 * @see \App\Cotizacion\Service\Padron\ReporteDeDocumentos
 */
final class ReporteDocumentosController extends AbstractController
{
    #[Route(
        '/cotizacion/user/manifiesto/documentos/{id}',
        name: 'cotizacion_manifiesto_documentos',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para ver el manifiesto.')]
    public function __invoke(
        string $id,
        Request $peticion,
        ReporteDeDocumentos $reporte,
        EntityManagerInterface $em,
    ): Response {
        $file = $em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));

        if ($file === null) {
            return new Response('No encontré el expediente.', Response::HTTP_NOT_FOUND);
        }

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

        $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $file->getNombreGrupo()) ?? 'expediente';
        $sufijo = $soloEstos === null ? '' : sprintf('-%d', count($soloEstos));

        return new Response($reporte->generar($file, $soloEstos), Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf(
                'attachment; filename="documentos-%s%s.xlsx"',
                trim($nombre, '-'),
                $sufijo,
            ),
            // Es una foto de un estado que cambia cada vez que alguien sube algo: cachearla es
            // servir una lista de faltantes que ya no lo son.
            'Cache-Control' => 'no-store',
        ]);
    }
}
