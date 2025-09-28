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
 * - Agrupa componentes y, si se repiten, muestra solo las diferencias (variantes) en línea.
 * - Oculta validez en las diferencias.
 * - Si todas las repeticiones comparten el mismo rango de edad, oculta edades y deja solo tipo de pax.
 * - Si además el tipo de pax es igual en todas, no muestra diferencias.
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

                    // 1) Alojamientos (no tocamos variantes aquí)
                    if ($this->esAlojamiento($tarifa, $componente)) {
                        $this->procesarAlojamiento($datos, $componente, $tarifa);
                        continue;
                    }

                    // 2) Servicios con título en el itinerario
                    if ($this->tieneTituloItinerario($componente, $servicio)) {
                        $this->procesarServicioConItinerario($datos, $servicio, $componente, $tarifa, $fotos);
                        continue;
                    }

                    // 3) Otros servicios (sin título de itinerario)
                    $this->procesarServicioSinItinerario($datos, $componente, $tarifa);
                }
            }
        }

        // 👉 post-proceso: dejar variantes solo si hay repetidos y aplicar reglas de edades/pax
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
        $label = $this->buildVarianteLabel($det, true); // incluir edades por defecto

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

            // ---- manejo de fechas (como lo tenías)
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
        // NO usaremos validez para etiquetas, pero lo dejamos por si se utilizara en otro lado
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
    // Post-proceso de variantes
    // =======================

    /**
     * Para cada bloque (con/sin itinerario), fusiona componentes por título,
     * deja variantes solo si hay repetidos y aplica regla extra:
     * - Si todas las variantes comparten el mismo rango de edad, no mostrar edades (solo pax).
     * - Si además todo el pax es igual, no mostrar variantes.
     */
    private function postProcesarDiferencias(array &$datos): void
    {
        // con título
        if (!empty($datos['serviciosConTituloItinerario'])) {
            foreach ($datos['serviciosConTituloItinerario'] as &$serv) {
                if (empty($serv['tipoTarifas'])) { continue; }
                $this->marcarFusionarYRefinar($serv['tipoTarifas']);
            }
            unset($serv);
        }

        // sin título
        if (!empty($datos['serviciosSinTituloItinerario']['tipoTarifas'])) {
            $this->marcarFusionarYRefinar($datos['serviciosSinTituloItinerario']['tipoTarifas']);
        }
    }

    /**
     * $tipoTarifas: array[tipoTarifaId => ['componentes' => [ key => ['titulo'=>..., 'variantes'=>...] ]]]
     * Pasos:
     * 1) Contar cuántas veces aparece cada título (en TODO el grupo).
     * 2) Fusionar componentes por título (merge de 'variantes').
     * 3) Si el título no se repite → quitar variantes.
     * 4) Si se repite → si todas las variantes comparten el MISMO rango de edad,
     *    rehacer etiquetas sin edad; si además el pax es el mismo en todas → quitar variantes.
     * 5) Normalizar a array indexado y limpiar etiquetas vacías/duplicadas.
     */
    private function marcarFusionarYRefinar(array &$tipoTarifas): void
    {
        // 1) Conteo global por título
        $conteo = [];
        foreach ($tipoTarifas as $tt) {
            if (empty($tt['componentes'])) { continue; }
            foreach ($tt['componentes'] as $comp) {
                $titulo = $comp['titulo'] ?? '';
                if ($titulo === '') { continue; }
                $conteo[$titulo] = ($conteo[$titulo] ?? 0) + 1;
            }
        }

        // 2) Fusionar por título dentro de cada tipoTarifa
        foreach ($tipoTarifas as &$tt) {
            if (empty($tt['componentes'])) { continue; }

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
                            $byTitle[$titulo]['variantes'][$hash] = $var;
                        }
                    }
                }
            }

            // 3) Podar/Refinar por título
            foreach ($byTitle as $title => &$c) {
                $repetido = ($conteo[$title] ?? 0) > 1;
                $c['repetido'] = $repetido;

                if (!$repetido) {
                    // no hay diferencia real, quita variantes
                    unset($c['variantes']);
                    continue;
                }

                // Si repetido, aplicar la lógica de edades/pax
                if (!empty($c['variantes']) && is_array($c['variantes'])) {
                    // Normalize list (pueden venir como asociativo por hash)
                    $vars = array_values($c['variantes']);

                    // --- detectar si TODAS comparten el mismo rango de edad ---
                    $ageKeys = [];
                    $paxKeys = [];
                    foreach ($vars as $v) {
                        $d = $v['detalles'] ?? [];
                        $emin = $d['edadMin'] ?? null; $emax = $d['edadMax'] ?? null;
                        $ageKeys[] = sprintf('%s|%s', $emin === null ? '∅' : (string)(int)$emin, $emax === null ? '∅' : (string)(int)$emax);

                        $pax = $d['tipoPaxTitulo'] ?? ($d['tipoPaxNombre'] ?? '∅');
                        $paxKeys[] = (string)$pax;
                    }
                    $ageUnique = array_unique($ageKeys);
                    $paxUnique = array_unique($paxKeys);
                    $sameAge   = count($ageUnique) <= 1; // mismo rango de edad (o todos sin rango)
                    $samePax   = count($paxUnique) <= 1; // mismo pax

                    // --- si todas comparten edad, rehacer etiquetas sin edad ---
                    if ($sameAge) {
                        foreach ($vars as &$v) {
                            $d = $v['detalles'] ?? [];
                            $v['label'] = $this->buildVarianteLabel($d, false); // sin edades
                        }
                        unset($v);
                    }

                    // Quitar variantes con etiqueta vacía
                    $vars = array_values(array_filter($vars, fn($v) => !empty($v['label'])));

                    // Deduplicar por etiqueta
                    $seen = [];
                    $dedup = [];
                    foreach ($vars as $v) {
                        $lab = $v['label'];
                        if (!isset($seen[$lab])) {
                            $seen[$lab] = true;
                            $dedup[] = $v;
                        }
                    }
                    $vars = $dedup;

                    // Si después de todo, todas las etiquetas son iguales (mismo pax) y no hay edades
                    if ($samePax) {
                        // Si hay 1 sola etiqueta, realmente no hay diferencia => quitar variantes
                        if (count($vars) <= 1) {
                            $c['variantes'] = [];
                        } else {
                            // múltiples iguales no deberían quedar tras deduplicar; fallback:
                            $c['variantes'] = [$vars[0]];
                        }
                    } else {
                        $c['variantes'] = $vars;
                    }

                    // Si quedó vacío, elimina variantes
                    if (empty($c['variantes'])) {
                        unset($c['variantes']);
                    }
                }
            }
            unset($c);

            // 5) Reemplaza componentes por la versión fusionada (array indexado)
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
