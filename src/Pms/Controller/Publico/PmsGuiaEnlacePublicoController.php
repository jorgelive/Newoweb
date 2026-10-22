<?php

declare(strict_types=1);

namespace App\Pms\Controller\Publico;

use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaItemGaleria;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaContexto;
use App\Pms\Guia\PmsGuiaEnlaceDelEquipo;
use App\Pms\Guia\PmsGuiaInterpolador;
use App\Pms\Guia\PmsGuiaThrottle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sirve UN ítem `PorEnlace` de la guía a quien trae un enlace del equipo: `pax` → `/acceso/{token}`.
 *
 * Lo que se valida lo decide `PmsGuiaEnlaceDelEquipo::leer()`; aquí sólo se interpola y se pinta.
 * El contexto lleva lo de la caja de arriba (`conMediosDelEquipo`: código, foto y vídeo) y el
 * acceso es el de la estancia más la ficha (`PmsGuiaAcceso::porEnlace()`): es la única puerta por
 * la que entra la caja del dinero, y la abrió una persona al mandar el enlace. Cualquier otro dato
 * que la ficha escriba sigue la escalera de siempre.
 *
 * Mismo freno que la guía (`PmsGuiaThrottle`) y el mismo 404 para cualquier fallo: un token que no
 * vale no dice por qué.
 *
 * Espejo del front: `pax/src/views/huesped/PaxGuiaEnlaceView.vue`. Ver `docs/PmsGuiaHuesped.md`
 * §7 sexies.
 */
#[Route('/pms/guia-enlace', name: 'pms_guia_enlace_publico_')]
final class PmsGuiaEnlacePublicoController extends AbstractController
{
    public function __construct(
        private readonly PmsGuiaEnlaceDelEquipo $enlaces,
        private readonly PmsGuiaInterpolador $interpolador,
        private readonly PmsGuiaThrottle $throttle,
    ) {
    }

    #[Route('/{token}', name: 'ver', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_.-]{20,400}'])]
    public function ver(string $token): JsonResponse
    {
        $this->throttle->asegurarPresupuesto();

        $abierto = $this->enlaces->leer($token);

        if ($abierto === null) {
            // Sólo cuenta lo que no firmamos: reabrir un enlace propio caducado no es enumerar.
            if (!$this->enlaces->firmaValida($token)) {
                $this->throttle->registrarFallo();
            }

            return $this->json(['error' => 'no_disponible'], Response::HTTP_NOT_FOUND);
        }

        $contexto = PmsGuiaContexto::construir(
            $abierto->evento->getPmsUnidadOrFail(),
            $abierto->evento,
            conMediosDelEquipo: true,
        );
        $acceso = PmsGuiaAcceso::porEnlace(PmsGuiaAcceso::paraEvento($abierto->evento));
        $item = $abierto->item;

        $respuesta = $this->json([
            'titulo' => $this->interpolador->interpolar($item->getTitulo(), $contexto, $acceso),
            'descripcion' => $this->interpolador->interpolar($item->getDescripcion(), $contexto, $acceso),
            'casita' => $abierto->evento->getPmsUnidadOrFail()->getNombre(),
            'galeria' => $this->galeria($item),
            'idioma' => $abierto->reserva->getIdioma()?->getId(),
            'expiraEn' => $abierto->expiraEn->format(\DateTimeInterface::ATOM),
        ]);

        // Lleva un código: que no lo guarde ninguna caché intermedia. (El `Referrer-Policy` que
        // importa es el de la PÁGINA, que es la que lleva el token en la URL: `PaxAppController`.)
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }

    /**
     * Las fotos propias de la ficha (la cartuchera, los llaveros…), en su orden.
     *
     * La foto y el vídeo de la caja son del ESTABLECIMIENTO y entran por marcador; éstas son de la
     * ficha y se suben en su «Fotos» del panel. La página pinta al pie las que el texto no haya
     * insertado ya con `{{ img: … }}`, así que basta con subirlas.
     *
     * @return list<array{imageUrl: string, descripcion: list<array{language?: string, content?: string|null}>}>
     */
    private function galeria(PmsGuiaItem $item): array
    {
        $fotos = $item->getGaleria()->toArray();
        usort($fotos, static fn (PmsGuiaItemGaleria $a, PmsGuiaItemGaleria $b): int => $a->getOrden() <=> $b->getOrden());

        $salida = [];
        foreach ($fotos as $foto) {
            $url = $foto->getImageUrl();

            if ($url !== null && $url !== '') {
                $salida[] = ['imageUrl' => $url, 'descripcion' => $foto->getDescripcion()];
            }
        }

        return $salida;
    }
}
