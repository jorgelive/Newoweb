<?php
namespace App\Controller;


use Sonata\AdminBundle\Controller\CRUDController as BaseController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class CRUDAdminController extends BaseController
{
    protected function redirectTo(Request $request, object $object): RedirectResponse
    {
        $response = parent::redirectTo($request, $object);

        if (null !== $request->get('btn_update_and_list') || null !== $request->get('btn_create_and_list')) {

            $current_admin = str_replace("_edit", "", $this->container->get('router')->match($request->getPathInfo())['_route']);
            $current_admin = str_replace("_create", "", $current_admin);

            $session = $this->container->get('request_stack')->getSession();
            $last_list = $session->get('last_list');

            if(strstr($last_list['route'], $current_admin) || empty($last_list['route'])) {
                $parameters = [];

                $filter = $this->admin->getFilterParameters();
                if ([] !== $filter) {
                    $parameters['filter'] = $filter;
                }
                $response = $this->redirect($this->admin->generateUrl('list', $parameters));
            }else{
                $response = new RedirectResponse(
                    $this->container->get('router')->generate(
                        $last_list['route'],
                        array('filter' => $last_list['filters'])
                    )
                );
            }
        }

        return $response;
    }

    public function listAction(Request $request): Response
    {
        $session = $this->container->get('request_stack')->getSession();
        $route = $this->container->get('router')->match($request->getPathInfo())['_route'];

        $filters = $this->admin->getFilterParameters();
        $session->set('last_list', array('route' => $route, 'filters' => $filters));

        return parent::listAction($request);

    }
}