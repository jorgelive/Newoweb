<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CotizacionCotcomponente;
use App\Entity\CotizacionCotizacion;
use App\Entity\CotizacionCotservicio;
use App\Entity\CotizacionCottarifa;
use App\Entity\ServicioTipocomponente;
use App\Entity\ServicioTipotarifadetalle;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Genera datos resumidos para la vista de una cotización.
 * - Mantiene la lógica de FlashBag (error si no se encuentra el ID).
 * - Null-safe y helpers para evitar duplicación.
 * - Ordena "tipoTarifas" con ksort al final de cada servicio con título.
 */
final class CotizacionResumen
{
    private EntityManagerInterface $entityManager;
    private TranslatorInterface $translator;
    private CotizacionItinerario $cotizacionItinerario;
    private RequestStack $requestStack;

    public function __construct(
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        CotizacionItinerario $cotizacionItinerario,
        RequestStack $requestStack
    ) {
        $this->entityManager        = $entityManager;
        $this->translator           = $translator;
        $this->cotizacionItinerario = $cotizacionItinerario;
        $this->requestStack         = $requestStack;
    }

    /**
     * Obtiene los datos de una cotización por su ID.
     */
    public function getDatosFromId(int $id): array
    {
        $cotizacion = $this->entityManager
            ->getRepository(CotizacionCotizacion::class)
            ->find($id);

        if (!$cotizacion) {
            // Mantener FlashBag tal cual
            $this->requestStack->getSession()->getFlashBag()->add(
                'error',
                sprintf('No se puede encontrar el objeto con el identificador : %s', $id)
            );
            return [];
        }

        return $this->getDatos($cotizacion);
    }

    /**
     * Procesa una cotización y genera los datos de la vista.
     */
    public function getDatos(CotizacionCotizacion $cotizacion): array
    {
        $datos = [];

        $servicios = $cotizacion->getCotservicios();
        if ($servicios->count() === 0) {
            return $datos;
        }

        /** @var CotizacionCotservicio $servicio */
        foreach ($servicios as $servicio) {
            $fotos = $this->cotizacionItinerario->getFotos($servicio); // Collection

            $componentes = $servicio->getCotcomponentes();
            if ($componentes->count() === 0) {
                continue;
            }

            /** @var CotizacionCotcomponente $componente */
            foreach ($componentes as $componente) {
                $tarifas = $componente->getCottarifas();
                if ($tarifas->count() === 0) {
                    continue;
                }

                /** @var CotizacionCottarifa $tarifa */
                foreach ($tarifas as $tarifa) {
                    // Si quieres omitir ciertas tarifas, puedes descomentar:
                    // if ($tarifa->getTipotarifa()->isOcultoenresumen()) { continue; }

                    // 1) Alojamientos
                    if ($this->esAlojamiento($tarifa, $componente)) {
                        $this->procesarAlojamiento($datos, $componente, $tarifa);
                        continue;
                    }

                    // 2) Servicios con título de itinerario
                    if ($this->tieneTituloItinerario($componente, $servicio)) {
                        $this->procesarServicioConItinerario($datos, $servicio, $componente, $tarifa, $fotos);
                        continue;
                    }

                    // 3) Servicios sin título de itinerario
                    $this->procesarServicioSinItinerario($datos, $componente, $tarifa);
                }
            }
        }

        return $datos;
    }

    /**
     * Determina si la tarifa corresponde a alojamiento y el componente tiene items.
     */
    private function esAlojamiento(CotizacionCottarifa $tarifa, CotizacionCotcomponente $componente): bool
    {
        $tipocompIdTarifa = $tarifa->getTarifa()?->getComponente()?->getTipocomponente()?->getId();
        $esAlojamiento    = $tipocompIdTarifa === ServicioTipocomponente::DB_VALOR_ALOJAMIENTO;

        return $esAlojamiento && $componente->getComponente()?->getComponenteitems()->count() > 0;
    }

    /**
     * Determina si un componente tiene título en el itinerario y posee items.
     */
    private function tieneTituloItinerario(CotizacionCotcomponente $componente, CotizacionCotservicio $servicio): bool
    {
        $titulo = $this->cotizacionItinerario->getTituloItinerario($componente->getFechahoraInicio(), $servicio);

        return !empty($titulo) && $componente->getComponente()?->getComponenteitems()->count() > 0;
    }

