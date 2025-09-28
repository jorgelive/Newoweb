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
 * - Mantiene FlashBag de "no encontrado".
 * - Agrupa componentes; si se repiten, muestra solo diferencias (Pax/edades) en línea.
 * - No muestra validez en etiquetas.
 * - Si todas las variantes comparten el mismo rango de edad => oculta edades (solo Pax).
 * - Si además todo el Pax es igual => no muestra variantes (no hay diferencia real).
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
                    // if ($tarifa->getTipotarifa()->isOcultoenresumen()) { continue; }

                    // 1) Alojamientos
                    if ($this->esAlojamiento($tarifa, $componente)) {
                        $this->procesarAlojamiento($datos, $componente, $tarifa);
                        continue;
                    }

                    // 2) Servicios con título en el itinerario
                    if ($this->tieneTituloItinerario($componente, $servicio)) {
                        $this->procesarServicioConItinerario($datos, $servicio, $componente, $tarifa, $fotos);
                        continue;
                    }

                    // 3) Otros servicios
                    $this->procesarServicioSinItinerario($datos, $componente, $tarifa);
                }
            }
        }

        // Post-proceso: diferencias solo si hay repetidos (global) + reglas de edades/pax
        $this->postProcesarDiferencias($datos);

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

        foreach ($tarifa->getCottarifadetalles() as $detalle) {
            if ($detalle->getTipotarifaDetalle()?->getId() === ServicioTipotarifadetalle::DB_VALOR_DETALLES) {
                $datos['alojamientos'][$tarifaId]['detalles'][] = $detalle->getDetalle();
            }
        }

        $datos['alojamientos'][$tarifaId]['duracionStr'] = $this->formatearDuracionNoches(
            $componente->getFechaInicio(),
            $componente->getFechaFin()
        );
    }

    /**
     * Procesa un servicio con título en el itinerario (agregando variantes por ítem).
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

        $tipoTarifaNodo =& $this->ensurePathArray(
            $datos,
            ['serviciosConTituloItinerario', $servicioId, 'tipoTarifas', $tipoTarId]
        );

        $this->setTipoTarifaMeta($tipoTarifaNodo, $tarifa);

        // Variantes por ítem (solo diferencias)
        $items = $componente->getComponente()?->getComponenteitems() ?? [];
        $det   = $this->buildTarifaDetalles($tarifa);
        $label = $this->buildVarianteLabel($det, true); // incluye edades inicialmente

        foreach ($items as $item) {
            $itemKey = $componente->getId() . '-' . $item->getId();

            if (!isset($tipoTarifaNodo['componentes'][$itemKey])) {
                $tipoTarifaNodo['componentes'][$itemKey] = [
                    'titulo'    => $item->getTitulo(),
                    'variantes' => [],
                ];
            }

            // evita duplicados exactos
            $hash = sha1(($label ?: '∅') . '|' . json_encode($det));
            if (!isset($tipoTarifaNodo['componentes'][$itemKey]['variantes'][$hash])) {
                $tipoTarifaNodo['componentes'][$itemKey]['variantes'][$hash] = [
                    'label'    => $label,
                    'detalles' => $det,
                    'tarifaId' => (int) $tarifa->getId(),
                ];
            }

            // ---- manejo de fechas
            $datos['serviciosConTituloItinerario'][$servicioId]['fechahorasdiferentes'] ??= false;

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

        \ksort($datos['serviciosConTituloItinerario'][$servicioId]['tipoTarifas']);

        $datos['serviciosConTituloItinerario'][$servicioId]['tituloItinerario'] = $this->cotizacionItinerario->getTituloItinerario(
            $componente->getFechahoraInicio(),
            $servicio
        );
        $datos['serviciosConTituloItinerario'][$servicioId]['fotos']           = $fotos;
        $datos['serviciosConTituloItinerario'][$servicioId]['fechahoraInicio'] = $servicio->getFechahoraInicio();
        $datos['serviciosConTituloItinerario'][$servicioId]['fechahoraFin']    = $servicio->getFechahoraFin();
        $datos['serviciosConTituloItinerario'][$servicioId]['fechaInicio']     = $servicio->getFechaInicio();
        $datos['serviciosConTituloItinerario'][$servicioId]['fechaFin']        = $servicio->getFechaFin();

        $datos['serviciosConTituloItinerario'][$servicioId]['duracionStr'] = $this->formatearDuracionServicio(
            $servicio->getFechahoraInicio(),
            $servicio->getFechahoraFin(),
            $servicio->getFechaInicio(),
            $servicio->getFechaFin()
        );
    }

    /**
     * Procesa servicios sin título de itinerario (otros servicios) con variantes.
     */
    private function procesarServicioSinItinerario(array &$datos, CotizacionCotcomponente $componente, CotizacionCottarifa $tarifa): void
    {
        $tipoTarId = (int) $tarifa->getTipotarifa()->getId();

        $tipoTarifaNodo =& $this->ensurePathArray(
            $datos,
            ['serviciosSinTituloItinerario', 'tipoTarifas', $tipoTarId]
        );

        $this->setTipoTarifaMeta($tipoTarifaNodo, $tarifa);

        $items = $componente->getComponente()?->getComponenteitems() ?? [];
        $det   = $this->buildTarifaDetalles($tarifa);
        $label = $this->buildVarianteLabel($det, true);

        foreach ($items as $item) {
            $key = $componente->getId() . '-' . $item->getId();

            if (!isset($tipoTarifaNodo['componentes'][$key])) {
                $tipoTarifaNodo['componentes'][$key] = [
                    'titulo'    => $item->getTitulo(),
                    'variantes' => [],
                ];
            }

            $hash = sha1(($label ?: '∅') . '|' . json_encode($det));
            if (!isset($tipoTarifaNodo['componentes'][$key]['variantes'][$hash])) {
                $tipoTarifaNodo['componentes'][$key]['variantes'][$hash] = [
                    'label'    => $label,
                    'detalles' => $det,
                    'tarifaId' => (int) $tarifa->getId(),
                ];
            }
        }
    }

    // =======================
    // Helpers de datos
    // =======================

    private function maybe(array &$arr, string $key, mixed $value): void
    {
        if ($value !== null && $value !== '' && $value !== []) {
            $arr[$key] = $value;
        }
    }

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

    private function setTipoTarifaMeta(array &$target, CotizacionCottarifa $tarifa): void
    {
        $tt = $tarifa->getTipotarifa();
        $target['tituloTipotarifa'] = $tt->getTitulo();
        $target['colorTipotarifa']  = $tt->getListacolor();
        $target['claseTipotarifa']  = $tt->getListaclase();
    }

    /**
     * Devuelve los detalles “comparables” de una tarifa.
     */
    private function buildTarifaDetalles(CotizacionCottarifa $tarifa): array
    {
        $t = $tarifa->getTarifa();

        $d = [];
        // Validez NO se usa en etiquetas, pero no estorba si luego lo necesitas
        $this->maybe($d, 'validezInicio', $t?->getValidezInicio());
        $this->maybe($d, 'validezFin',    $t?->getValidezFin());
        $this->maybe($d, 'capacidadMin',  $t?->getCapacidadmin());
        $this->maybe($d, 'capacidadMax',  $t?->getCapacidadmax());
        $this->maybe($d, 'edadMin',       $t?->getEdadmin());
        $this->maybe($d, 'edadMax',       $t?->getEdadmax());

        if ($tp = $t?->getTipopax()) {
            $d['tipoPaxId']     = $tp->getId();
            $d['tipoPaxNombre'] = $tp->getNombre();
            $d['tipoPaxTitulo'] = $tp->getTitulo();
        }
        return $d;
    }

    /**
     * Construye etiqueta de variante a partir de detalles.
     * $includeAges = true → incluye edad; false → sin edad.
     * Nunca incluye validez.
     */
    private function buildVarianteLabel(array $det, bool $includeAges = true): string
    {
        $partes = [];

        if (!empty($det['tipoPaxTitulo'])) {
            $partes[] = $det['tipoPaxTitulo'];
        } elseif (!empty($det['tipoPaxNombre'])) {
            $partes[] = $det['tipoPaxNombre'];
        }

        if ($includeAges && (isset($det['edadMin']) || isset($det['edadMax']))) {
            $emin = $det['edadMin'] ?? null;
            $emax = $det['edadMax'] ?? null;

            if ($emin !== null && $emax !== null)      { $partes[] = sprintf('%d–%d años', (int)$emin, (int)$emax); }
            elseif ($emin !== null)                    { $partes[] = sprintf('≥ %d años', (int)$emin); }
            elseif ($emax !== null)                    { $partes[] = sprintf('≤ %d años', (int)$emax); }
        }

        return $partes ? implode(' · ', $partes) : '';
    }

    // =======================
    // Post-proceso con repetidos GLOBAL
    // =======================

    /**
     * Post-proceso: usa conteo GLOBAL de títulos (across all tipoTarifas) para decidir diferencias.
     */
    private function postProcesarDiferencias(array &$datos): void
    {
        // Servicios con título (por servicio)
        if (!empty($datos['serviciosConTituloItinerario'])) {
            foreach ($datos['serviciosConTituloItinerario'] as &$serv) {
                if (empty($serv['tipoTarifas'])) { continue; }
                $globalCounts = $this->buildGlobalTitleCounts($serv['tipoTarifas']);
                $this->mergeAndRefineWithGlobalCounts($serv['tipoTarifas'], $globalCounts);
            }
            unset($serv);
        }

        // Otros servicios (bloque completo)
        if (!empty($datos['serviciosSinTituloItinerario']['tipoTarifas'])) {
            $tt = &$datos['serviciosSinTituloItinerario']['tipoTarifas'];
            $globalCounts = $this->buildGlobalTitleCounts($tt);
            $this->mergeAndRefineWithGlobalCounts($tt, $globalCounts);
        }
    }

    /**
     * Construye un conteo GLOBAL de títulos de componentes a través de todas las tipoTarifas de un bloque.
     *
     * @param array<int|string, array> $tipoTarifas
     * @return array<string,int> titulo => conteo
     */
    private function buildGlobalTitleCounts(array $tipoTarifas): array
    {
        $counts = [];
        foreach ($tipoTarifas as $tt) {
            if (empty($tt['componentes'])) { continue; }
            foreach ($tt['componentes'] as $comp) {
                $titulo = $comp['titulo'] ?? '';
                if ($titulo === '') { continue; }
                $counts[$titulo] = ($counts[$titulo] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Funde componentes por título dentro de cada tipoTarifa y decide variantes con conteo GLOBAL.
     *
     * - Si el título NO está repetido según $globalCounts ⇒ elimina variantes.
     * - Si está repetido ⇒ conserva variantes y aplica refinamiento:
     *     · si todas las variantes comparten el mismo rango de edad ⇒ quita edades (deja solo pax)
     *     · si además todo el pax es igual ⇒ elimina variantes (no hay diferencia real)
     *
     * @param array<int|string, array> $tipoTarifas
     * @param array<string,int>        $globalCounts
     */
    private function mergeAndRefineWithGlobalCounts(array &$tipoTarifas, array $globalCounts): void
    {
        foreach ($tipoTarifas as &$tt) {
            if (empty($tt['componentes'])) { continue; }

            // Fusionar por título dentro de esta tipoTarifa
            $byTitle = []; // titulo => comp fusionado
            foreach ($tt['componentes'] as $comp) {
                $titulo = $comp['titulo'] ?? '';
                if ($titulo === '') { continue; }

                if (!isset($byTitle[$titulo])) {
                    $byTitle[$titulo] = $comp;
                    if (!empty($byTitle[$titulo]['variantes']) && is_array($byTitle[$titulo]['variantes'])) {
                        $byTitle[$titulo]['variantes'] = $byTitle[$titulo]['variantes'];
                    }
                } else {
                    if (!empty($comp['variantes']) && is_array($comp['variantes'])) {
                        if (empty($byTitle[$titulo]['variantes']) || !is_array($byTitle[$titulo]['variantes'])) {
                            $byTitle[$titulo]['variantes'] = [];
                        }
                        foreach ($comp['variantes'] as $hash => $var) {
                            $byTitle[$titulo]['variantes'][$hash] = $var; // de-dup por hash
                        }
                    }
                }
            }

            // Decidir variantes con base en conteo GLOBAL
            foreach ($byTitle as $title => &$c) {
                $repetido = ($globalCounts[$title] ?? 0) > 1;
                $c['repetido'] = $repetido;

                if (!$repetido) {
                    unset($c['variantes']);
                    continue;
                }

                // Refinamiento de variantes
                if (!empty($c['variantes']) && is_array($c['variantes'])) {
                    $vars = array_values($c['variantes']);

                    // comparar edades/pax
                    $ageKeys = [];
                    $paxKeys = [];
                    foreach ($vars as $v) {
                        $d = $v['detalles'] ?? [];
                        $emin = $d['edadMin'] ?? null; $emax = $d['edadMax'] ?? null;
                        $ageKeys[] = sprintf('%s|%s', $emin === null ? '∅' : (string)(int)$emin, $emax === null ? '∅' : (string)(int)$emax);
                        $pax = $d['tipoPaxTitulo'] ?? ($d['tipoPaxNombre'] ?? '∅');
                        $paxKeys[] = (string)$pax;
                    }
                    $sameAge = count(array_unique($ageKeys)) <= 1;
                    $samePax = count(array_unique($paxKeys)) <= 1;

                    // si todas las edades iguales ⇒ rehacer etiqueta sin edades
                    if ($sameAge) {
                        foreach ($vars as &$v) {
                            $d = $v['detalles'] ?? [];
                            $v['label'] = $this->buildVarianteLabel($d, false);
                        }
                        unset($v);
                    }

                    // filtra etiquetas vacías y de-duplica por label
                    $vars = array_values(array_filter($vars, fn($v) => !empty($v['label'])));
                    $seen = []; $dedup = [];
                    foreach ($vars as $v) {
                        $lab = $v['label'];
                        if (!isset($seen[$lab])) {
                            $seen[$lab] = true;
                            $dedup[] = $v;
                        }
                    }
                    $vars = $dedup;

                    // si al final todos los pax son iguales y no hay edades ⇒ sin diferencia real
                    if ($samePax && !empty($vars)) {
                        if (count($vars) <= 1) {
                            $c['variantes'] = [];
                        } else {
                            $c['variantes'] = [$vars[0]];
                        }
                    } else {
                        $c['variantes'] = $vars;
                    }

                    if (empty($c['variantes'])) {
                        unset($c['variantes']);
                    }
                }
            }
            unset($c);

            // Reemplaza componentes por la versión fusionada (array indexado)
            $tt['componentes'] = array_values($byTitle);
        }
        unset($tt);
    }

    // =======================
    // Formateadores de duración
    // =======================

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
     * Usa horas totales (días*24 + horas).
     */
    private function formatearDuracionServicio(
        ?\DateTimeInterface $fechaHoraInicio,
        ?\DateTimeInterface $fechaHoraFin,
        ?\DateTimeInterface $fechaInicio,
        ?\DateTimeInterface $fechaFin
    ): string {
        if ($fechaHoraInicio && $fechaHoraFin) {
            $interval = $fechaHoraInicio->diff($fechaHoraFin);
            $horasTotales = (int) ($interval->days * 24 + $interval->h);

            if ($horasTotales >= 24) {
                $dias = ($fechaInicio && $fechaFin)
                    ? (int) $fechaInicio->diff($fechaFin)->format('%d')
                    : (int) \floor($horasTotales / 24);

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
