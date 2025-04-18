<?php

namespace App\Controller;

use App\Entity\CotizacionEstadocotcomponente;
use App\Entity\CotizacionEstadocotizacion;
use Google\Cloud\Translate\V2\TranslateClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use App\Service\CotizacionProceso;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class CotizacionCotizacionAdminController extends CRUDAdminController
{

    private EntityManagerInterface $entityManager;
    var $clasificacionTarifas = [];
    var $resumenClasificado = [];
    var $cotizacionProceso;

    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'App\Service\CotizacionProceso' => CotizacionProceso::class,
            ] + parent::getSubscribedServices();
    }

    public function clonarAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);

        $this->admin->checkAccess('create', $object);

        if(!empty($request->query->get('fechainicio'))){
            try {
                $newFechaInicio = new \DateTimeImmutable($request->query->get('fechainicio'));
                $mensaje = 'Cotización clonada correctamente, se ha cambiado la fecha de inicio de los servicios';
                $mensajeTyoe = 'success';
            } catch (\Exception $e) {
                $newFechaInicio = new \DateTimeImmutable('today');
                $mensaje = 'Formato de fecha incorrecto, la cotización se ha clonado para la fecha de hoy';
                $mensajeTyoe = 'info';
            }
        }else{
            $mensaje = 'Cotización clonada correctamente, se ha mantenido la fecha de los servicios';
            $mensajeTyoe = 'success';
        }

        $this->entityManager = $this->container->get('doctrine.orm.default_entity_manager');

        $newObject = clone $object;
        $newObject->setNombre('(Clonado) ' . $object->getNombre());
        $newObject->setEstadocotizacion($this->entityManager->getReference('App\Entity\CotizacionEstadocotizacion', CotizacionEstadocotizacion::DB_VALOR_PENDIENTE));

        foreach($newObject->getCotservicios() as $cotservicio):

            if(isset($newFechaInicio)){
                //solo en la primera iteracion considerando que el orden es por fecha de inicio
                if(!isset($oldFechaInicio)){
                    $oldFechaInicio = new \DateTimeImmutable($cotservicio->getFechaHoraInicio()->format('Y-m-d'));
                    $interval = $oldFechaInicio->diff($newFechaInicio);
                }
                if(isset($interval)) {
                    $cotservicio->getFechaHoraInicio()->add($interval);
                    $cotservicio->getFechaHoraFin()->add($interval);
                }
            }
            foreach($cotservicio->getCotcomponentes() as $cotcomponente):
                if(isset($newFechaInicio) && isset($interval)) {
                    $cotcomponente->getFechaHoraInicio()->add($interval);
                    $cotcomponente->getFechaHoraFin()->add($interval);
                }
                $cotcomponente->setEstadocotcomponente($this->entityManager->getReference('App\Entity\CotizacionEstadocotcomponente', CotizacionEstadocotcomponente::DB_VALOR_PENDIENTE));
            endforeach;
        endforeach;

        $this->admin->create($newObject);
        $this->addFlash('sonata_flash_' . $mensajeTyoe, $mensaje);

        return new RedirectResponse($this->admin->generateUrl('edit', ['id' => $newObject->getId()]));
    }

    public function traducirAction(Request $request)
    {
        $object = $this->assertObjectExists($request, true);
        $id = $object->getId();
        if($request->getDefaultLocale() == $request->getLocale()){
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');

            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->admin->checkAccess('edit', $object);

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $cotizacionDL = $em->getRepository('App\Entity\CotizacionCotizacion')->find($id);
        $cotizacionDL->setLocale($request->getDefaultLocale());
        $em->refresh($cotizacionDL);

        $resumenDL = $cotizacionDL->getResumen();

        $cotizacionDL->setLocale($request->getLocale());
        $em->refresh($cotizacionDL);

        $translate = new TranslateClient([
            'key' => $this->getParameter('google_translate_key')
        ]);

        if(!empty($resumenDL)) {
            $resumenTL = $translate->translate($resumenDL, [
                'target' => $request->getLocale(),
                'source' => $request->getDefaultLocale()
            ]);
            $object->setResumen($resumenTL['text']);
        }

        $existingObject = $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'La cotización traducida correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }

    public function showAction(Request $request): Response | RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        $template = 'cotizacion_cotizacion_admin/show.html.twig';
        //falla en producciom $template = $this->templateRegistry->getTemplate('show');

        if($this->container->get('App\Service\CotizacionProceso')->procesar($object->getId())){
            return $this->renderWithExtraParams($template,
                ['cotizacion' => $this->container->get('App\Service\CotizacionProceso')->getDatosCotizacion(),
                    'tabs' => $this->container->get('App\Service\CotizacionProceso')->getDatosTabs(),
                    'object' => $object,
                    'action' => 'show',
                    'elements' => $fields

                ], null);
        }else{
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

    }

    function resumenAction(Request $request): Response | RedirectResponse
    {

        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        //verificamos token
        if($request->get('token') != $object->getToken()){
            $this->addFlash('sonata_flash_error', 'El código de autorización no coincide');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if($object->getEstadocotizacion()->isNopublico()){
            $this->addFlash('sonata_flash_error', 'La cotización no se muestra en resumen, redirigido a "Mostrar"');
            return new RedirectResponse($this->admin->generateUrl('show', ['id' => $object->getId()]));
        }

        $this->checkParentChildAssociation($request, $object);

        //$this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        $template = 'cotizacion_cotizacion_admin/show.html.twig';

        if($this->container->get('App\Service\CotizacionProceso')->procesar($object->getId())){
            return $this->renderWithExtraParams($template,
                ['cotizacion' => $this->container->get('App\Service\CotizacionProceso')->getDatosCotizacion(),
                    'tabs' => $this->container->get('App\Service\CotizacionProceso')->getDatosTabs(),
                    'object' => $object,
                    'action' => 'resumen',
                    'elements' => $fields,

                ], null);
        }else{
            return new RedirectResponse($this->admin->generateUrl('list'));
        }
    }

    function operacionesAction(Request $request): Response | RedirectResponse
    {

        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        //verificamos token
        if($request->get('tokenoperaciones') != $object->getTokenoperaciones()){
            $this->addFlash('sonata_flash_error', 'El código de autorización no coincide');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if($object->getEstadocotizacion()->isNopublico()){
            $this->addFlash('sonata_flash_error', 'La cotización no se muestra en resumen, redirigido a "Mostrar"');
            return new RedirectResponse($this->admin->generateUrl('show', ['id' => $object->getId()]));
        }

        $this->checkParentChildAssociation($request, $object);

        //$this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        $template = 'cotizacion_cotizacion_admin/show.html.twig';

        if($this->container->get('App\Service\CotizacionProceso')->procesar($object->getId())){
            return $this->renderWithExtraParams($template,
                ['cotizacion' => $this->container->get('App\Service\CotizacionProceso')->getDatosCotizacion(),
                    'tabs' => $this->container->get('App\Service\CotizacionProceso')->getDatosTabs(),
                    'object' => $object,
                    'action' => 'operaciones',
                    'elements' => $fields,

                ], null);
        }else{
            return new RedirectResponse($this->admin->generateUrl('list'));
        }
    }


}
