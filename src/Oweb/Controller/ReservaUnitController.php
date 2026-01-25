<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\ReservaEstado;
use App\Oweb\Entity\ReservaUnit;
use App\Oweb\Service\IcalGenerator;
use App\Oweb\Service\MainVariableproceso;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador para la gestión de Unidades de Reserva.
 * Maneja traducciones automáticas V3 e integraciones de calendarios iCal.
 */
class ReservaUnitController extends CRUDController
{
    /**
     * Define los servicios requeridos por el controlador para su inyección mediante el contenedor.
     * * @return array
     */
    public static function getSubscribedServices(): array
    {
        return [
                'App\Oweb\Service\IcalGenerator' => IcalGenerator::class,
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'App\Oweb\Service\MainVariableproceso' => MainVariableproceso::class,
                'App\Service\GoogleTranslateService' => GoogleTranslateService::class,
            ] + parent::getSubscribedServices();
    }

    /**
     * Traduce la descripción y referencia de la unidad utilizando el servicio de abstracción de Google V3.
     * * @param Request $request
     * @return RedirectResponse
     */
    public function traducirAction(Request $request): RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $request->get('id')));
        }

        $id = $object->getId();
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

        /** @var ReservaUnit $unitDL */
        $unitDL = $em->getRepository(ReservaUnit::class)->find($id);

        // Carga de datos originales
        $unitDL->setLocale($defaultLocale);
        $em->refresh($unitDL);

        $descripcionDL = $unitDL->getDescripcion();
        $referenciaDL = $unitDL->getReferencia();

        // Cambio a locale destino para persistencia
        $unitDL->setLocale($targetLocale);
        $em->refresh($unitDL);

        try {
            if (!empty($descripcionDL)) {
                $translations = $translateService->translate($descripcionDL, $targetLocale, $defaultLocale);
                $object->setDescripcion($translations[0] ?? '');
            }

            if (!empty($referenciaDL)) {
                $translations = $translateService->translate($referenciaDL, $targetLocale, $defaultLocale);
                $object->setReferencia($translations[0] ?? '');
            }

            $this->admin->update($object);
            $this->addFlash('sonata_flash_success', 'Unidad traducida correctamente mediante servicio V3');

        } catch (ApiException $e) {
            $this->addFlash('sonata_flash_error', 'Error en el servicio de traducción: ' . $e->getMessage());
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * Muestra la vista de inventario de la unidad.
     * * @param Request|null $request
     * @return Response|RedirectResponse
     */
    public function inventarioAction(Request $request = null): Response|RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        $this->checkParentChildAssociation($request, $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);
        $fields = $this->admin->getShow();

        return $this->render('oweb/admin/reserva_unit/show.html.twig', [
            'object'   => $object,
            'action'   => 'inventario',
            'elements' => $fields,
            'admin'    => $this->admin,
        ]);
    }

    /**
     * Muestra un resumen detallado de la unidad.
     * * @param Request|null $request
     * @return Response|RedirectResponse
     */
    public function resumenAction(Request $request = null): Response|RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        $this->checkParentChildAssociation($request, $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);
        $fields = $this->admin->getShow();

        return $this->render('oweb/admin/reserva_unit/show.html.twig', [
            'object'   => $object,
            'action'   => 'resumen',
            'elements' => $fields,
            'admin'    => $this->admin,
        ]);
    }

    /**
     * Alias para la generación de iCal con extensión .ics.
     */
    public function icalicsAction(Request $request = null): Response
    {
        return $this->icalAction($request);
    }

    /**
     * Genera el feed iCal de reservas para la unidad especificada.
     * * @param Request|null $request
     * @return Response
     */
    public function icalAction(Request $request = null): Response
    {
        /** @var MainVariableproceso $variableproceso */
        $variableproceso = $this->container->get('App\Oweb\Service\MainVariableproceso');
        /** @var IcalGenerator $icalGenerator */
        $icalGenerator = $this->container->get('App\Oweb\Service\IcalGenerator');
        /** @var EntityManagerInterface $em */
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $ahora = new \DateTime('now');
        $object = $this->assertObjectExists($request, true);

        if (!$object) {
            throw $this->createNotFoundException('Unable to find the object processing the request');
        }

        $id = $object->getId();

        $qb = $em->createQueryBuilder()
            ->select('rr')
            ->from('App\Oweb\Entity\ReservaReserva', 'rr')
            ->where('rr.unit = :unit')
            ->andWhere('rr.estado in (:estado)')
            ->andWhere('DATE(rr.fechahorafin) >= :fechahorafin')
            ->orderBy('rr.fechahorainicio', 'ASC')
            ->setParameter('unit', $id)
            ->setParameter('estado', [
                ReservaEstado::DB_VALOR_INICIAL,
                ReservaEstado::DB_VALOR_CONFIRMADO,
                ReservaEstado::DB_VALOR_PAGO_PARCIAL,
                ReservaEstado::DB_VALOR_PAGO_TOTAL,
                ReservaEstado::DB_VALOR_PARA_CANCELACION
            ])
            ->setParameter('fechahorafin', $ahora->format('Y-m-d'));

        $reservas = $qb->getQuery()->getResult();

        $calendar = $icalGenerator
            ->setTimezone('America/Lima')
            ->setProdid('-//OpenPeru//ReservaUnit Calendar //ES')
            ->createCalendar();

        $queriedfrom = gethostbyaddr($request->getClientIp());
        $variableproceso->prependtofile('debug/reservasuniticalhosts.txt', $ahora->format('Y-m-d H:i:s') . ' Para: ' . $id . ', Consultado desde '. $queriedfrom . "\n");

        foreach ($reservas as $reserva) {
            $fechainicio = new \DateTime($reserva->getFechahorainicio()->format('Y-m-d'));
            $fechafin = new \DateTime($reserva->getFechahorafin()->format('Y-m-d'));

            $tempEvent = $icalGenerator
                ->createCalendarEvent()
                ->setStart($fechainicio)
                ->setEnd($fechafin)
                ->setSummary($reserva->getNombre())
                ->setDescription($reserva->getEnlace())
                ->setUid($id . '-' . $reserva->getUid());

            $calendar->addEvent($tempEvent);
        }

        return $this->makeIcalResponse($calendar, Response::HTTP_OK);
    }
}