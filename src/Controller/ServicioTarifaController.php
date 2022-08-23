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

#[Route('/servicio/tarifa')]
class ServicioTarifaController extends AbstractController
{

    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'knp_paginator' => PaginatorInterface::class
            ] + parent::getSubscribedServices();
    }

    #[Route('/ajaxinfo/{id}', name: 'app_servicio_tarifa_ajaxinfo', defaults: ['id' => null])]
    public function ajaxinfoAction(Request $request, $id)
    {

        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $tarifa = $em
            ->getRepository('App\Entity\ServicioTarifa')
            ->find($id);

        if(!$tarifa){
            $content = [];
            $status = Response::HTTP_NO_CONTENT;
            return $this->makeresponse($content, $status);
        }

        $content['id'] = $tarifa->getId();
        $content['moneda'] = $tarifa->getMoneda() ? $tarifa->getMoneda()->getId() : null;
        $content['monto'] = $tarifa->getMonto();
        $content['prorrateado'] = $tarifa->isProrrateado();
        $content['capacidadmin'] = $tarifa->getCapacidadmin();
        $content['capacidadmax'] = $tarifa->getCapacidadmax();
        $content['tipotarifa'] = $tarifa->getTipotarifa()->getId();

        $status = Response::HTTP_OK;

        return $this->makeresponse($content, $status);

    }

    #[Route('/porcomponentedropdown/{componente}', name: 'app_servicio_tarifa_porcomponentedropdown', defaults: ['componente' => null])]
    public function porcomponentedropdownAction(Request $request, $componente)
    {

        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $tarifas = $em
            ->getRepository('App\Entity\ServicioTarifa')->createQueryBuilder('t');
        if($componente != 0){
            $tarifas->where('t.componente = :componente')
                ->setParameter('componente', $componente);
        }

        if(!empty($request->get('q'))){
            $tarifas->andWhere('t.nombre like :cadena')
                ->setParameter('cadena', '%' . $request->get('q') . '%');
        }

        $tarifas->orderBy('t.nombre', 'ASC');
            //->orderBy('p.price', 'ASC')
        $paginator  = $this->container->get('knp_paginator');
        $pagination = $paginator->paginate(
            $tarifas->getQuery(),
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
            $resultado[$key]['label'] = $item->__toString();
            $resultado[$key]['costo'] = sprintf('%s %s', $item->getMoneda()->getCodigo(), $item->getMonto());
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

    function makeresponse($content, $status){
        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent(json_encode($content));
        $response->setStatusCode($status);
        return $response;

    }

}
