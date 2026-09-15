<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionVuelo;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Qué tramos vuela **una** reserva aérea.
 *
 * ## Por qué un endpoint y no el JSON de carga
 *
 * 🔥 El JSON de `VuelosCargaController` existe para lo que manda la aerolínea: «estos 24
 * localizadores vuelan estos tramos», de una vez. Es la herramienta correcta para **montar** el
 * expediente.
 *
 * Pero el caso que lo rompía es el contrario y es corriente: a alguien lo sacan de su PNR y se le
 * abre uno nuevo para él solo. Eso es **un código y los mismos tramos de siempre**, y obligaba a
 * reescribir el JSON entero —o a inventárselo— para declarar un vínculo que se dice en dos
 * palabras. Quien tiene que hacer eso a las once de la noche acaba no haciéndolo, y entonces el
 * pasajero abre su app y no ve ningún vuelo.
 *
 * ## Por qué desde la RESERVA y no desde el vuelo
 *
 * ⚠️ Lo dice {@see VueloEditarController}: el formulario del vuelo corrige el HECHO —a qué hora
 * sale— y no a quién le pasa. El vínculo es de la reserva, y declararlo también desde el vuelo
 * daría dos sitios para cambiar lo mismo. Por eso esto cuelga del subgrupo.
 *
 * ## Por qué UUID y no IRI
 *
 * ⚠️ `CotizacionVuelo` **no es un `ApiResource`**: no tiene IRI que denormalizar, así que exponer
 * la colección en un grupo de escritura habría sido una promesa que API Platform no puede cumplir.
 * Aquí se habla en UUID, que es lo que la pantalla ya tiene.
 *
 * ## Reemplaza, no acumula
 *
 * Se manda la lista COMPLETA de tramos de esa reserva y el endpoint la deja así. Un «añadir» y un
 * «quitar» por separado obligarían a la pantalla a llevar la cuenta de lo que cambió, y con
 * casillas eso es justo lo que no se quiere: lo marcado ES el estado.
 */
final class GrupoVuelosController extends AbstractController
{
    #[Route(
        '/cotizacion/user/grupos/{id}/vuelos',
        name: 'cotizacion_grupo_vuelos',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['PATCH'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para editar subgrupos.')]
    public function __invoke(string $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $grupo = $em->getRepository(CotizacionFileGrupo::class)->find(Uuid::fromString($id));

        if ($grupo === null) {
            return $this->json(['error' => 'No encontré ese subgrupo.'], Response::HTTP_NOT_FOUND);
        }

        // ⚠️ Sólo una reserva aérea tiene tramos. Una habitación con vuelos colgando sería un dato
        // que nada sabe leer, y la pantalla del pasajero lo pintaría como si volara su cuarto.
        if ($grupo->getTipo() !== GrupoTipoEnum::RESERVA_AEREA) {
            return $this->json(
                ['error' => 'Sólo una reserva aérea puede tener tramos.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        /** @var array<string, mixed> $datos */
        $datos = json_decode($request->getContent(), true) ?: [];
        $pedidos = is_array($datos['vuelos'] ?? null) ? $datos['vuelos'] : null;

        if ($pedidos === null) {
            return $this->json(['error' => 'Falta la lista de vuelos.'], Response::HTTP_BAD_REQUEST);
        }

        $nuevos = [];

        foreach ($pedidos as $uuid) {
            if (!is_string($uuid) || !Uuid::isValid($uuid)) {
                return $this->json(['error' => 'Hay un identificador de vuelo que no lo es.'], Response::HTTP_BAD_REQUEST);
            }

            $vuelo = $em->getRepository(CotizacionVuelo::class)->find(Uuid::fromString($uuid));

            // 🔥 **Y que sea de ESTE expediente.** Sin esto, un id copiado de otro expediente
            // enlazaría el vuelo de otro grupo de viaje: el pasajero vería en su app un tramo que
            // no es suyo, con hora y puerta, y no hay nada que después lo delate.
            if ($vuelo === null || $vuelo->getFile()?->getId()?->equals($grupo->getFile()?->getId() ?? Uuid::v4()) !== true) {
                return $this->json(
                    ['error' => 'Uno de los vuelos no es de este expediente.'],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $nuevos[$uuid] = $vuelo;
        }

        // Se quitan los que ya no están y se añaden los que faltan: el resultado es exactamente la
        // lista recibida, y los que no cambian ni se tocan.
        foreach ($grupo->getVuelos()->toArray() as $actual) {
            if (!isset($nuevos[(string) $actual->getId()])) {
                $grupo->removeVuelo($actual);
            }
        }

        foreach ($nuevos as $vuelo) {
            $grupo->addVuelo($vuelo);
        }

        $em->flush();

        return $this->json(['ok' => true, 'vuelos' => count($nuevos)]);
    }
}
