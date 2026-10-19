<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Service\Lista\ListaParaProveedorInterface;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Descarga la lista de pasajeros en el formato de un tercero: `perurail`, `cultura`.
 *
 * Mismo par de verbos que la hoja de control (`ReporteDocumentosController`): `GET` el manifiesto
 * entero, `POST` sólo los ids del cuerpo —los que el manifiesto tiene filtrados—, y en el ORDEN en
 * que llegan.
 *
 * Los pasajeros incompletos no paran la descarga: se dicen en la cabecera `X-Avisos` (JSON
 * codificado en URL), que el panel enseña. Una lista de cuarenta no se puede quedar sin bajar porque
 * a uno le falte el sexo.
 */
final class ListaParaProveedorController extends AbstractController
{
    /** @param iterable<ListaParaProveedorInterface> $listas */
    public function __construct(
        #[AutowireIterator('app.lista_para_proveedor')]
        private readonly iterable $listas,
    ) {
    }

    #[Route(
        '/cotizacion/user/manifiesto/lista/{formato}/{id}',
        name: 'cotizacion_manifiesto_lista',
        requirements: ['formato' => '[a-z]+', 'id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para ver el manifiesto.')]
    public function __invoke(string $formato, string $id, Request $peticion, EntityManagerInterface $em): Response
    {
        $lista = null;
        foreach ($this->listas as $candidata) {
            if ($candidata->formato() === $formato) {
                $lista = $candidata;
                break;
            }
        }
        if ($lista === null) {
            return new Response(sprintf('No sé generar la lista «%s».', $formato), Response::HTTP_NOT_FOUND);
        }

        $file = $em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));
        if ($file === null) {
            return new Response('No encontré el expediente.', Response::HTTP_NOT_FOUND);
        }

        $pasajeros = $this->pasajeros($file, $peticion);
        if ($pasajeros === []) {
            return new Response('No hay pasajeros que poner en la lista.', Response::HTTP_BAD_REQUEST);
        }

        $archivo = $lista->generar($file, $pasajeros);

        return new Response($archivo->contenido, Response::HTTP_OK, [
            'Content-Type' => $archivo->tipoMime,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $archivo->nombre),
            'X-Avisos' => rawurlencode((string) json_encode($archivo->avisos, JSON_UNESCAPED_UNICODE)),
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return list<CotizacionFilepasajero> */
    private function pasajeros(CotizacionFile $file, Request $peticion): array
    {
        /** @var list<CotizacionFilepasajero> $todos */
        $todos = array_values($file->getFilepasajeros()->toArray());

        if (!$peticion->isMethod('POST')) {
            usort($todos, static fn (CotizacionFilepasajero $a, CotizacionFilepasajero $b): int =>
                mb_strtolower(sprintf('%s %s', $a->getApellido(), $a->getNombre()))
                <=> mb_strtolower(sprintf('%s %s', $b->getApellido(), $b->getNombre())));

            return $todos;
        }

        /** @var array{ids?: list<mixed>} $cuerpo */
        $cuerpo = $peticion->toArray();
        $porId = [];
        foreach ($todos as $pasajero) {
            $porId[(string) $pasajero->getId()] = $pasajero;
        }

        $elegidos = [];
        foreach ($cuerpo['ids'] ?? [] as $idPasajero) {
            $clave = is_scalar($idPasajero) ? trim((string) $idPasajero) : '';
            if (isset($porId[$clave])) {
                $elegidos[] = $porId[$clave];
            }
        }

        return $elegidos;
    }
}
