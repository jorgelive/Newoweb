<?php

declare(strict_types=1);

namespace App\Oweb\Service;

use App\Oweb\Entity\CotizacionCotcomponente;
use App\Oweb\Entity\CotizacionCotizacion;
use App\Oweb\Entity\CotizacionCotservicio;
use App\Oweb\Entity\CotizacionCottarifa;
use App\Oweb\Entity\ServicioTipocomponente;
use App\Oweb\Entity\ServicioTipotarifadetalle;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Genera datos resumidos para la vista de una cotización.
 *
 * - Mantiene FlashBag de "no encontrado".
 * - Agrupa componentes; si se repiten (entre tipos de tarifa), muestra SOLO las
 *   diferencias (Pax/edades) en línea.
 * - NO muestra validez en etiquetas.
 * - Si hay 2+ variantes y todas comparten el mismo rango de edad ⇒ quita edad (deja solo Pax).
 * - Si hay solo 1 variante, conserva edad (porque explica la diferencia global).
 * - Conteo de repetidos GLOBAL entre tipos de tarifa + normalización suave de títulos.
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

    // =======================
    // Entrada pública
    // =======================

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

                    // 3) Otros servicios (sin título en itinerario)
                    $this->procesarServicioSinItinerario($datos, $componente, $tarifa);
                }
            }
        }

        // 👉 diferencias solo si hay repetidos (GLOBAL) + reglas de edades/pax
        $this->postProcesarDiferencias($datos);

        return $datos;
    }

    // =======================
    // Clasificación
    // =======================

    private function esAlojamiento(CotizacionCottarifa $tarifa, CotizacionCotcomponente $componente): bool
    {
        $tipocompIdTarifa = $tarifa->getTarifa()?->getComponente()?->getTipocomponente()?->getId();
        $esAlojamiento    = $tipocompIdTarifa === ServicioTipocomponente::DB_VALOR_ALOJAMIENTO;

        return $esAlojamiento && $componente->getComponente()?->getComponenteitems()->count() > 0;
    }

    private function tieneTituloItinerario(CotizacionCotcomponente $componente, CotizacionCotservicio $servicio): bool
    {
        $titulo = $this->cotizacionItinerario->getTituloItinerario($componente->getFechahoraInicio(), $servicio);

        return !empty($titulo) && $componente->getComponente()?->getComponenteitems()->count() > 0;
    }

    // =======================
    // Construcción de datos
    // =======================

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
        $label = $this->buildVarianteLabel($det, true);

        foreach ($items as $item) {
            $itemKey = $componente->getId() . '-' . $item->getId();

            if (!isset($tipoTarifaNodo['componentes'][$itemKey])) {
                $tipoTarifaNodo['componentes'][$itemKey] = [
                    'titulo'    => $item->getTitulo(),
                    'variantes' => [],
                ];
            }

            // evita duplicados exactos de variante
            $hash = sha1(($label ?: '∅') . '|' . json_encode($det));
            if (!isset($tipoTarifaNodo['componentes'][$itemKey]['variantes'][$hash])) {
                $tipoTarifaNodo['componentes'][$itemKey]['variantes'][$hash] = [
                    'label'    => $label,
                    'detalles' => $det,
                    'tarifaId' => (int) $tarifa->getId(),
                ];
            }

            // Fechas (tal como estaba)
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
     * (La validez se guarda por si la necesitas, pero NO se usa en etiquetas).
     */
    private function buildTarifaDetalles(CotizacionCottarifa $tarifa): array
    {
        $t = $tarifa->getTarifa();

        $d = [];
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
     * Normaliza títulos para conteo global (trim + colapsar espacios + lowercase).
     */
    private function normalizeTitle(string $title): string
    {
        $title = preg_replace('/\s+/u', ' ', trim($title)) ?? '';
        return mb_strtolower($title, 'UTF-8');
    }

    /**
     * Post-proceso: usa conteo GLOBAL de títulos (across all tipoTarifas) para decidir diferencias.
     */
    private function postProcesarDiferencias(array &$datos): void
    {
        // Con título (por servicio)
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
     * Conteo GLOBAL de títulos de componentes a través de todas las tipoTarifas de un bloque.
     *
     * @param array<int|string, array> $tipoTarifas
     * @return array<string,int> tituloNormalizado => conteo
     */
    private function buildGlobalTitleCounts(array $tipoTarifas): array
    {
        $counts = [];
        foreach ($tipoTarifas as $tt) {
            if (empty($tt['componentes'])) { continue; }
            foreach ($tt['componentes'] as $comp) {
                $titulo = $comp['titulo'] ?? '';
                if ($titulo === '') { continue; }
                $key = $this->normalizeTitle($titulo);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Funde componentes por título dentro de cada tipoTarifa y decide variantes con conteo GLOBAL.
     *
     * - Si el título NO está repetido según $globalCounts ⇒ elimina variantes.
     * - Si está repetido ⇒ conserva variantes y aplica refinamiento:
     *     · si HAY 2+ variantes y todas comparten el mismo rango de edad ⇒ quita edades (deja solo pax)
     *     · si luego el label queda vacío ⇒ se filtra
     *     · nunca quitamos la única variante, porque expresa la diferencia global
     *
     * @param array<int|string, array> $tipoTarifas
     * @param array<string,int>        $globalCounts
     */
    private function mergeAndRefineWithGlobalCounts(array &$tipoTarifas, array $globalCounts): void
    {
        foreach ($tipoTarifas as &$tt) {
            if (empty($tt['componentes'])) { continue; }

            // 1) Fusionar por título dentro de esta tipoTarifa
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

            // 2) Decidir variantes según conteo GLOBAL
            foreach ($byTitle as $title => &$c) {
                $repetido = ($globalCounts[$this->normalizeTitle($title)] ?? 0) > 1;
                $c['repetido'] = $repetido;

                if (!$repetido) {
                    unset($c['variantes']);
                    continue;
                }

                if (!empty($c['variantes']) && is_array($c['variantes'])) {
                    $vars = array_values($c['variantes']);

                    // ¿todas las variantes comparten el mismo rango de edad?
                    $ageKeys = [];
                    foreach ($vars as $v) {
                        $d = $v['detalles'] ?? [];
                        $emin = $d['edadMin'] ?? null; $emax = $d['edadMax'] ?? null;
                        $ageKeys[] = sprintf('%s|%s', $emin === null ? '∅' : (string)(int)$emin, $emax === null ? '∅' : (string)(int)$emax);
                    }
                    $sameAge   = count(array_unique($ageKeys)) <= 1;
                    $varsCount = count($vars);

                    // ✅ Solo quitamos edades si hay 2+ variantes y comparten el mismo rango
                    if ($sameAge && $varsCount > 1) {
                        foreach ($vars as &$v) {
                            $d = $v['detalles'] ?? [];
                            $v['label'] = $this->buildVarianteLabel($d, false); // sin edades
                        }
                        unset($v);
                    }
                    // Si hay 1 sola variante, mantenemos la etiqueta original (con edad)

                    // Filtra etiquetas vacías y de-duplica por label
                    $vars = array_values(array_filter($vars, fn($v) => !empty($v['label'])));
                    $seen = []; $dedup = [];
                    foreach ($vars as $v) {
                        $lab = $v['label'];
                        if (!isset($seen[$lab])) {
                            $seen[$lab] = true;
                            $dedup[] = $v;
                        }
                    }
                    $c['variantes'] = $dedup;

                    if (empty($c['variantes'])) {
                        unset($c['variantes']);
                    }
                }
            }
            unset($c);

            // 3) Reemplazar componentes por array indexado
            $tt['componentes'] = array_values($byTitle);
        }
        unset($tt);
    }

    // =======================
    // Formateadores
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
