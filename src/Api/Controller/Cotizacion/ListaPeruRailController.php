<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Padron\ListaPeruRail;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Descarga la lista de pasajeros en la plantilla de PeruRail.
 *
 * Mismo par de verbos que la hoja de control y el padrón: `GET` baja el expediente entero (sin los
 * «No participa»), `POST` baja **sólo a los ids del cuerpo**, que son los que el manifiesto tiene
 * filtrados — lo normal, porque al tren no suele ir el grupo entero en el mismo horario.
 *
 * `fecha` (AAAA-MM-DD, en la query del GET o en el cuerpo del POST) es el día del tren: con ella se
 * decide quién es adulto y quién niño. Sin ella, hoy.
 *
 * Con más de {@see ListaPeruRail::MAX_POR_ARCHIVO} personas baja un ZIP con varias partes.
 *
 * ⚠️ `X-PeruRail-Incompletos` lleva cuántas personas tienen huecos (pintados de ámbar en la hoja),
 * y `X-PeruRail-Detalle` la lista en JSON url-codificado, para que el panel avise ANTES de que la
 * hoja se suba a PeruRail. Se expone con `Access-Control-Expose-Headers` por si el panel vive en otro
 * origen.
 *
 * @see \App\Cotizacion\Service\Padron\ListaPeruRail
 */
final class ListaPeruRailController extends AbstractController
{
    #[Route(
        '/cotizacion/user/manifiesto/perurail/{id}',
        name: 'cotizacion_manifiesto_perurail',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para exportar el manifiesto.')]
    public function __invoke(
        string $id,
        Request $peticion,
        ListaPeruRail $lista,
        EntityManagerInterface $em,
    ): Response {
        $file = $em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));

        if ($file === null) {
            return new Response('No encontré el expediente.', Response::HTTP_NOT_FOUND);
        }

        $soloEstos = null;
        $fecha = $peticion->query->getString('fecha');

        if ($peticion->isMethod('POST')) {
            /** @var array{ids?: list<mixed>, fecha?: mixed} $cuerpo */
            $cuerpo = $peticion->toArray();
            $ids = array_values(array_filter(
                array_map(static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '', $cuerpo['ids'] ?? []),
                static fn (string $v): bool => $v !== '',
            ));

            if ($ids === []) {
                return new Response('No mandaste a nadie que exportar.', Response::HTTP_BAD_REQUEST);
            }

            $soloEstos = $ids;
            if (isset($cuerpo['fecha']) && is_string($cuerpo['fecha'])) {
                $fecha = $cuerpo['fecha'];
            }
        }

        $fechaDelTren = null;
        if ($fecha !== '') {
            $fechaDelTren = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
            if ($fechaDelTren === false) {
                return new Response('La fecha del tren va como AAAA-MM-DD.', Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $resultado = $lista->generar($file, $soloEstos, $fechaDelTren);
        } catch (\RuntimeException $e) {
            return new Response($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response($resultado->contenido, Response::HTTP_OK, [
            'Content-Type' => $resultado->esZip
                ? 'application/zip'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $resultado->nombreArchivo),
            'X-PeruRail-Personas' => (string) $resultado->personas,
            'X-PeruRail-Incompletos' => (string) count($resultado->incompletos),
            // Recortado: una cabecera de varios KB la cortan los proxys, y para avisar bastan las primeras.
            'X-PeruRail-Detalle' => rawurlencode((string) json_encode(
                array_slice($resultado->incompletos, 0, 25),
                JSON_UNESCAPED_UNICODE,
            )),
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-PeruRail-Personas, X-PeruRail-Incompletos, X-PeruRail-Detalle',
            'Cache-Control' => 'no-store',
        ]);
    }
}
