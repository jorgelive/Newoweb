<?php

declare(strict_types=1);

namespace App\Api\Controller\Oweb;

use App\Oweb\Entity\ServicioServicio;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador de API para la entidad Servicio.
 * Optimizado para búsquedas asíncronas con Select2 y prevención de Session Locking.
 */
#[Route('/servicio/servicio')]
class ServicioServicioController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private PaginatorInterface $paginator;

    /**
     * Inyección de servicios mediante el constructor para aprovechar el tipado de PHP 8.4.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        PaginatorInterface $paginator
    ) {
        $this->entityManager = $entityManager;
        $this->paginator = $paginator;
    }

    /**
     * Endpoint para Select2 que lista todos los servicios con soporte de búsqueda y paginación.
     * * @param Request $request
     * @return Response
     */
    #[Route('/alldropdown', name: 'api_oweb_servicio_servicio_alldropdown')]
    public function alldropdownAction(Request $request): Response
    {
        // LIBERACIÓN DE SESIÓN: Evita que múltiples búsquedas de Select2 bloqueen el subdominio.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $qb = $this->entityManager->getRepository(ServicioServicio::class)->createQueryBuilder('s');

        $queryTerm = $request->query->get('q');
        if (!empty($queryTerm)) {
            $qb->where('s.nombre like :cadena')
                ->setParameter('cadena', '%' . $queryTerm . '%');
        }

        $qb->orderBy('s.nombre', 'ASC');

        // REFIX PHP 8.4: Obtenemos enteros estrictos para evitar TypeError en el paginador.
        $page = $request->query->getInt('_page', 1);
        $limit = $request->query->getInt('_per_page', 10);

        $pagination = $this->paginator->paginate(
            $qb->getQuery(),
            $page,
            $limit
        );

        if (!$pagination->getItems()) {
            return new JsonResponse([
                'status' => 'OK',
                'items' => [],
                'more' => false,
                'message' => 'No existe contenido.'
            ], Response::HTTP_OK);
        }

        $resultado = [];
        foreach ($pagination->getItems() as $item) {
            /** @var ServicioServicio $item */
            $resultado[] = [
                'id' => $item->getId(),
                'label' => $item->getNombre(),
            ];
        }

        $totalItems = $pagination->getTotalItemCount();
        $maxItems = $page * $limit;

        return new JsonResponse([
            'status' => 'OK',
            'more' => ($maxItems < $totalItems),
            'items' => $resultado
        ], Response::HTTP_OK);
    }

    /**
     * Retorna información técnica de un servicio específico vía AJAX.
     * * @param Request $request
     * @param mixed $id
     * @return Response
     */
    #[Route('/ajaxinfo/{id}', name: 'api_oweb_servicio_servicio_ajaxinfo', defaults: ['id' => null])]
    public function ajaxinfoAction(Request $request, $id): Response
    {
        // Liberación de sesión preventiva.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $servicio = $this->entityManager
            ->getRepository(ServicioServicio::class)
            ->find($id);

        if (!$servicio) {
            return new JsonResponse([], Response::HTTP_NO_CONTENT);
        }

        $content = [
            'id' => $servicio->getId(),
            'paralelo' => $servicio->isParalelo(),
        ];

        return new JsonResponse($content, Response::HTTP_OK);
    }
}