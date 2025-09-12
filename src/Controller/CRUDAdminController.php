<?php
namespace App\Controller;

use Sonata\AdminBundle\Controller\CRUDController as BaseController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class CRUDAdminController extends BaseController
{
    function makeIcalResponse($calendar, int $status = 200): Response
    {
        $mimeType = $calendar->getContentType(); // 'text/calendar'
        $filename = $calendar->getFilename();    // ejemplo: 'evento especial.ics'

        $response = new Response(
            $calendar->export(), // contenido del archivo
            $status,             // código HTTP
            [
                'Content-Type' => $mimeType . '; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma'        => 'no-cache',
                'Expires'       => '0',
            ]
        );

        // Header Content-Disposition seguro para todos los navegadores
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            preg_replace('/[^\x20-\x7E]/', '_', $filename) // backup ASCII
        );

        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    public function emailAction(Request $request, TransportInterface $mailer): RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        // Determinar método de envío de datos
        $emailInfo = $request->isMethod('POST')
            ? $request->request->get('email', [])   // POST
            : $request->query->get('email', []);   // GET

        // Validar campos obligatorios
        if (empty($emailInfo['destinatario']) || empty($emailInfo['titulo']) || empty($emailInfo['mensaje'])) {
            $this->addFlash('sonata_flash_error', 'Todos los campos son obligatorios.');
            return $this->redirectToRoute('admin_show', ['id' => $object->getId()]);
        }

        // Si los datos vienen por GET, decodificarlos
        $to      = $request->isMethod('GET') ? urldecode($emailInfo['destinatario']) : $emailInfo['destinatario'];
        $subject = $request->isMethod('GET') ? urldecode($emailInfo['titulo'])       : $emailInfo['titulo'];
        $message = $request->isMethod('GET') ? urldecode($emailInfo['mensaje'])     : $emailInfo['mensaje'];

        $email = (new Email())
            ->from(new Address(
                $this->getParameter('mailer_sender_email'),
                $this->getParameter('mailer_sender_name')
            ))
            ->to($to)
            ->bcc($this->getParameter('mailer_control_email'))
            ->priority(Email::PRIORITY_HIGH)
            ->subject($subject)
            ->html($message);

        try {
            $mailer->send($email);
            $this->addFlash('sonata_flash_success', 'Se envió correctamente el mensaje.');
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('sonata_flash_error', 'Hubo un error al enviar el mensaje: ' . $e->getMessage());
        }

        return new RedirectResponse($this->admin->generateUrl('show', ['id' => $object->getId()]));
    }

    protected function redirectTo(Request $request, object $object): RedirectResponse
    {
        $response = parent::redirectTo($request, $object);

        if ($request->get('btn_update_and_list') !== null || $request->get('btn_create_and_list') !== null) {

            // Ruta actual sin sufijos _edit o _create
            $currentRoute = $request->attributes->get('_route');
            $currentAdmin = str_replace(['_edit', '_create'], '', $currentRoute);

            // Obtener sesión y última lista
            $session = $request->getSession();
            $lastList = $session->get('last_list');

            if (!empty($lastList) && !empty($lastList['route']) && !str_contains($lastList['route'], $currentAdmin)) {
                // Redirigir a la última lista con filtros
                $response = $this->redirect(
                    $this->generateUrl($lastList['route'], ['filter' => $lastList['filters']])
                );
            } else {
                // Comportamiento normal: lista del admin actual con filtros activos
                $parameters = [];
                $filter = $this->admin->getFilterParameters();
                if (!empty($filter)) {
                    $parameters['filter'] = $filter;
                }
                $response = $this->redirect($this->admin->generateUrl('list', $parameters));
            }
        }

        return $response;
    }

    public function listAction(Request $request): Response
    {
        // Evitar ejecutar en lista modal o si viene el parámetro 'pcode'
        if (is_null($request->get('pcode')) && !$request->isXmlHttpRequest()) {
            $session = $request->getSession();
            $route = $request->attributes->get('_route');
            $filters = $this->admin->getFilterParameters();
            $session->set('last_list', [
                'route'   => $route,
                'filters' => $filters,
            ]);
        }

        // Llamar al listAction original de Sonata
        return parent::listAction($request);
    }

}