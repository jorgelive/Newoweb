<?php

declare(strict_types=1);

namespace App\Api\Controller\Oweb;

use App\Oweb\Entity\ServicioTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador de API para la gestión de Tarifas de Servicio.
 * Optimizado para evitar Session Locking y asegurar compatibilidad con PHP 8.4.
 */
#[Route('/servicio/tarifa')]
class ServicioTarifaController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private PaginatorInterface $paginator;

    /**
     * Inyección de dependencias vía constructor.
     */
    public function __construct(
            EntityManagerInterface $entityManager,
            PaginatorInterface $paginator
    ) {
        $this->entityManager = $entityManager;
        $this->paginator = $paginator;
    }

    /**
     * Retorna el detalle completo de una tarifa vía AJAX.
     * * @param Request $request
     * @param mixed $id
     * @return Response
     */
    #[Route('/ajaxinfo/{id}', name: 'api_oweb_servicio_tarifa_ajaxinfo', defaults: ['id' => null])]
    public function ajaxinfoAction(Request $request, $id): Response
    {
        // LIBERACIÓN DE SESIÓN: Evita bloqueos concurrentes.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $tarifa = $this->entityManager
                ->getRepository(ServicioTarifa::class)
                ->find($id);

        if (!$tarifa) {
            return new JsonResponse([], Response::HTTP_NO_CONTENT);
        }

        $content = [
                'id' => $tarifa->getId(),
                'moneda' => $tarifa->getMoneda() ? $tarifa->getMoneda()->getId() : null,
                'providerId' => $tarifa->getProvider() ? $tarifa->getProvider()->getId() : null,
                'providerName' => $tarifa->getProvider() ? $tarifa->getProvider()->getNombre() : null,
                'monto' => $tarifa->getMonto(),
                'prorrateado' => $tarifa->isProrrateado(),
                'capacidadmin' => $tarifa->getCapacidadmin(),
                'capacidadmax' => $tarifa->getCapacidadmax(),
                'tipotarifa' => $tarifa->getTipotarifa() ? $tarifa->getTipotarifa()->getId() : null,
        ];

        return new JsonResponse($content, Response::HTTP_OK);
    }

    /**
     * Endpoint para Select2 que filtra tarifas por componente.
     * * @param Request $request
     * @param mixed $componente
     * @return Response
     */
    #[Route('/porcomponentedropdown/{componente}', name: 'api_oweb_servicio_tarifa_porcomponentedropdown', defaults: ['componente' => null])]
    public function porcomponentedropdownAction(Request $request, $componente): Response
    {
        // Liberación de sesión inmediata para mejorar la respuesta del Select2.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $qb = $this->entityManager
                ->getRepository(ServicioTarifa::class)
                ->createQueryBuilder('t');

        if ($componente !== null && $componente != 0) {
            $qb->where('t.componente = :componente')
                    ->setParameter('componente', $componente);
        }

        $queryTerm = $request->query->get('q');
        if (!empty($queryTerm)) {
            $qb->andWhere('t.nombre like :cadena')
                    ->setParameter('cadena', '%' . $queryTerm . '%');
        }

        $qb->orderBy('t.nombre', 'ASC');

        // REFIX PHP 8.4: Forzamos obtención de enteros para evitar TypeError en KnpPaginator.
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
            /** @var ServicioTarifa $item */
            $resultado[] = [
                    'id' => $item->getId(),
                    'label' => $item->__toString(),
                    'costo' => sprintf('%s %s',
                            $item->getMoneda() ? $item->getMoneda()->getCodigo() : '',
                            $item->getMonto()
                    ),
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
}