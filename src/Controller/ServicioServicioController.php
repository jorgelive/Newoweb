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


#[Route('/servicio/servicio')]
class ServicioServicioController extends AbstractController
{


    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'knp_paginator' => PaginatorInterface::class
            ] + parent::getSubscribedServices();
    }


    #[Route('/alldropdown', name: 'app_servicio_servicio_alldropdown')]
    public function alldropdownAction(Request $request): Response
    {
        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $servicios = $em->getRepository('App:ServicioServicio')->createQueryBuilder('s');

        if(!empty($request->get('q'))){
            $servicios->where('s.nombre like :cadena')
                ->setParameter('cadena', '%' . $request->get('q') . '%');
        }

        $servicios->orderBy('s.nombre', 'ASC');

        $paginator  = $this->container->get('knp_paginator');
        $pagination = $paginator->paginate(
            $servicios->getQuery(),
            //el dropdown estandar no lleva pagina
            !is_null($request->get('_page')) ? $request->get('_page') : 1 ,
            $request->get('_per_page')
        );

        if(!$pagination->getItems()){
            $content = ['status' => 'OK', 'items' => [], 'more' => false, 'message' => 'No existe contenido.'];
            $status = Response::HTTP_OK;// Response::HTTP_NO_CONTENT;
            return $this->makeresponse($content, $status);
        };

        foreach ($pagination->getItems() as $key => $item):
            $resultado[$key]['id'] = $item->getId();
            $resultado[$key]['label'] = $item->getNombre();
        endforeach;

        $totalItems = $pagination->getTotalItemCount();
        $maxItems = $request->get('_page') * $request->get('_per_page');

        $content = [
            'status' => 'OK',
            'more' => ($maxItems < $totalItems),
            'items' => $resultado
        ];
        $status = Response::HTTP_OK;

        return $this->makeresponse($content, $status);
    }


    #[Route('/ajaxinfo/{id}', name: 'app_servicio_servicio_ajaxinfo', defaults: ['id' => null])]
    public function ajaxinfoAction(Request $request, $id): Response
    {

        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $servicio=$em
            ->getRepository('App:ServicioServicio')
            ->find($id);

        if(!$servicio){
            $content = [];
            $status = Response::HTTP_NO_CONTENT;
            return $this->makeresponse($content, $status);
        }

        $content['id'] = $servicio->getId();
        $content['paralelo'] = $servicio->getParalelo();

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
