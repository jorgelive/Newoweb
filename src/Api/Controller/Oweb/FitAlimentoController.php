<?php

declare(strict_types=1);

namespace App\Api\Controller\Oweb;

use App\Oweb\Entity\FitAlimento;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador de API para la gestión de Alimentos (Módulo Fitness).
 * Proporciona información nutricional rápida vía AJAX.
 */
#[Route('/fit/alimento')]
class FitAlimentoController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    /**
     * Inyección de dependencias mediante constructor para PHP 8.4.
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Retorna la información nutricional de un alimento específico.
     * * @param Request $request
     * @param mixed $id ID del alimento.
     * @return Response JSON con macros y detalles de medida.
     */
    #[Route('/ajaxinfo/{id}', name: 'api_oweb_fit_alimento_ajaxinfo', defaults: ['id' => null])]
    public function ajaxinfoAction(Request $request, $id): Response
    {
        // LIBERACIÓN DE SESIÓN: Evita el bloqueo de la interfaz si se consultan varios alimentos rápido.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $alimento = $this->entityManager
            ->getRepository(FitAlimento::class)
            ->find($id);

        if (!$alimento) {
            return new JsonResponse([], Response::HTTP_NO_CONTENT);
        }

        // Construcción del contenido con validación de relaciones existentes
        $content = [
            'id' => $alimento->getId(),
            'grasa' => $alimento->getGrasa(),
            'carbohidrato' => $alimento->getCarbohidrato(),
            'proteina' => $alimento->getProteina(),
            'medidaalimento' => $alimento->getMedidaalimento() ? $alimento->getMedidaalimento()->getNombre() : null,
            'cantidad' => $alimento->getCantidad(),
            'proteinaaltovalor' => $alimento->isProteinaaltovalor(),
            'tipoalimento' => $alimento->getTipoalimento() ? $alimento->getTipoalimento()->getNombre() : null,
        ];

        return new JsonResponse($content, Response::HTTP_OK);
    }
}