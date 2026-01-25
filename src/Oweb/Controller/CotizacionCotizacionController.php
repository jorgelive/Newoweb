<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\CotizacionCotizacion;
use App\Oweb\Entity\CotizacionEstadocotcomponente;
use App\Oweb\Entity\CotizacionEstadocotizacion;
use App\Oweb\Service\CotizacionProceso;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador para la gestión de Cotizaciones.
 * Maneja la clonación con ajuste de fechas, visualización de estados y
 * traducción automática delegada en un servicio de abstracción.
 */
class CotizacionCotizacionController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    public array $clasificacionTarifas = [];
    public array $resumenClasificado = [];
    public $cotizacionProceso;

    /**
     * Define los servicios requeridos por el controlador para su inyección mediante el contenedor.
     * Se incluye el servicio de traducción para cumplir con el estándar de no usar vendors directamente.
     * * @return array
     */
    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'App\Oweb\Service\CotizacionProceso' => CotizacionProceso::class,
                'App\Service\GoogleTranslateService' => GoogleTranslateService::class,
            ] + parent::getSubscribedServices();
    }

    /**
     * Clona una cotización ajustando opcionalmente las fechas de los servicios.
     * * @param Request $request
     * @return Response
     */
    public function clonarAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);
        $this->admin->checkAccess('create', $object);

        $mensajeType = 'success';
        if (!empty($request->query->get('fechainicio'))) {
            try {
                $newFechaInicio = new \DateTimeImmutable($request->query->get('fechainicio'));
                $mensaje = 'Cotización clonada correctamente, se ha cambiado la fecha de inicio de los servicios';
            } catch (\Exception $e) {
                $newFechaInicio = new \DateTimeImmutable('today');
                $mensaje = 'Formato de fecha incorrecto, la cotización se ha clonado para la fecha de hoy';
                $mensajeType = 'info';
            }
        } else {
            $mensaje = 'Cotización clonada correctamente, se ha mantenido la fecha de los servicios';
        }

        /** @var EntityManagerInterface $em */
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $newObject = clone $object;
        $newObject->setNombre('(Clonado) ' . $object->getNombre());
        $newObject->setEstadocotizacion($em->getReference(CotizacionEstadocotizacion::class, CotizacionEstadocotizacion::DB_VALOR_PENDIENTE));

        foreach ($newObject->getCotservicios() as $cotservicio) {
            if (isset($newFechaInicio)) {
                if (!isset($oldFechaInicio)) {
                    $oldFechaInicio = new \DateTimeImmutable($cotservicio->getFechaHoraInicio()->format('Y-m-d'));
                    $interval = $oldFechaInicio->diff($newFechaInicio);
                }
                if (isset($interval)) {
                    $cotservicio->getFechaHoraInicio()->add($interval);
                    $cotservicio->getFechaHoraFin()->add($interval);
                }
            }
            foreach ($cotservicio->getCotcomponentes() as $cotcomponente) {
                if (isset($newFechaInicio) && isset($interval)) {
                    $cotcomponente->getFechaHoraInicio()->add($interval);
                    $cotcomponente->getFechaHoraFin()->add($interval);
                }
                $cotcomponente->setEstadocotcomponente($em->getReference(CotizacionEstadocotcomponente::class, CotizacionEstadocotcomponente::DB_VALOR_PENDIENTE));
            }
        }

        $this->admin->create($newObject);
        $this->addFlash('sonata_flash_' . $mensajeType, $mensaje);

        return new RedirectResponse($this->admin->generateUrl('edit', ['id' => $newObject->getId()]));
    }

    /**
     * Traduce el resumen de la cotización delegando en el servicio de abstracción.
     * * @param Request $request
     * @return RedirectResponse
     */
    public function traducirAction(Request $request): RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        $targetLocale = $request->getLocale();
        $defaultLocale = $request->getDefaultLocale();

        if ($defaultLocale === $targetLocale) {
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->admin->checkAccess('edit', $object);

        /** @var EntityManagerInterface $em */
        $em = $this->container->get('doctrine.orm.default_entity_manager');
        /** @var GoogleTranslateService $translateService */
        $translateService = $this->container->get('App\Service\GoogleTranslateService');

        /** @var CotizacionCotizacion $cotizacionDL */
        $cotizacionDL = $em->getRepository(CotizacionCotizacion::class)->find($object->getId());

        // Carga de contenido original
        $cotizacionDL->setLocale($defaultLocale);
        $em->refresh($cotizacionDL);
        $resumenDL = $cotizacionDL->getResumen();

        // Regreso al locale destino para persistencia
        $cotizacionDL->setLocale($targetLocale);
        $em->refresh($cotizacionDL);

        if (!empty($resumenDL)) {
            try {
                // Se utiliza la capa de servicio para evitar el uso de vendors en el controlador
                $translations = $translateService->translate($resumenDL, $targetLocale, $defaultLocale);
                $object->setResumen($translations[0] ?? '');

                $this->admin->update($object);
                $this->addFlash('sonata_flash_success', 'La cotización se ha traducido correctamente mediante API V3');
            } catch (ApiException $e) {
                $this->addFlash('sonata_flash_error', 'Error en el servicio de traducción: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Renderiza la vista detallada de la cotización procesada.
     * * @param Request $request
     * @return Response|RedirectResponse
     */
    public function showAction(Request $request): Response|RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        $this->checkParentChildAssociation($request, $object);
        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);
        $fields = $this->admin->getShow();
        $template = 'oweb/admin/cotizacion_cotizacion/show.html.twig';

        /** @var CotizacionProceso $proceso */
        $proceso = $this->container->get('App\Oweb\Service\CotizacionProceso');

        if ($proceso->procesar($object->getId())) {
            return $this->renderWithExtraParams($template, [
                'cotizacion' => $proceso->getDatosCotizacion(),
                'tabs' => $proceso->getDatosTabs(),
                'object' => $object,
                'action' => 'show',
                'elements' => $fields
            ]);
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Muestra el resumen público de la cotización validando el token de acceso.
     */
    public function resumenAction(Request $request): Response|RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);

        if ($request->get('token') !== $object->getToken()) {
            $this->addFlash('sonata_flash_error', 'El código de autorización no coincide');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if ($object->getEstadocotizacion()->isNopublico()) {
            $this->addFlash('sonata_flash_error', 'La cotización no se muestra en resumen, redirigido a "Mostrar"');
            return new RedirectResponse($this->admin->generateUrl('show', ['id' => $object->getId()]));
        }

        $this->admin->setSubject($object);
        $template = 'oweb/admin/cotizacion_cotizacion/show.html.twig';

        /** @var CotizacionProceso $proceso */
        $proceso = $this->container->get('App\Oweb\Service\CotizacionProceso');

        if ($proceso->procesar($object->getId())) {
            return $this->renderWithExtraParams($template, [
                'cotizacion' => $proceso->getDatosCotizacion(),
                'tabs' => $proceso->getDatosTabs(),
                'object' => $object,
                'action' => 'resumen',
                'elements' => $this->admin->getShow(),
            ]);
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Muestra la vista de operaciones con validación de token específico.
     */
    public function operacionesAction(Request $request): Response|RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);

        if ($request->get('tokenoperaciones') !== $object->getTokenoperaciones()) {
            $this->addFlash('sonata_flash_error', 'El código de autorización no coincide');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if ($object->getEstadocotizacion()->isNopublico()) {
            $this->addFlash('sonata_flash_error', 'La cotización no se muestra en resumen, redirigido a "Mostrar"');
            return new RedirectResponse($this->admin->generateUrl('show', ['id' => $object->getId()]));
        }

        $this->admin->setSubject($object);
        $template = 'oweb/admin/cotizacion_cotizacion/show.html.twig';

        /** @var CotizacionProceso $proceso */
        $proceso = $this->container->get('App\Oweb\Service\CotizacionProceso');

        if ($proceso->procesar($object->getId())) {
            return $this->renderWithExtraParams($template, [
                'cotizacion' => $proceso->getDatosCotizacion(),
                'tabs' => $proceso->getDatosTabs(),
                'object' => $object,
                'action' => 'operaciones',
                'elements' => $this->admin->getShow(),
            ]);
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }
}