    /**
     * Procesa un alojamiento.
     */
    private function procesarAlojamiento(array &$datos, CotizacionCotcomponente $componente, CotizacionCottarifa $tarifa): void
    {
        $tarifaId  = (int) $tarifa->getId();
        $items     = $componente->getComponente()?->getComponenteitems() ?? [];
        $tempItems = [];

        foreach ($items as $item) {
            $tempItems[] = $item->getTitulo();
        }

        $datos['alojamientos'][$tarifaId]['titulo'] = \implode(', ', $tempItems);

        if (!empty($tarifa->getTarifa()?->getTitulo())) {
            $datos['alojamientos'][$tarifaId]['tarifaTitulo'] = $tarifa->getTarifa()->getTitulo();
        }

        if (!empty($tarifa->getProvider())) {
            $datos['alojamientos'][$tarifaId]['proveedor'] = $tarifa->getProvider();
        }

        $datos['alojamientos'][$tarifaId]['fechahoraInicio'] = $componente->getFechahoraInicio();
        $datos['alojamientos'][$tarifaId]['fechahoraFin']    = $componente->getFechahoraFin();
        $datos['alojamientos'][$tarifaId]['fechaInicio']     = $componente->getFechaInicio();
        $datos['alojamientos'][$tarifaId]['fechaFin']        = $componente->getFechaFin();
        $datos['alojamientos'][$tarifaId]['tipoTarifa']      = $tarifa->getTipotarifa();

        // Detalles (solo los de tipo "DETALLES")
        foreach ($tarifa->getCottarifadetalles() as $detalle) {
            if ($detalle->getTipotarifaDetalle()?->getId() === ServicioTipotarifadetalle::DB_VALOR_DETALLES) {
                $datos['alojamientos'][$tarifaId]['detalles'][] = $detalle->getDetalle();
            }
        }

        // Duración (noches)
        $datos['alojamientos'][$tarifaId]['duracionStr'] = $this->formatearDuracionNoches(
            $componente->getFechaInicio(),
            $componente->getFechaFin()
        );
    }

    /**
     * Procesa un servicio con título en el itinerario.
     */
    private function procesarServicioConItinerario(
        array &$datos,
        CotizacionCotservicio $servicio,
        CotizacionCotcomponente $componente,
        CotizacionCottarifa $tarifa,
        Collection $fotos
    ): void {
        $servicioId = (int) $servicio->getId();
        $tipoTarId  = (int) $tarifa->getTipotarifa()->getId();

        // Asegura la ruta y obtén referencia al array objetivo donde setear meta de tipotarifa
        $tipoTarifaNodo =& $this->ensurePathArray(
            $datos,
            ['serviciosConTituloItinerario', $servicioId, 'tipoTarifas', $tipoTarId]
        );

        // Meta de tipo tarifa
        $this->setTipoTarifaMeta($tipoTarifaNodo, $tarifa);

        // Items por componente
        $items = $componente->getComponente()?->getComponenteitems() ?? [];
        foreach ($items as $item) {
            $itemKey = $componente->getId() . '-' . $item->getId();
            $tipoTarifaNodo['componentes'][$itemKey]['titulo'] = $item->getTitulo();

            // Prep bandera de fechas diferentes
            $datos['serviciosConTituloItinerario'][$servicioId]['fechahorasdiferentes'] ??= false;

            // Agendable: compara fechas servicio vs componente y agrupa por fecha
            if ($tarifa->getTarifa()?->getComponente()?->getTipocomponente()?->isAgendable()) {
                $fechaComp = $componente->getFechahoraInicio();
                $fechaServ = $servicio->getFechahoraInicio();

                if ($fechaComp && $fechaServ && $fechaComp->format('Y/m/d H:i') !== $fechaServ->format('Y/m/d H:i')) {
                    $datos['serviciosConTituloItinerario'][$servicioId]['fechahorasdiferentes'] = true;
                }

                if ($fechaComp) {
                    $fechaKey = $fechaComp->format('Ymd');

                    $fechaNodo =& $this->ensurePathArray(
                        $datos,
                        ['serviciosConTituloItinerario', $servicioId, 'fechas', $fechaKey]
                    );

                    $fechaNodo['fecha'] = $fechaComp;
                    $fechaNodo['items'][$itemKey]['titulo'] = $item->getTitulo();
                    $fechaNodo['items'][$itemKey]['fechahoraInicio'] = $fechaComp;
                }
            }
        }

        // Ordena tipoTarifas por clave una vez
        \ksort($datos['serviciosConTituloItinerario'][$servicioId]['tipoTarifas']);

        // Meta del servicio
        $datos['serviciosConTituloItinerario'][$servicioId]['tituloItinerario'] = $this->cotizacionItinerario->getTituloItinerario(
            $componente->getFechahoraInicio(),
            $servicio
        );
        $datos['serviciosConTituloItinerario'][$servicioId]['fotos']           = $fotos;
        $datos['serviciosConTituloItinerario'][$servicioId]['fechahoraInicio'] = $servicio->getFechahoraInicio();
        $datos['serviciosConTituloItinerario'][$servicioId]['fechahoraFin']    = $servicio->getFechahoraFin();
        $datos['serviciosConTituloItinerario'][$servicioId]['fechaInicio']     = $servicio->getFechaInicio();
        $datos['serviciosConTituloItinerario'][$servicioId]['fechaFin']        = $servicio->getFechaFin();

        // Duración (horas o días)
        $datos['serviciosConTituloItinerario'][$servicioId]['duracionStr'] = $this->formatearDuracionServicio(
            $servicio->getFechahoraInicio(),
            $servicio->getFechahoraFin(),
            $servicio->getFechaInicio(),
            $servicio->getFechaFin()
        );
    }

