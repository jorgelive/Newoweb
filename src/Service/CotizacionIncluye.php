<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ServicioTipocomponente;
use App\Entity\CotizacionCotizacion;
use Doctrine\ORM\EntityManagerInterface; // (por si luego lo necesitas)
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Genera la estructura de "incluidos" e "internoIncluidos" para una cotización.
 * - Mantiene la lógica de FlashBag tal como en tu versión original.
 * - Evita números mágicos (-4, -1) usando constantes.
 * - Usa helpers privados para reducir duplicación.
 * - Ordena buckets (hotel → normal → varios) sin duplicar/unset.
 * - Ordena tipotarifas al final (ksort una sola vez).
 * - Compara entidades por identidad (no por ID).
 * - Normaliza monto a float para comparaciones.
 */
final class CotizacionIncluye
{
    /** Bucket especial: alojamientos al inicio */
    private const BUCKET_HOTEL  = -4;
    /** Bucket especial: varios al final */
    private const BUCKET_VARIOS = -1;

    private TranslatorInterface $translator;
    private CotizacionItinerario $cotizacionItinerario;
    private RequestStack $requestStack;

    public function __construct(
        TranslatorInterface $translator,
        CotizacionItinerario $cotizacionItinerario,
        RequestStack $requestStack
    ) {
        $this->translator          = $translator;
        $this->cotizacionItinerario = $cotizacionItinerario;
        $this->requestStack        = $requestStack;
    }

    /**
     * @return array{
     *   incluidos?: array<int, mixed>,
     *   internoIncluidos?: array<int, mixed>
     * }
     */
    public function getDatos(CotizacionCotizacion $cotizacion): array
    {
        $datos = [];

        $servicios = $cotizacion->getCotservicios();
        if ($servicios->count() === 0) {
            return $datos;
        }

        foreach ($servicios as $servicio) {
            $componentes = $servicio->getCotcomponentes();
            if ($componentes->count() === 0) {
                continue;
            }

            foreach ($componentes as $componente) {
                $tarifas = $componente->getCottarifas();
                if ($tarifas->count() === 0) {
                    continue;
                }

                foreach ($tarifas as $tarifa) {
                    $tTarifa = $tarifa->getTarifa();
                    $compDeTarifa = $tTarifa?->getComponente();
                    $compDelComponente = $componente->getComponente();

                    // === Warning con FlashBag: se mantiene TAL CUAL la lógica, pero con comparación por identidad ===
                    if ($compDeTarifa && $compDelComponente && $compDeTarifa !== $compDelComponente) {
                        $this->requestStack->getSession()->getFlashBag()->add(
                            'warning',
                            sprintf(
                                'Tarifas que no corresponden al componente revise la tarifa %s que corresponde al componente %s pero se encuentra bajo %s.',
                                (string) $tTarifa?->getNombre(),
                                (string) $compDeTarifa?->getNombre(),
                                (string) $compDelComponente?->getNombre()
                            )
                        );
                    }

                    // === Determinar bucket/caso/título ===
                    [$servicioId, $caso, $tituloItinerario] = $this->resolverBucketCasoTitulo(
                        $componente,
                        $servicio
                    );

                    // === Inicializa meta por bucket (interno y público) ===
                    if (!isset($datos['internoIncluidos'][$servicioId])) {
                        $datos['internoIncluidos'][$servicioId]['caso']             = $caso;
                        $datos['internoIncluidos'][$servicioId]['tituloItinerario'] = $tituloItinerario;
                    }
                    if (!isset($datos['incluidos'][$servicioId])) {
                        $datos['incluidos'][$servicioId]['caso']             = $caso;
                        $datos['incluidos'][$servicioId]['tituloItinerario'] = $tituloItinerario;
                    }

                    // === Meta de tipotarifa (compartido) ===
                    $tt        = $tarifa->getTipotarifa();
                    $ttId      = $tt->getId();
                    $ttMeta    = $this->metaTipotarifa($tt);

                    $datos['internoIncluidos'][$servicioId]['tipotarifas'][$ttId] =
                        ($datos['internoIncluidos'][$servicioId]['tipotarifas'][$ttId] ?? []) + $ttMeta;

                    $datos['incluidos'][$servicioId]['tipotarifas'][$ttId] =
                        ($datos['incluidos'][$servicioId]['tipotarifas'][$ttId] ?? []) + $ttMeta;

                    // === Interno: por componente ===
                    $compKey = (int) $componente->getId();
                    if (!isset($datos['internoIncluidos'][$servicioId]['tipotarifas'][$ttId]['componentes'][$compKey])) {
                        $datos['internoIncluidos'][$servicioId]['tipotarifas'][$ttId]['componentes'][$compKey] = [
                            'cantidadComponente' => $componente->getCantidad(),
                            'nombre'             => $compDelComponente?->getNombre(),
                            'listaclase'         => $ttMeta['claseTipotarifa'] ?? null,
                            'listacolor'         => $ttMeta['colorTipotarifa'] ?? 'inherit',
                            'fecha'              => $componente->getFechahorainicio()?->format('Y-m-d'),
                            'tarifas'            => [],
                        ];
                    }

                    $tarifaInterna = $this->buildTarifaInterna($tarifa);
                    $datos['internoIncluidos'][$servicioId]['tipotarifas'][$ttId]['componentes'][$compKey]['tarifas'][] = $tarifaInterna;

                    // === Público: por items del componente ===
                    $items = $compDelComponente?->getComponenteitems() ?? [];
                    if (\count($items) > 0) {
                        foreach ($items as $item) {
                            $key = $componente->getId() . '-' . $item->getId();

                            if (!isset($datos['incluidos'][$servicioId]['tipotarifas'][$ttId]['componentes'][$key])) {
                                $datos['incluidos'][$servicioId]['tipotarifas'][$ttId]['componentes'][$key] = [
                                    'cantidadComponente' => $componente->getCantidad(),
                                    'titulo'             => $item->getTitulo(),
                                    'listaclase'         => $ttMeta['claseTipotarifa'] ?? null,
                                    'listacolor'         => $ttMeta['colorTipotarifa'] ?? 'inherit',
                                    'fecha'              => $componente->getFechahorainicio()?->format('Y-m-d'),
                                    'tarifas'            => [],
                                ];
                            }

                            $tarifaPublica = $this->buildTarifaPublica($tarifa, $item);
                            if ($tarifaPublica !== null) {
                                $datos['incluidos'][$servicioId]['tipotarifas'][$ttId]['componentes'][$key]['tarifas'][] = $tarifaPublica;
                            }
                        }
                    }
                }
            }
        }

        // === Ordenar buckets por peso (hotel → normal → varios) sin duplicar/unset ===
        $peso = ['hotel' => 0, 'normal' => 1, 'varios' => 2];
        $ordenarBuckets = static function (array &$grupo) use ($peso): void {
            \uasort($grupo, static function ($a, $b) use ($peso): int {
                $pa = $peso[$a['caso'] ?? 'normal'] ?? 99;
                $pb = $peso[$b['caso'] ?? 'normal'] ?? 99;
                return $pa <=> $pb;
            });
        };

        if (!empty($datos['incluidos'])) {
            $ordenarBuckets($datos['incluidos']);
        }
        if (!empty($datos['internoIncluidos'])) {
            $ordenarBuckets($datos['internoIncluidos']);
        }

        // === Ordenar tipotarifas (ksort) una sola vez por bucket ===
        foreach (['incluidos', 'internoIncluidos'] as $grupoKey) {
            if (!isset($datos[$grupoKey]) || !\is_array($datos[$grupoKey])) {
                continue;
            }
            foreach ($datos[$grupoKey] as &$bucket) {
                if (isset($bucket['tipotarifas']) && \is_array($bucket['tipotarifas'])) {
                    \ksort($bucket['tipotarifas']);
                }
            }
            unset($bucket);
        }

        return $datos;
    }

