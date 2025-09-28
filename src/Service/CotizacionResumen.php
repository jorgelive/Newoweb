<?php

namespace App\Service;

use App\Entity\CotizacionCotizacion;
use App\Entity\CotizacionCotservicio;
use App\Entity\CotizacionCotcomponente;
use App\Entity\CotizacionCottarifa;
use App\Entity\ServicioTipocomponente;
use App\Entity\ServicioTipotarifadetalle;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class CotizacionResumen
{
    private EntityManagerInterface $entityManager;
    private TranslatorInterface $translator;
    private CotizacionCotizacion $cotizacion;
    private CotizacionItinerario $cotizacionItinerario;
    private RequestStack $requestStack;

    public function __construct(
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        CotizacionItinerario $cotizacionItinerario,
        RequestStack $requestStack
    ) {
        $this->entityManager = $entityManager;
        $this->translator = $translator;
        $this->cotizacionItinerario = $cotizacionItinerario;
        $this->requestStack = $requestStack;
    }

    /**
     * Obtiene los datos de una cotización por su ID.
     *
     * @param int $id
     * @return array
     */
    public function getDatosFromId(int $id): array
    {
        $cotizacion = $this->entityManager
            ->getRepository(CotizacionCotizacion::class)
            ->find($id);

        if (!$cotizacion) {
            $this->requestStack
                ->getSession()
                ->getFlashBag()
                ->add('error', sprintf('No se puede encontrar el objeto con el identificador : %s', $id));
            return [];
        }

        return $this->getDatos($cotizacion);
    }

    /**
     * Procesa una cotización y genera los datos de la vista.
     *
     * @param CotizacionCotizacion $cotizacion
     * @return array
     */
    public function getDatos(CotizacionCotizacion $cotizacion): array
    {
        $this->cotizacion = $cotizacion;
        $datos = [];

        /** @var CotizacionCotservicio $servicio */
        foreach ($cotizacion->getCotservicios() as $servicio) {
            $fotos = $this->cotizacionItinerario->getFotos($servicio); // Collection

            /** @var CotizacionCotcomponente $componente */
            foreach ($servicio->getCotcomponentes() as $componente) {
                /** @var CotizacionCottarifa $tarifa */
                foreach ($componente->getCottarifas() as $tarifa) {

                    // Omite tarifas ocultas si quieres
                    // if ($tarifa->getTipotarifa()->isOcultoenresumen()) continue;

                    // Procesar alojamientos
                    if ($this->esAlojamiento($tarifa, $componente)) {
                        $this->procesarAlojamiento($datos, $componente, $tarifa);
                        continue;
                    }

                    // Procesar servicios con itinerario
                    if ($this->tieneTituloItinerario($componente, $servicio)) {
                        $this->procesarServicioConItinerario($datos, $servicio, $componente, $tarifa, $fotos);
                        continue;
                    }

                    // Servicios sin itinerario
                    $this->procesarServicioSinItinerario($datos, $componente, $tarifa);
                }
            }
        }

        return $datos;
    }

    /**
     * Determina si la tarifa corresponde a alojamiento.
     */
    private function esAlojamiento(CotizacionCottarifa $tarifa, CotizacionCotcomponente $componente): bool
    {
        return $tarifa->getTarifa()->getComponente()->getTipocomponente()->getId() === ServicioTipocomponente::DB_VALOR_ALOJAMIENTO
            && $componente->getComponente()->getComponenteitems()->count() > 0;
    }

    /**
     * Determina si un componente tiene título en el itinerario.
     */
    private function tieneTituloItinerario(CotizacionCotcomponente $componente, CotizacionCotservicio $servicio): bool
    {
        return !empty($this->cotizacionItinerario->getTituloItinerario($componente->getFechahoraInicio(), $servicio))
            && $componente->getComponente()->getComponenteitems()->count() > 0;
    }

    /**
     * Procesa un alojamiento.
     */
    private function procesarAlojamiento(array &$datos, CotizacionCotcomponente $componente, CotizacionCottarifa $tarifa): void
    {
        $tarifaId = $tarifa->getId();
        $tempItems = [];

        foreach ($componente->getComponente()->getComponenteitems() as $item) {
            $tempItems[] = $item->getTitulo();
        }

        $datos['alojamientos'][$tarifaId]['titulo'] = implode(', ', $tempItems);

        if (!empty($tarifa->getTarifa()->getTitulo())) {
            $datos['alojamientos'][$tarifaId]['tarifaTitulo'] = $tarifa->getTarifa()->getTitulo();
        }

        if (!empty($tarifa->getProvider())) {
            $datos['alojamientos'][$tarifaId]['proveedor'] = $tarifa->getProvider();
        }

        $datos['alojamientos'][$tarifaId]['fechahoraInicio'] = $componente->getFechahoraInicio();
        $datos['alojamientos'][$tarifaId]['fechahoraFin'] = $componente->getFechahoraFin();
        $datos['alojamientos'][$tarifaId]['fechaInicio'] = $componente->getFechaInicio();
        $datos['alojamientos'][$tarifaId]['fechaFin'] = $componente->getFechaFin();
        $datos['alojamientos'][$tarifaId]['tipoTarifa'] = $tarifa->getTipotarifa();

        // Detalles
        foreach ($tarifa->getCottarifadetalles() as $detalle) {
            if ($detalle->getTipotarifaDetalle()->getId() === ServicioTipotarifadetalle::DB_VALOR_DETALLES) {
                $datos['alojamientos'][$tarifaId]['detalles'][] = $detalle->getDetalle();
            }
        }

        // Duración
        $duracionDiff = (int)date_diff($componente->getFechaInicio(), $componente->getFechaFin())->format('%d');
        $unidad = ($duracionDiff === 1)
            ? $this->translator->trans('noche', [], 'messages')
            : $this->translator->trans('noches', [], 'messages');

        $datos['alojamientos'][$tarifaId]['duracionStr'] = $duracionDiff . ' ' . $unidad;
    }

    /**
     * Procesa un servicio con título en el itinerario.
     */
    private function procesarServicioConItinerario(array &$datos, CotizacionCotservicio $servicio, CotizacionCotcomponente $componente, CotizacionCottarifa $tarifa, Collection $fotos): void
    {
        $servicioId = $servicio->getId();
        $tipoTarId = $tarifa->getTipotarifa()->getId();

        $datos['serviciosConTituloItinerario'][$servicioId]['tipoTarifas'][$tipoTarId]['tituloTipotarifa'] = $tarifa->getTipotarifa()->getTitulo();
        $datos['serviciosConTituloItinerario'][$servicioId]['tipoTarifas'][$tipoTarId]['colorTipotarifa'] = $tarifa->getTipotarifa()->getListacolor();
        $datos['serviciosConTituloItinerario'][$servicioId]['tipoTarifas'][$tipoTarId]['claseTipotarifa'] = $tarifa->getTipotarifa()->getListaclase();

        foreach ($componente->getComponente()->getComponenteitems() as $item) {
            $itemKey = $componente->getId() . '-' . $item->getId();
            $datos['serviciosConTituloItinerario'][$servicioId]['tipoTarifas'][$tipoTarId]['componentes'][$itemKey]['titulo'] = $item->getTitulo();

            if (!isset($datos['serviciosConTituloItinerario'][$servicioId]['fechahorasdiferentes'])) {
                $datos['serviciosConTituloItinerario'][$servicioId]['fechahorasdiferentes'] = false;
            }

            if ($tarifa->getTarifa()->getComponente()->getTipocomponente()->isAgendable()) {
                if ($componente->getFechahoraInicio()->format('Y/m/d H:i') !== $servicio->getFechahoraInicio()->format('Y/m/d H:i')) {
                    $datos['serviciosConTituloItinerario'][$servicioId]['fechahorasdiferentes'] = true;
                }

                $fechaKey = $componente->getFechahoraInicio()->format('Ymd');
                $datos['serviciosConTituloItinerario'][$servicioId]['fechas'][$fechaKey]['fecha'] = $componente->getFechahoraInicio();
                $datos['serviciosConTituloItinerario'][$servicioId]['fechas'][$fechaKey]['items'][$itemKey]['titulo'] = $item->getTitulo();
                $datos['serviciosConTituloItinerario'][$servicioId]['fechas'][$fechaKey]['items'][$itemKey]['fechahoraInicio'] = $componente->getFechahoraInicio();
            }
        }

        ksort($datos['serviciosConTituloItinerario'][$servicioId]['tipoTarifas']);

        $datos['serviciosConTituloItinerario'][$servicioId]['tituloItinerario'] = $this->cotizacionItinerario->getTituloItinerario($componente->getFechahoraInicio(), $servicio);
        $datos['serviciosConTituloItinerario'][$servicioId]['fotos'] = $fotos;
        $datos['serviciosConTituloItinerario'][$servicioId]['fechahoraInicio'] = $servicio->getFechahoraInicio();
        $datos['serviciosConTituloItinerario'][$servicioId]['fechahoraFin'] = $servicio->getFechahoraFin();
        $datos['serviciosConTituloItinerario'][$servicioId]['fechaInicio'] = $servicio->getFechaInicio();
        $datos['serviciosConTituloItinerario'][$servicioId]['fechaFin'] = $servicio->getFechaFin();

        // Duración
        $duracionDiff = (int)date_diff($servicio->getFechahoraInicio(), $servicio->getFechahoraFin())->format('%h');
        if ($duracionDiff >= 24) {
            $duracionDiff = (int)date_diff($servicio->getFechaInicio(), $servicio->getFechaFin())->format('%d');
            $unidad = ($duracionDiff > 1) ? $this->translator->trans('dias', [], 'messages') : $this->translator->trans('dia', [], 'messages');
        } else {
            $unidad = ($duracionDiff === 1) ? $this->translator->trans('hora', [], 'messages') : $this->translator->trans('horas', [], 'messages');
        }
        $datos['serviciosConTituloItinerario'][$servicioId]['duracionStr'] = $duracionDiff . ' ' . $unidad;
    }

    /**
     * Procesa un servicio sin título en el itinerario.
     */
    private function procesarServicioSinItinerario(array &$datos, CotizacionCotcomponente $componente, CotizacionCottarifa $tarifa): void
    {
        $tipoTarId = $tarifa->getTipotarifa()->getId();
        $datos['serviciosSinTituloItinerario']['tipoTarifas'][$tipoTarId]['tituloTipotarifa'] = $tarifa->getTipotarifa()->getTitulo();
        $datos['serviciosSinTituloItinerario']['tipoTarifas'][$tipoTarId]['colorTipotarifa'] = $tarifa->getTipotarifa()->getListacolor();
        $datos['serviciosSinTituloItinerario']['tipoTarifas'][$tipoTarId]['claseTipotarifa'] = $tarifa->getTipotarifa()->getListaclase();

        foreach ($componente->getComponente()->getComponenteitems() as $item) {
            $datos['serviciosSinTituloItinerario']['tipoTarifas'][$tipoTarId]['componentes'][$componente->getId() . '-' . $item->getId()]['titulo'] = $item->getTitulo();
        }
    }
}