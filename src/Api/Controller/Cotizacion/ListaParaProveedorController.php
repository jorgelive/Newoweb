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
            'X-Avisos' => self::avisosParaCabecera($archivo->avisos),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Los avisos que caben en una cabecera, y si no caben todos, «y N más».
     *
     * ⚠️ Iban enteros, y en URL una tilde ocupa nueve bytes. En un grupo de 133 al que le falte un
     * dato común —el sexo, el código del Ministerio de un país— eran muchos KB de cabecera, y nginx
     * corta la respuesta cuando pasan de `fastcgi_buffer_size` (4–8 KB por defecto): 502 y sin
     * fichero, justo en la lista que más hace falta (revisión del 19/10/2026). Y son datos
     * personales: una cabecera acaba en proxies y logs con más facilidad que un cuerpo.
     *
     * @param list<string> $avisos
     */
    private static function avisosParaCabecera(array $avisos): string
    {
        $codificar = static fn (array $lista): string => rawurlencode((string) json_encode($lista, JSON_UNESCAPED_UNICODE));
        $caben = [];

        foreach ($avisos as $i => $aviso) {
            $resto = \count($avisos) - $i - 1;
            $prueba = [...$caben, $aviso];
            if ($resto > 0) {
                $prueba[] = sprintf('y %d aviso(s) más: revisa los datos del manifiesto.', $resto);
            }

            if (\strlen($codificar($prueba)) > self::BYTES_DE_AVISOS) {
                $caben[] = sprintf('y %d aviso(s) más: revisa los datos del manifiesto.', \count($avisos) - $i);

                return $codificar($caben);
            }

            $caben[] = $aviso;
        }

        return $codificar($caben);
    }

    /** Muy por debajo de los 4 KB del búfer de cabeceras de nginx por defecto, que comparten todas. */
    private const int BYTES_DE_AVISOS = 2000;

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
