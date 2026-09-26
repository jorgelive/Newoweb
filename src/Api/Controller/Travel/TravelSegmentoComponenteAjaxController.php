<?php

declare(strict_types=1);

namespace App\Api\Controller\Travel;

use App\Travel\Dto\FilaDeLogistica;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/travel/user/travel-segmento-componente', name: 'travel_user_segmento_componente')]
class TravelSegmentoComponenteAjaxController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Recupera el catálogo, la logística específica y la logística GENERAL (solo lectura).
     *
     * @param string $relId El ID (UUID) de la relación TravelItinerarioSegmentoRel.
     * @return JsonResponse Retorna un objeto JSON con catálogo, data específica y data general.
     */
    #[Route('/{relId}', name: '_get', methods: ['GET'])]
    public function getComponentes(string $relId): JsonResponse
    {
        $relacion = $this->em->find(TravelItinerarioSegmentoRel::class, $relId);
        if (!$relacion) {
            return $this->json(['error' => 'No encontrado'], 404);
        }

        $itinerarioId = $relacion->getItinerarioOrFail()->getId();
        $segmentoId = $relacion->getSegmentoOrFail()->getId();

        // 1. Catálogo para el Select
        $componentes = $this->em->getRepository(TravelComponente::class)->findBy([], ['nombreInterno' => 'ASC']);
        $cat = array_map(function($c) {
            $tarifas = array_map(fn($t) => [
                'id' => $t->getIdOrFail()->toRfc4122(),
                'nombre' => $t->getNombreInterno() . ' (' . ($t->getMoneda() ? $t->getMoneda()->getId() : '') . ' ' . $t->getMonto() . ')'
            ], $c->getTarifas()->toArray());

            return [
                'id' => $c->getIdOrFail()->toRfc4122(),
                'nombre' => $c->getNombreInterno(),
                'tarifas' => $tarifas
            ];
        }, $componentes);

        // 2. Data de la logística ESPECÍFICA (itinerarioContexto = actual)
        $logisticaEspecifica = $this->em->getRepository(TravelSegmentoComponente::class)->findBy([
            'itinerarioContexto' => $itinerarioId,
            'segmento'           => $segmentoId
        ], ['orden' => 'ASC']);

        // 🔥 Se mapea el día a la vista
        $dataEspecifica = array_map(fn($l) => [
            'componenteId'     => $l->getComponenteOrFail()->getIdOrFail()->toRfc4122(),
            'tarifaId'         => $l->getTarifaPredeterminada()?->getIdOrFail()->toRfc4122(),
            'dia'              => $l->getDia(), // <-- Inyectamos el filtro de día relativo
            'hora'             => $l->getHora() ? $l->getHora()->format('H:i') : '',
            'horaFin'          => $l->getHoraFin() ? $l->getHoraFin()->format('H:i') : '',
            'modo'             => $l->getModo()->value,
            'orden'            => $l->getOrden(),
            'servicioCompleto' => $l->isHoraServicioCompleto(),
        ], $logisticaEspecifica);

        // 3. Data de la logística GENERAL del pool (Solo lectura, itinerarioContexto = null)
        $logisticaGeneral = $this->em->getRepository(TravelSegmentoComponente::class)->findBy([
            'itinerarioContexto' => null,
            'segmento'           => $segmentoId
        ], ['orden' => 'ASC']);

        // 🔥 Se mapea el día a la vista
        $dataGeneral = array_map(fn($l) => [
            'nombre'       => $l->getComponenteOrFail()->getNombreInterno(),
            'tarifaNombre' => $l->getTarifaPredeterminada() ? $l->getTarifaPredeterminada()->getNombreInterno() : 'Auto / Varias',
            'dia'          => $l->getDia(), // <-- Inyectamos el filtro de día relativo
            'hora'         => $l->getHora() ? $l->getHora()->format('H:i') : '--:--',
            'horaFin'      => $l->getHoraFin() ? $l->getHoraFin()->format('H:i') : '--:--',
            'modo'         => $l->getModo()->value,
            'orden'        => $l->getOrden()
        ], $logisticaGeneral);

        return $this->json([
            'catalogo'    => $cat,
            'data'        => $dataEspecifica,
            'dataGeneral' => $dataGeneral,
            'contexto'    => ['itinerario' => $itinerarioId, 'segmento' => $segmentoId]
        ]);
    }

    /**
     * Procesa y guarda la configuración logística ESPECÍFICA para un párrafo de un itinerario.
     * Solo afecta a los registros amarrados a este itinerarioContexto.
     *
     * @param Request $request El objeto de la petición HTTP que contiene el payload JSON.
     * @param string $relId El ID (UUID) de la relación.
     * @return JsonResponse Retorna un JSON confirmando el éxito.
     */
    #[Route('/{relId}', name: '_post', methods: ['POST'])]
    public function saveComponentes(Request $request, string $relId): JsonResponse
    {
        $relacion = $this->em->find(TravelItinerarioSegmentoRel::class, $relId);
        // El GET ya devolvía 404; el POST no, y un id que no existe acababa en un 500 con
        // «Call to a member function getItinerario() on null».
        if (!$relacion) {
            return $this->json(['error' => 'No encontrado'], 404);
        }

        $itinerario = $relacion->getItinerarioOrFail();
        $segmento = $relacion->getSegmentoOrFail();

        // Se lee y se valida TODO antes de borrar nada: ver `FilaDeLogistica`.
        try {
            $filas = FilaDeLogistica::lista(json_decode($request->getContent(), true));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }

        // 1. Purgar logística ESPECÍFICA (NO toca los generales porque buscamos por itinerarioContexto)
        $existing = $this->em->getRepository(TravelSegmentoComponente::class)->findBy([
            'itinerarioContexto' => $itinerario->getId(),
            'segmento'           => $segmento->getId()
        ]);

        foreach ($existing as $e) {
            $this->em->remove($e);
        }
        $this->em->flush();

        // 2. Insertar nueva operativa específica. La unicidad de la hora de
        // "servicio completo" (una por plantilla y día) la garantiza al flush el
        // listener TravelSegmentoComponentePromocionUnicaListener, así que aquí
        // sólo persistimos el flag tal cual viene por fila.
        foreach ($filas as $fila) {
            $comp = $this->em->getReference(TravelComponente::class, $fila->componenteId);

            $nuevaLog = new TravelSegmentoComponente();
            $nuevaLog->setItinerarioContexto($itinerario);
            $nuevaLog->setSegmento($segmento);
            $nuevaLog->setComponente($comp);
            $nuevaLog->setOrden($fila->orden);
            $nuevaLog->setModo($fila->modo);
            $nuevaLog->setDia($fila->dia);

            // Guardar tarifa predeterminada apuntando correctamente a TravelTarifa
            if ($fila->tarifaId !== null) {
                $nuevaLog->setTarifaPredeterminada($this->em->getReference(TravelTarifa::class, $fila->tarifaId));
            }

            if ($fila->hora !== null) $nuevaLog->setHora($fila->hora);
            if ($fila->horaFin !== null) $nuevaLog->setHoraFin($fila->horaFin);

            $nuevaLog->setHoraServicioCompleto($fila->servicioCompleto);

            $this->em->persist($nuevaLog);
        }

        $this->em->flush();
        return $this->json(['success' => true]);
    }
}