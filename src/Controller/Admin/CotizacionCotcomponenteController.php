<?php

namespace App\Controller\Admin;


use App\Service\MensajeProveedor;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Service\IcalGenerator;

class CotizacionCotcomponenteController extends CRUDController
{

    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'App\Service\MensajeProveedor' => MensajeProveedor::class,
            ] + parent::getSubscribedServices();
    }

    public function showAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        $template = 'admin/cotizacion_cotcomponente/show.html.twig';
        //falla en producciom $template = $this->templateRegistry->getTemplate('show');

        return $this->renderWithExtraParams($template, [
            'proveedores' => $this->container->get('App\Service\MensajeProveedor')->getMensajesParaComponente($object->getId()),
            'action' => 'show',
            'object' => $object,
            'elements' => $fields,
        ]);
    }


}