    // =======================
    // Helpers privados
    // =======================

    /**
     * Devuelve [servicioId, caso, tituloItinerario]
     *
     * - Hotel: bucket especial y título "alojamiento"
     * - Normal: usa título de itinerario
     * - Varios: bucket especial y título "varios"
     */
    private function resolverBucketCasoTitulo($componente, $servicio): array
    {
        $tipocompId = $componente->getComponente()?->getTipocomponente()?->getId();
        $esHotel    = $tipocompId === ServicioTipocomponente::DB_VALOR_ALOJAMIENTO;

        if ($esHotel) {
            return [
                self::BUCKET_HOTEL,
                'hotel',
                $this->translator->trans('alojamiento', [], 'messages'),
            ];
        }

        $tituloIt = $this->cotizacionItinerario->getTituloItinerario($componente->getFechahorainicio(), $servicio);
        if (!empty($tituloIt)) {
            return [
                (int) $servicio->getId(),
                'normal',
                $tituloIt,
            ];
        }

        return [
            self::BUCKET_VARIOS,
            'varios',
            $this->translator->trans('varios', [], 'messages'),
        ];
    }

    private function metaTipotarifa($tipotarifa): array
    {
        return [
            'tituloTipotarifa' => $tipotarifa->getTitulo(),
            'colorTipotarifa'  => $tipotarifa->getListacolor(),
            'claseTipotarifa'  => $tipotarifa->getListaclase(),
        ];
    }

