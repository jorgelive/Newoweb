<?php

declare(strict_types=1);

namespace App\Api\Controller\Travel;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Enum\ComponenteItemModoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

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

        $itinerarioId = $relacion->getItinerario()->getId();
        $segmentoId = $relacion->getSegmento()->getId();

        // 1. Catálogo para el Select
        $componentes = $this->em->getRepository(TravelComponente::class)->findBy([], ['nombre' => 'ASC']);
        $cat = array_map(fn($c) => ['id' => $c->getId()->toRfc4122(), 'nombre' => $c->getNombre()], $componentes);

        // 2. Data de la logística ESPECÍFICA de esta plantilla (Editable)
        $logisticaEspecifica = $this->em->getRepository(TravelSegmentoComponente::class)->findBy([
                'itinerarioContexto' => $itinerarioId,
                'segmento'           => $segmentoId
        ], ['orden' => 'ASC']);

        $dataEspecifica = array_map(fn($l) => [
                'componenteId' => $l->getComponente()->getId()->toRfc4122(),
                'hora'         => $l->getHora() ? $l->getHora()->format('H:i') : '',
                'horaFin'      => $l->getHoraFin() ? $l->getHoraFin()->format('H:i') : '',
                'orden'        => $l->getOrden()
        ], $logisticaEspecifica);

        // 3. 🔥 NUEVO: Data de la logística GENERAL del pool (Solo lectura, itinerarioContexto = null)
        $logisticaGeneral = $this->em->getRepository(TravelSegmentoComponente::class)->findBy([
                'itinerarioContexto' => null,
                'segmento'           => $segmentoId
        ], ['orden' => 'ASC']);

        $dataGeneral = array_map(fn($l) => [
                'nombre'  => $l->getComponente()->getNombre(),
                'hora'    => $l->getHora() ? $l->getHora()->format('H:i') : '--:--',
                'horaFin' => $l->getHoraFin() ? $l->getHoraFin()->format('H:i') : '--:--',
                'orden'   => $l->getOrden()
        ], $logisticaGeneral);

        return $this->json([
                'catalogo'    => $cat,
                'data'        => $dataEspecifica,
                'dataGeneral' => $dataGeneral, // Mandamos la base al frontend
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
        $itinerario = $relacion->getItinerario();
        $segmento = $relacion->getSegmento();

        $payload = json_decode($request->getContent(), true);

        // 1. Purgar logística ESPECÍFICA (NO toca los generales porque buscamos por itinerarioContexto)
        $existing = $this->em->getRepository(TravelSegmentoComponente::class)->findBy([
                'itinerarioContexto' => $itinerario->getId(),
                'segmento'           => $segmento->getId()
        ]);

        foreach ($existing as $e) {
            $this->em->remove($e);
        }
        $this->em->flush();

        // 2. Insertar nueva operativa específica
        foreach ($payload as $row) {
            if (empty($row['componenteId'])) {
                continue;
            }

            $uuidObj = Uuid::fromString($row['componenteId']);
            $comp = $this->em->getReference(TravelComponente::class, $uuidObj);

            $nuevaLog = new TravelSegmentoComponente();
            $nuevaLog->setItinerarioContexto($itinerario); // Amarrado estrictamente a la plantilla
            $nuevaLog->setSegmento($segmento);
            $nuevaLog->setComponente($comp);
            $nuevaLog->setOrden((int)$row['orden']);
            $nuevaLog->setModo(ComponenteItemModoEnum::INCLUIDO);

            if (!empty($row['hora'])) {
                $nuevaLog->setHora(new \DateTimeImmutable($row['hora']));
            }
            if (!empty($row['horaFin'])) {
                $nuevaLog->setHoraFin(new \DateTimeImmutable($row['horaFin']));
            }

            $this->em->persist($nuevaLog);
        }

        $this->em->flush();
        return $this->json(['success' => true]);
    }
}