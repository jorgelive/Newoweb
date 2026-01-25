<?php

namespace App\Api\Controller\Oweb;

use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Oweb\Entity\ServicioComponente;


#[Route('/servicio/componente')]
class ServicioComponenteController extends AbstractController
{

    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'knp_paginator' => PaginatorInterface::class
            ] + parent::getSubscribedServices();
    }

    #[Route('/porserviciodropdown/{servicio}', name: 'api_oweb_servicio_componente_porserviciodropdown', defaults: ['servicio' => null])]
    public function porserviciodownAction(Request $request, $servicio): Response
    {
        //?q=&_per_page=10&_page=1&field=tarifa&_=1513629738031
        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $componentes = $em->getRepository(ServicioComponente::class)->createQueryBuilder('c');
        if($servicio != 0){
            $componentes
                ->select('c')
                ->innerJoin('c.servicios', 's')
                ->where('s.id = :servicio')
                ->setParameter('servicio', $servicio);
        }

        if(!empty($request->get('q'))){
            $componentes->andWhere('c.nombre like :cadena')
                ->setParameter('cadena', '%' . $request->get('q') . '%');
        }

        $componentes->orderBy('c.nombre', 'ASC');

        $paginator  = $this->container->get('knp_paginator');
        $pagination = $paginator->paginate(
            $componentes->getQuery(),
            $request->get('_page'),
            $request->get('_per_page')
        );

        if(!$pagination->getItems()){
            $content = ['status' => 'OK', 'items' => [], 'more' => false, 'message' => 'No existe contenido.'];
            $status = Response::HTTP_OK;// Response::HTTP_NO_CONTENT;
            return $this->makeresponse($content, $status);
        };

        foreach($pagination->getItems() as $key => $item):
            $resultado[$key]['id'] = $item->getId();
            $resultado[$key]['label'] = $item->getNombre();
        endforeach;

        $totalItems = $pagination->getTotalItemCount();
        $maxItems = $request->get('_page') * $request->get('_per_page');

        //throw $this->createAccessDeniedException('no tiene el permiso para ver el contenido!');
        // subject will be empty to avoid unnecessary database requests and keep autocomplete function fast

        $content = [
            'status' => 'OK',
            'more' => ($maxItems < $totalItems),
            'items' => $resultado
        ];
        $status = Response::HTTP_OK;

        return $this->makeresponse($content, $status);
    }


    #[Route('/ajaxinfo/{id}', name: 'api_oweb_servicio_componente_ajaxinfo', defaults: ['id' => null])]
    public function ajaxinfoAction(Request $request, $id): Response
    {

        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $componente=$em
            ->getRepository(ServicioComponente::class)
            ->find($id);

        if(!$componente){
            $content = [];
            $status = Response::HTTP_NO_CONTENT;
            return $this->makeresponse($content, $status);
        }

        $content['id'] = $componente->getId();
        $content['duracion'] = $componente->getDuracion();
        $content['dependeduracion'] = $componente->getTipocomponente()->isDependeduracion();

        $status = Response::HTTP_OK;

        return $this->makeresponse($content, $status);

    }


    function makeresponse($content, $status): Response
    {
        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent(json_encode($content));
        $response->setStatusCode($status);
        return $response;
    }

}