    /**
     * Estructura interna de tarifa (para "internoIncluidos").
     */
    private function buildTarifaInterna($tarifa): array
    {
        $t = $tarifa->getTarifa();

        $out = [
            'nombre'   => $t?->getNombre(),
            'cantidad' => (int) $tarifa->getCantidad(),
            'monto'    => (float) $tarifa->getMonto(),
            'moneda'   => $tarifa->getMoneda(),
        ];

        // Campos opcionales de la tarifa base
        $this->maybe($out, 'validezInicio', $t?->getValidezInicio());
        $this->maybe($out, 'validezFin',    $t?->getValidezFin());
        $this->maybe($out, 'capacidadMin',  $t?->getCapacidadmin());
        $this->maybe($out, 'capacidadMax',  $t?->getCapacidadmax());
        $this->maybe($out, 'edadMin',       $t?->getEdadmin());
        $this->maybe($out, 'edadMax',       $t?->getEdadmax());

        if ($tp = $t?->getTipopax()) {
            $out['tipoPaxId']     = $tp->getId();
            $out['tipoPaxNombre'] = $tp->getNombre();
            $out['tipoPaxTitulo'] = $tp->getTitulo();
        }

        // Detalles (TODOS, también internos)
        $detalles = [];
        foreach ($tarifa->getCottarifadetalles() as $i => $detalle) {
            $tipo = $detalle->getTipotarifadetalle();
            $detalles[$i] = [
                'contenido'  => $detalle->getDetalle(),
                'tipoId'     => $tipo->getId(),
                'tipoNombre' => $tipo->getNombre(),
                'tipoTitulo' => $tipo->getTitulo() ?: $tipo->getNombre(),
            ];
        }
        if (!empty($detalles)) {
            $out['detalles'] = $detalles;
        }

        return $out;
    }

    /**
     * Estructura pública de tarifa (para "incluidos") filtrando internos.
     * Devuelve null si no hay nada que mostrar.
     */
    private function buildTarifaPublica($tarifa, $item): ?array
    {
        $t = $tarifa->getTarifa();
        $mostrarTitulo     = !empty($t?->getTitulo()) && !$item->isNomostrartarifa();
        $mostrarModalidad  = !empty($t?->getModalidadtarifa()) && !$item->isNomostrarmodalidadtarifa();
        $mostrarCategoria  = !empty($t?->getCategoriatour()) && !$item->isNomostrarcategoriatour();

        if (!$mostrarTitulo && !$mostrarModalidad && !$mostrarCategoria) {
            return null;
        }

        $out = [
            'cantidad' => (int) $tarifa->getCantidad(),
        ];

        if ($mostrarTitulo) {
            $out['titulo'] = $t->getTitulo();
        }
        if ($mostrarModalidad) {
            $out['modalidad'] = $t->getModalidadtarifa()->getTitulo();
        }
        if ($mostrarCategoria) {
            $out['categoria'] = $t->getCategoriatour()->getTitulo();
        }

        // Mostrar costo si procede
        $monto   = (float) $tarifa->getMonto();
        $mostrar = $tarifa->getTipotarifa()->isMostrarcostoincluye() === true && $monto > 0.0;

        $out['mostrarcostoincluye'] = $mostrar;
        if ($mostrar) {
            $out['simboloMoneda'] = $tarifa->getMoneda()?->getSimbolo();
            $out['costo']         = $monto;
        }

        // Opcionales
        $this->maybe($out, 'validezInicio', $t?->getValidezInicio());
        $this->maybe($out, 'validezFin',    $t?->getValidezFin());
        $this->maybe($out, 'capacidadMin',  $t?->getCapacidadmin());
        $this->maybe($out, 'capacidadMax',  $t?->getCapacidadmax());
        $this->maybe($out, 'edadMin',       $t?->getEdadmin());
        $this->maybe($out, 'edadMax',       $t?->getEdadmax());

        if ($tp = $t?->getTipopax()) {
            $out['tipoPaxId']     = $tp->getId();
            $out['tipoPaxNombre'] = $tp->getNombre();
            $out['tipoPaxTitulo'] = $tp->getTitulo();
        }

        // Detalles PÚBLICOS (excluye internos)
        $detalles = [];
        foreach ($tarifa->getCottarifadetalles() as $i => $detalle) {
            $tipo = $detalle->getTipotarifadetalle();
            if ($tipo->isInterno()) {
                continue;
            }
            $detalles[$i] = [
                'contenido'  => $detalle->getDetalle(),
                'tipoId'     => $tipo->getId(),
                'tipoNombre' => $tipo->getNombre(),
                'tipoTitulo' => $tipo->getTitulo() ?: $tipo->getNombre(),
            ];
        }
        if (!empty($detalles)) {
            $out['detalles'] = $detalles;
        }

        return $out;
    }

    /**
     * Añade $value a $arr[$key] si no está vacío.
     *
     * @param array<string,mixed> $arr
     */
    private function maybe(array &$arr, string $key, mixed $value): void
    {
        if ($value !== null && $value !== '' && $value !== []) {
            $arr[$key] = $value;
        }
    }
}
