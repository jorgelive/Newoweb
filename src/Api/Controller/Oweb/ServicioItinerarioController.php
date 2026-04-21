<?php

declare(strict_types=1);

namespace App\Api\Controller\Oweb;

use App\Oweb\Entity\ServicioItinerario;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador de API para la gestión de Itinerarios.
 * Proporciona endpoints para componentes de búsqueda (dropdowns) y consultas AJAX.
 */
#[Route('/servicio/itinerario')]
class ServicioItinerarioController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private PaginatorInterface $paginator;

    /**
     * Inyección de dependencias moderna para evitar el uso del contenedor como service locator.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        PaginatorInterface $paginator
    ) {
        $this->entityManager = $entityManager;
        $this->paginator = $paginator;
    }

    /**
     * Retorna información básica de un itinerario en formato JSON.
     * * @param Request $request
     * @param mixed $id ID del itinerario (puede ser null por el default de la ruta).
     */
    #[Route('/ajaxinfo/{id}', name: 'api_oweb_servicio_itinerario_ajaxinfo', defaults: ['id' => null])]
    public function ajaxinfoAction(Request $request, $id): Response
    {
        // LIBERACIÓN DE SESIÓN: Evita el Session Locking en peticiones AJAX concurrentes.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $itinerario = $this->entityManager
            ->getRepository(ServicioItinerario::class)
            ->find($id);

        if (!$itinerario) {
            return new JsonResponse([], Response::HTTP_NO_CONTENT);
        }

        $content = [
            'id' => $itinerario->getId(),
            'hora' => $itinerario->getHora() ? $itinerario->getHora()->format('H:i') : null,
            'duracion' => $itinerario->getDuracion(),
        ];

        return new JsonResponse($content, Response::HTTP_OK);
    }

    /**
     * Endpoint para poblar dropdowns de búsqueda con soporte de paginación y filtros.
     * * @param Request $request
     * @param mixed $servicio ID del servicio para filtrar.
     */
    #[Route('/porserviciodropdown/{servicio}', name: 'api_oweb_servicio_itinerario_porserviciodropdown', defaults: ['servicio' => null])]
    public function porserviciodropdownAction(Request $request, $servicio): Response
    {
        // LIBERACIÓN DE SESIÓN: Vital aquí ya que los dropdowns de búsqueda suelen lanzar muchas peticiones.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $qb = $this->entityManager
            ->getRepository(ServicioItinerario::class)
            ->createQueryBuilder('i');

        if ($servicio !== null && $servicio != 0) {
            $qb->where('i.servicio = :servicio')
                ->setParameter('servicio', $servicio);
        }

        $queryTerm = $request->query->get('q');
        if (!empty($queryTerm)) {
            $qb->andWhere('i.nombre like :cadena')
                ->setParameter('cadena', '%' . $queryTerm . '%');
        }

        $qb->orderBy('i.nombre', 'ASC');

        // REFIX PHP 8.4: Forzamos obtención de enteros para el paginador evitando el TypeError.
        $page = $request->query->getInt('_page', 1);
        $limit = $request->query->getInt('_per_page', 10);

        $pagination = $this->paginator->paginate(
            $qb->getQuery(),
            $page,
            $limit
        );

        $resultado = [];
        foreach ($pagination->getItems() as $item) {
            /** @var ServicioItinerario $item */
            $resultado[] = [
                'id' => $item->getId(),
                'label' => $item->getNombre(),
            ];
        }

        $totalItems = $pagination->getTotalItemCount();
        $maxItems = $page * $limit;

        $content = [
            'status' => 'OK',
            'more' => ($maxItems < $totalItems),
            'items' => $resultado
        ];

        return new JsonResponse($content, Response::HTTP_OK);
    }
}