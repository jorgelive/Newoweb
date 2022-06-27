<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;

#[Route('/fit/alimento')]
class FitAlimentoController extends AbstractController
{

    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'knp_paginator' => PaginatorInterface::class
            ] + parent::getSubscribedServices();
    }

    #[Route('/ajaxinfo/{id}', name: 'app_fit_alimento_ajaxinfo', defaults: ['id' => null])]
    public function ajaxinfoAction(Request $request, $id): Response
    {

        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $alimento = $em
            ->getRepository('App\Entity\FitAlimento')
            ->find($id);

        if(!$alimento){
            $content = [];
            $status = Response::HTTP_NO_CONTENT;
            return $this->makeresponse($content, $status);
        }

        $content['id'] = $alimento->getId();
        $content['grasa'] = $alimento->getGrasa();
        $content['carbohidrato'] = $alimento->getCarbohidrato();
        $content['proteina'] = $alimento->getProteina();
        $content['medidaalimento'] = $alimento->getMedidaalimento() ? $alimento->getMedidaalimento()->getNombre() : null;
        $content['cantidad'] = $alimento->getCantidad();
        $content['proteinaaltovalor'] = $alimento->isProteinaaltovalor();
        $content['tipoalimento'] = $alimento->getTipoalimento() ? $alimento->getTipoalimento()->getNombre() : null;

        $status = Response::HTTP_OK;

        return $this->makeresponse($content, $status);

    }

    function makeresponse($content, $status){
        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent(json_encode($content));
        $response->setStatusCode($status);
        return $response;

    }

}
