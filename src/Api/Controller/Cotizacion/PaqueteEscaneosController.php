<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Padron\PaqueteDeEscaneos;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Descarga el ZIP con los escaneos de identidad del expediente.
 *
 * Mismo par de métodos que la hoja de documentos ({@see ReporteDocumentosController}): `GET` para
 * el expediente entero y `POST` con la lista de ids para respetar los filtros de la pantalla. Los
 * filtros se mandan resueltos, no repetidos, por lo mismo que allí: dos implementaciones de la
 * misma pregunta, y la que se quedase corta lo haría en silencio.
 *
 * ⚠️ **Esto saca de la casa un sobre con los pasaportes de un grupo entero.** Por eso:
 *
 * - Pide `RESERVAS_WRITE`, el mismo permiso que ya guarda el manifiesto. No es un enlace público
 *   ni firmado: quien lo baja tiene sesión y nombre.
 * - `no-store`, para que no se quede en la caché del navegador ni en un proxy.
 * - El ZIP temporal **se borra al terminar de servirlo** (`deleteFileAfterSend`): sin eso queda un
 *   sobre con documentos de identidad en `/tmp`, legible por cualquiera que entre a la máquina.
 *
 * Y NO se le devuelve al pasajero: {@see \App\Cotizacion\Enum\ArchivoTipoEnum::esDevolvibleAlPasajero()}
 * explica por qué el escaneo propio no baja a su móvil. Esto es la vía del operador hacia el
 * alojamiento, que es otra cosa y tiene otro dueño.
 */
final class PaqueteEscaneosController extends AbstractController
{
    #[Route(
        '/cotizacion/user/manifiesto/escaneos/{id}',
        name: 'cotizacion_manifiesto_escaneos',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para descargar los documentos.')]
    public function __invoke(
        string $id,
        Request $peticion,
        PaqueteDeEscaneos $paquete,
        EntityManagerInterface $em,
    ): Response {
        $file = $em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));

        if ($file === null) {
            return new Response('No encontré el expediente.', Response::HTTP_NOT_FOUND);
        }

        $soloEstos = null;
        $soloTipos = null;

        if ($peticion->isMethod('POST')) {
            /** @var array{ids?: list<string>, tipos?: list<string>} $cuerpo */
            $cuerpo = $peticion->toArray();
            $ids = array_values(array_filter(
                array_map(static fn (mixed $v): string => trim((string) $v), $cuerpo['ids'] ?? []),
                static fn (string $v): bool => $v !== '',
            ));

            if ($ids === []) {
                return new Response('No mandaste a nadie que exportar.', Response::HTTP_BAD_REQUEST);
            }

            $soloEstos = $ids;

            // Qué tipos de escaneo entran. Lista vacía o ausente = todos los de identidad.
            // La lista blanca la aplica el servicio, no esto: ver `tiposPedidos()`.
            $tipos = array_values(array_filter(
                array_map(static fn (mixed $v): string => trim((string) $v), $cuerpo['tipos'] ?? []),
                static fn (string $v): bool => $v !== '',
            ));

            $soloTipos = $tipos === [] ? null : $tipos;
        }

        $ruta = $paquete->generar($file, $soloEstos, $soloTipos);

        $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $file->getNombreGrupo()) ?? 'expediente';
        $sufijo = $soloEstos === null ? '' : sprintf('-%d', count($soloEstos));

        $respuesta = new BinaryFileResponse($ruta);
        $respuesta->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('documentos-%s%s.zip', trim($nombre, '-'), $sufijo),
        );
        $respuesta->headers->set('Content-Type', 'application/zip');
        $respuesta->headers->set('Cache-Control', 'no-store');
        $respuesta->deleteFileAfterSend();

        return $respuesta;
    }
}
