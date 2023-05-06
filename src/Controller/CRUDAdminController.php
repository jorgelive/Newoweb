<?php
namespace App\Controller;

use Sonata\AdminBundle\Controller\CRUDController as BaseController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class CRUDAdminController extends BaseController
{
    function emailAction(Request $request, TransportInterface $mailer): RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        $emaiInfo = $request->get('email');

        $email = (new Email())
            ->from(new Address($this->getParameter('mailer_sender_email'), $this->getParameter('mailer_sender_name')))
            ->to(urldecode($emaiInfo['destinatario']))
            ->cc($this->getParameter('mailer_control_email'))
            ->priority(Email::PRIORITY_HIGH)
            ->subject(urldecode($emaiInfo['titulo']))
            ->html(urldecode($emaiInfo['mensaje']));

        try {
            $mailer->send($email);
        }catch (TransportExceptionInterface $e) {
            $this->addFlash('sonata_flash_error', 'Hubo un error al enviar el mensaje:' . $e->getMessage());
            return new RedirectResponse($this->admin->generateUrl('show', ['id' => $object->getId()]));
        }

        $this->addFlash('sonata_flash_success', 'Se envió correctamente el mensaje.');
        return new RedirectResponse($this->admin->generateUrl('show', ['id' => $object->getId()]));
    }

    protected function redirectTo(Request $request, object $object): RedirectResponse
    {
        $response = parent::redirectTo($request, $object);

        if(null !== $request->get('btn_update_and_list') || null !== $request->get('btn_create_and_list')) {

            $current_admin = str_replace("_edit", "", $this->container->get('router')->match($request->getPathInfo())['_route']);
            $current_admin = str_replace("_create", "", $current_admin);

            $session = $this->container->get('request_stack')->getSession();
            $last_list = $session->get('last_list');

            if(!strstr($last_list['route'], $current_admin) && !empty($last_list['route'])) {
                $response = new RedirectResponse(
                    $this->container->get('router')->generate(
                        $last_list['route'],
                        ['filter' => $last_list['filters']]
                    )
                );
            }else{ //comportamiento normal
                $parameters = [];

                $filter = $this->admin->getFilterParameters();
                if([] !== $filter) {
                    $parameters['filter'] = $filter;
                }
                $response = $this->redirect($this->admin->generateUrl('list', $parameters));
            }
        }

        return $response;
    }

    public function listAction(Request $request): Response
    {
        //No se ejecuta en el caso de lista modal
        //$temp = gettype($request->get('_xml_http_request'));

        if(is_null($request->get('pcode')) && $request->get('_xml_http_request') != 'true'){
            $session = $this->container->get('request_stack')->getSession();
            $route = $this->container->get('router')->match($request->getPathInfo())['_route'];
            $filters = $this->admin->getFilterParameters();
            $session->set('last_list', array('route' => $route, 'filters' => $filters));
        }

        return parent::listAction($request);

    }
}