    /**
     * Procesa un servicio sin título en el itinerario.
     */
    private function procesarServicioSinItinerario(array &$datos, CotizacionCotcomponente $componente, CotizacionCottarifa $tarifa): void
    {
        $tipoTarId = (int) $tarifa->getTipotarifa()->getId();

        // Asegura la ruta y obtén referencia al array objetivo donde setear meta de tipotarifa
        $tipoTarifaNodo =& $this->ensurePathArray(
            $datos,
            ['serviciosSinTituloItinerario', 'tipoTarifas', $tipoTarId]
        );

        // Meta de tipo tarifa
        $this->setTipoTarifaMeta($tipoTarifaNodo, $tarifa);

        // Items
        $items = $componente->getComponente()?->getComponenteitems() ?? [];
        foreach ($items as $item) {
            $key = $componente->getId() . '-' . $item->getId();
            $tipoTarifaNodo['componentes'][$key]['titulo'] = $item->getTitulo();
        }
    }

    // =======================
    // Helpers
    // =======================

    /**
     * Garantiza que exista un camino de arrays dentro de $root y devuelve
     * una REFERENCIA (&) al último nodo.
     *
     * @param array<string,mixed> $root
     * @param list<int|string>    $path
     * @return array<string,mixed> referencia al último nodo
     */
    private function &ensurePathArray(array &$root, array $path): array
    {
        $ref =& $root;
        foreach ($path as $k) {
            if (!isset($ref[$k]) || !\is_array($ref[$k])) {
                $ref[$k] = [];
            }
            $ref =& $ref[$k];
        }
        return $ref;
    }

    /**
     * Asigna metadatos básicos de tipo tarifa.
     * @param array<string,mixed> $target
     */
    private function setTipoTarifaMeta(array &$target, CotizacionCottarifa $tarifa): void
    {
        $tt = $tarifa->getTipotarifa();
        $target['tituloTipotarifa'] = $tt->getTitulo();
        $target['colorTipotarifa']  = $tt->getListacolor();
        $target['claseTipotarifa']  = $tt->getListaclase();
    }

    /**
     * "3 noches" / "1 noche"
     */
    private function formatearDuracionNoches(?\DateTimeInterface $inicio, ?\DateTimeInterface $fin): string
    {
        if (!$inicio || !$fin) {
            return '';
        }
        $dias = (int) $inicio->diff($fin)->format('%d');
        $unidad = ($dias === 1)
            ? $this->translator->trans('noche', [], 'messages')
            : $this->translator->trans('noches', [], 'messages');

        return $dias . ' ' . $unidad;
    }

    /**
     * "5 horas" / "1 hora" o, si >=24h, "3 dias" / "1 dia".
     * Usa horas TOTALES (no solo el resto %h).
     */
    private function formatearDuracionServicio(
        ?\DateTimeInterface $fechaHoraInicio,
        ?\DateTimeInterface $fechaHoraFin,
        ?\DateTimeInterface $fechaInicio,
        ?\DateTimeInterface $fechaFin
    ): string {
        if ($fechaHoraInicio && $fechaHoraFin) {
            $interval = $fechaHoraInicio->diff($fechaHoraFin);
            // horas totales (días*24 + horas)
            $horasTotales = (int) ($interval->days * 24 + $interval->h);

            if ($horasTotales >= 24) {
                // Pasar a días usando fechas puras si están disponibles
                if ($fechaInicio && $fechaFin) {
                    $dias = (int) $fechaInicio->diff($fechaFin)->format('%d');
                } else {
                    $dias = (int) \floor($horasTotales / 24);
                }
                $unidad = ($dias > 1)
                    ? $this->translator->trans('dias', [], 'messages')
                    : $this->translator->trans('dia', [], 'messages');
                return $dias . ' ' . $unidad;
            }

            $unidadHoras = ($horasTotales === 1)
                ? $this->translator->trans('hora', [], 'messages')
                : $this->translator->trans('horas', [], 'messages');

            return $horasTotales . ' ' . $unidadHoras;
        }

        // Fallback a días si no hay hora exacta
        if ($fechaInicio && $fechaFin) {
            $dias = (int) $fechaInicio->diff($fechaFin)->format('%d');
            $unidad = ($dias > 1)
                ? $this->translator->trans('dias', [], 'messages')
                : $this->translator->trans('dia', [], 'messages');
            return $dias . ' ' . $unidad;
        }

        return '';
    }
}
