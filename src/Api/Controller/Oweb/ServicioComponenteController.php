<?php

declare(strict_types=1);

namespace App\Api\Controller\Oweb;

use App\Oweb\Entity\ServicioComponente;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador de API para la gestión de Componentes de Servicio.
 * Diseñado para alimentar componentes Select2 con alta concurrencia y soporte para PHP 8.4.
 */
#[Route('/servicio/componente')]
class ServicioComponenteController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private PaginatorInterface $paginator;

    /**
     * Inyección de dependencias recomendada para Symfony 6.4 / 7+ y PHP 8.4.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        PaginatorInterface $paginator
    ) {
        $this->entityManager = $entityManager;
        $this->paginator = $paginator;
    }

    /**
     * Endpoint para Select2 que filtra componentes asociados a un servicio específico.
     * * @param Request $request
     * @param mixed $servicio ID del servicio padre.
     */
    #[Route('/porserviciodropdown/{servicio}', name: 'api_oweb_servicio_componente_porserviciodropdown', defaults: ['servicio' => null])]
    public function porserviciodownAction(Request $request, $servicio): Response
    {
        // LIBERACIÓN DE SESIÓN: Evita el bloqueo de peticiones concurrentes del Select2.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $qb = $this->entityManager->getRepository(ServicioComponente::class)->createQueryBuilder('c');

        if ($servicio !== null && $servicio != 0) {
            $qb->select('c')
                ->innerJoin('c.servicios', 's')
                ->where('s.id = :servicio')
                ->setParameter('servicio', $servicio);
        }

        $queryTerm = $request->query->get('q');
        if (!empty($queryTerm)) {
            $qb->andWhere('c.nombre like :cadena')
                ->setParameter('cadena', '%' . $queryTerm . '%');
        }

        $qb->orderBy('c.nombre', 'ASC');

        // REFIX PHP 8.4: Forzamos obtención de enteros para evitar TypeError en el paginador.
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
            /** @var ServicioComponente $item */
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
     * Retorna información técnica de un componente vía AJAX.
     * * @param Request $request
     * @param mixed $id
     */
    #[Route('/ajaxinfo/{id}', name: 'api_oweb_servicio_componente_ajaxinfo', defaults: ['id' => null])]
    public function ajaxinfoAction(Request $request, $id): Response
    {
        // Liberación de sesión preventiva.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $componente = $this->entityManager
            ->getRepository(ServicioComponente::class)
            ->find($id);

        if (!$componente) {
            return new JsonResponse([], Response::HTTP_NO_CONTENT);
        }

        $content = [
            'id' => $componente->getId(),
            'duracion' => $componente->getDuracion(),
            'dependeduracion' => $componente->getTipocomponente() ? $componente->getTipocomponente()->isDependeduracion() : false,
        ];

        return new JsonResponse($content, Response::HTTP_OK);
    }
}