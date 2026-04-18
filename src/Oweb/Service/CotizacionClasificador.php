<?php

namespace App\Oweb\Service;

use App\Oweb\Entity\CotizacionCotcomponente;
use App\Oweb\Entity\CotizacionCotizacion;
use App\Oweb\Entity\CotizacionCotservicio;
use App\Oweb\Entity\CotizacionCottarifa;
use App\Oweb\Entity\MaestroMoneda;
use App\Oweb\Entity\MaestroTipocambio;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class CotizacionClasificador
{

    // 🔹 Límites y valores configurables
    private const MAX_RECURSION = 10;        // Límite de iteraciones recursivas
    private const EDAD_MAXIMA_DEFAULT = 120; // Edad máxima por defecto
    private const EDAD_MINIMA_DEFAULT = 0;   // Edad mínima por defecto

    private TranslatorInterface $translator;
    private CotizacionCotizacion $cotizacion;
    private RequestStack $requestStack;

    private array $tarifasClasificadas = [];
    //Es el resumen final de todos los pasajeros de tarifas costos v netas por tipo de tarifa incluido no incluido, etc 
    private array $resumenDeClasificado = [];

    private array $datos = [];

    function __construct(TranslatorInterface $translator, RequestStack $requestStack)
    {
        $this->translator = $translator;
        $this->requestStack = $requestStack;
    }

    /**
     * Clasifica las tarifas de una cotización por servicio y componente.
     *
     * Utiliza un enfoque de "Doble Pasada":
     * 1. Extrae el "Perfil Maestro" buscando el componente con las reglas más estrictas.
     * 2. Recorre todos los componentes y encaja las tarifas en esos contenedores inmutables.
     *
     * @param CotizacionCotizacion $cotizacion La cotización a procesar.
     * @param MaestroTipocambio $tipocambio Tipo de cambio para conversión de montos.
     * @return bool True si todas las tarifas se clasificaron correctamente, false si hubo errores.
     */
    public function clasificar(CotizacionCotizacion $cotizacion, MaestroTipocambio $tipocambio): bool
    {
        $this->cotizacion = $cotizacion;

        if (!$this->validarCotizacion()) {
            return false;
        }

        // --- PASADA 1: Encontrar el perfil de pasajeros maestro ---
        if (!$this->definirClasesMaestras($tipocambio)) {
            return false;
        }

        $existeAlertaDiferencia = false;
        $fechaHoraPrimerServicio = null;

        // --- PASADA 2: Asignar tarifas a los contenedores inmutables ---
        foreach ($cotizacion->getCotservicios() as $servicio) {
            foreach ($servicio->getCotcomponentes() as $componente) {
                if ($fechaHoraPrimerServicio === null) {
                    $fechaHoraPrimerServicio = $componente->getFechahorainicio();
                } else {
                    $this->validarSeparacionFechas($componente, $fechaHoraPrimerServicio, $existeAlertaDiferencia);
                }

                $cantidadComponente = 0;
                $tarifasTemp = $this->construirArrayTarifasComponente($componente, $servicio, $tipocambio, $cantidadComponente);

                if ($tarifasTemp === false) {
                    return false;
                }

                if (!$this->obtenerTarifasComponente($tarifasTemp, $cotizacion->getNumeropasajeros())) {
                    return false;
                }

                if (!$this->validarCantidadComponente($componente, $cantidadComponente)) {
                    return false;
                }
            }
        }

        if (empty($this->tarifasClasificadas)) {
            $this->requestStack->getSession()->getFlashBag()->add('error', 'No se pudieron clasificar las tarifas.');
            return false;
        }

        $this->ordenarClasesPorEdad();

        $this->resumirTarifas();
        $this->datos['rangos'] = $this->tarifasClasificadas;
        $this->datos['tarifas']['resumen'] = $this->resumenDeClasificado;

        return true;
    }

    /**
     * Define los contenedores (clases) base analizando cuál es el componente
     * con mayor nivel de especificidad (ej. Entradas a Machu Picchu o Trenes).
     * Esto evita que tarifas genéricas secuestren clases que requieren nacionalidad.
     *
     * @param MaestroTipocambio $tipocambio
     * @return bool
     */
    private function definirClasesMaestras(MaestroTipocambio $tipocambio): bool
    {
        $mejorPuntaje = -1;
        $componenteMaestroData = [];

        // Buscar el componente más restrictivo
        foreach ($this->cotizacion->getCotservicios() as $servicio) {
            foreach ($servicio->getCotcomponentes() as $componente) {
                $cantidadComponente = 0;
                $tarifasTemp = $this->construirArrayTarifasComponente($componente, $servicio, $tipocambio, $cantidadComponente);

                if ($tarifasTemp === false) {
                    return false;
                }

                // El maestro debe cubrir a todos los pasajeros de la cotización
                if ($cantidadComponente != $this->cotizacion->getNumeropasajeros()) {
                    continue;
                }

                $puntaje = $this->calcularEspecificidadComponente($tarifasTemp);

                if ($puntaje > $mejorPuntaje) {
                    $mejorPuntaje = $puntaje;
                    $componenteMaestroData = $tarifasTemp;
                }
            }
        }

        if (empty($componenteMaestroData)) {
            $this->requestStack->getSession()->getFlashBag()->add('error', 'No se pudo determinar un perfil maestro de pasajeros en la cotización.');
            return false;
        }

        // Construir las clases maestras (contenedores inmutables)
        $clasesAgrupadas = [];
        foreach ($componenteMaestroData as $tarifa) {
            $min = $tarifa['edadMin'] ?? self::EDAD_MINIMA_DEFAULT;
            $max = $tarifa['edadMax'] ?? self::EDAD_MAXIMA_DEFAULT;
            $tipo = 'r' . $min . '-' . $max . 't' . $tarifa['tipoPaxId'];

            if (!isset($clasesAgrupadas[$tipo])) {
                $clasesAgrupadas[$tipo] = [
                    'tipo' => $tipo,
                    'cantidad' => 0,
                    'cantidadRestante' => 0,
                    'tipoPaxId' => $tarifa['tipoPaxId'],
                    'tipoPaxNombre' => $tarifa['tipoPaxNombre'],
                    'tipoPaxTitulo' => $tarifa['tipoPaxTitulo'],
                    'edadMin' => $min,
                    'edadMax' => $max,
                    'tarifas' => []
                ];
            }

            $clasesAgrupadas[$tipo]['cantidad'] += $tarifa['cantidad'];
            $clasesAgrupadas[$tipo]['cantidadRestante'] += $tarifa['cantidad'];
        }

        $this->tarifasClasificadas = array_values($clasesAgrupadas);
        return true;
    }

    /**
     * Calcula cuán "específico" es un arreglo de tarifas.
     * Mayor especificidad (con nacionalidades declaradas y rangos de edad cortos)
     * asegura que los contenedores maestros se creen correctamente.
     *
     * @param array $tarifas
     * @return int
     */
    private function calcularEspecificidadComponente(array $tarifas): int
    {
        $score = 0;
        foreach ($tarifas as $t) {
            // Premia enormemente si exige nacionalidad explícita (Extranjero, Peruano, etc)
            if ($t['tipoPaxId'] != 0) {
                $score += 100;
            }
            // Premia rangos de edad cerrados (un rango 14-17 gana más puntos que un 0-120)
            $rango = ($t['edadMax'] ?? self::EDAD_MAXIMA_DEFAULT) - ($t['edadMin'] ?? self::EDAD_MINIMA_DEFAULT);
            $score += (self::EDAD_MAXIMA_DEFAULT - $rango);
        }
        return $score;
    }

    /**
     * Valida que la cotización tenga servicios y componentes con tarifas.
     *
     * @return bool True si la cotización es válida, false si falta algún elemento.
     */
    private function validarCotizacion(): bool
    {
        if ($this->cotizacion->getCotservicios()->count() === 0) {
            $this->requestStack->getSession()->getFlashBag()->add('error', 'La cotización no tiene servicios.');
            return false;
        }

        foreach ($this->cotizacion->getCotservicios() as $servicio) {
            if ($servicio->getCotcomponentes()->count() === 0) {
                $this->requestStack->getSession()->getFlashBag()->add(
                    'error',
                    sprintf(
                        'El servicio no tiene componente en %s %s.',
                        $servicio->getFechahorainicio()->format('Y/m/d'),
                        $servicio->getServicio()->getNombre()
                    )
                );
                return false;
            }

            foreach ($servicio->getCotcomponentes() as $componente) {
                if ($componente->getCottarifas()->count() === 0) {
                    $this->requestStack->getSession()->getFlashBag()->add(
                        'error',
                        sprintf(
                            'El componente no tiene tarifa en %s %s %s.',
                            $servicio->getFechahorainicio()->format('Y/m/d'),
                            $servicio->getServicio()->getNombre(),
                            $componente->getComponente()->getNombre()
                        )
                    );
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Genera alerta si la separación entre componentes supera los 20 días.
     *
     * @param CotizacionCotcomponente $componente Componente actual.
     * @param \DateTime $fechaPrimerServicio Fecha del primer servicio.
     * @param bool $existeAlerta Referencia que indica si la alerta ya fue mostrada.
     *
     * @return void
     */
    private function validarSeparacionFechas(
        CotizacionCotcomponente $componente,
        \DateTime $fechaPrimerServicio,
        bool &$existeAlerta
    ): void {
        $diff = (int) $componente->getFechahorainicio()->diff($fechaPrimerServicio)->format('%a');

        if ($diff > 20 && $existeAlerta === false) {
            $this->requestStack->getSession()->getFlashBag()->add(
                'warning',
                'Existen servicios fuera del periodo de operación.'
            );
            $existeAlerta = true;
        }
    }

    /**
     * Construye un array temporal de tarifas de un componente listo para clasificación.
     *
     * Recorre todas las tarifas del componente y las convierte en arrays listos para la clasificación.
     * Acumula la cantidad total de pasajeros por componente y realiza validaciones de moneda y prorrateo.
     *
     * @param CotizacionCotcomponente $componente Componente actual.
     * @param CotizacionCotservicio $servicio Servicio asociado al componente.
     * @param MaestroTipocambio $tipocambio Tipo de cambio para conversiones monetarias.
     * @param int &$cantidadComponente Acumulador de cantidad procesada para validación.
     *
     * @return array|bool Array de tarifas listas para clasificar, o false si ocurre un error en la construcción.
     */
    private function construirArrayTarifasComponente(
        CotizacionCotcomponente $componente,
        CotizacionCotservicio $servicio,
        MaestroTipocambio $tipocambio,
        int &$cantidadComponente
    ): array|bool {
        $tarifasTemp = [];
        $cantidadComponente = 0;

        foreach ($componente->getCottarifas() as $tarifa) {
            $tempArrayTarifa = $this->construirTarifaArray(
                $tarifa,
                $servicio,
                $componente,
                $tipocambio,
                $this->cotizacion,
                $cantidadComponente
            );

            if ($tempArrayTarifa === false) {
                return false;
            }

            $tarifasTemp[] = $tempArrayTarifa;
        }

        return $tarifasTemp;
    }

    /**
     * Valida que la cantidad de pasajeros del componente coincida con la cantidad de la cotización.
     *
     * @param CotizacionCotcomponente $componente Componente actual a validar.
     * @param int $cantidadComponente Cantidad total de pasajeros calculada para este componente.
     *
     * @return bool True si la cantidad coincide con la cotización, false en caso contrario.
     */
    private function validarCantidadComponente(CotizacionCotcomponente $componente, int $cantidadComponente): bool
    {
        if ($cantidadComponente > 0 && $cantidadComponente != $this->cotizacion->getNumeropasajeros()) {
            $this->requestStack->getSession()->getFlashBag()->add(
                'error',
                sprintf(
                    'La cantidad de pasajeros por componente no coincide con la cantidad de pasajeros en %s %s %s.',
                    $componente->getFechahorainicio()->format('Y/m/d'),
                    $componente->getCotservicio()->getServicio()->getNombre(),
                    $componente->getComponente()->getNombre()
                )
            );
            return false;
        }

        return true;
    }

    /**
     * Ordena las clases de tarifas por edad mínima descendente de las clases finalizadas.
     *
     * @return void
     */
    private function ordenarClasesPorEdad(): void
    {
        usort($this->tarifasClasificadas, function ($a, $b) {
            if (!isset($b['edadMin'])) {
                $b['edadMin'] = self::EDAD_MINIMA_DEFAULT;
            }
            if (!isset($b['edadMax'])) {
                $b['edadMax'] = self::EDAD_MAXIMA_DEFAULT;
            }
            return $b['edadMin'] <=> $a['edadMin'];
        });
    }

    /**
     * Construye un arreglo de datos detallados para una tarifa específica.
     *
     * Esta función prepara todos los valores necesarios para la clasificación,
     * cálculo de montos unitarios y totales, conversión de moneda, aplicación de
     * comisión y validaciones adicionales. También maneja prorrateo si aplica.
     *
     * @param CotizacionCottarifa $tarifa Entidad de la tarifa a procesar.
     * @param CotizacionCotservicio $servicio Entidad del servicio al que pertenece la tarifa.
     * @param CotizacionCotcomponente $componente Entidad del componente dentro del servicio.
     * @param MaestroTipocambio $tipocambio Tipo de cambio para conversión de montos.
     * @param CotizacionCotizacion $cotizacion Cotización actual.
     * @param int &$cantidadComponente Variable por referencia para acumular cantidad.
     *
     * @return array|false Arreglo con datos de la tarifa listos para clasificación o false si hay error.
     */
    private function construirTarifaArray(
        CotizacionCottarifa $tarifa,
        CotizacionCotservicio $servicio,
        CotizacionCotcomponente $componente,
        MaestroTipocambio $tipocambio,
        CotizacionCotizacion $cotizacion,
        int &$cantidadComponente
    ): array|false
    {
        // Datos básicos de la tarifa y componente
        $tempArrayTarifa = [
            'id'                 => $tarifa->getId(),
            'nombreServicio'     => $servicio->getServicio()->getNombre(),
            'cantidadComponente' => $componente->getCantidad(),
            'nombreComponente'   => $componente->getComponente()->getNombre(),
        ];

        // Cálculo de montos unitarios y totales considerando prorrateo
        if ($tarifa->getTarifa()->isProrrateado() === true) {
            $tempArrayTarifa['montounitario'] = number_format(
                (float)($tarifa->getMonto() * $tarifa->getCantidad() / $cotizacion->getNumeropasajeros() * $componente->getCantidad()),
                2,
                '.',
                ''
            );
            $tempArrayTarifa['montototal'] = number_format(
                (float)($tarifa->getMonto() * $tarifa->getCantidad() * $componente->getCantidad()),
                2,
                '.',
                ''
            );
            $tempArrayTarifa['cantidad'] = (int) $cotizacion->getNumeropasajeros();
            $tempArrayTarifa['prorrateado'] = true;
        } else {
            $tempArrayTarifa['montounitario'] = number_format(
                (float)($tarifa->getMonto() * $componente->getCantidad()),
                2,
                '.',
                ''
            );
            $tempArrayTarifa['montototal'] = number_format(
                (float)($tarifa->getMonto() * $componente->getCantidad() * $tarifa->getCantidad()),
                2,
                '.',
                ''
            );
            $tempArrayTarifa['cantidad'] = $tarifa->getCantidad();
            $cantidadComponente += $tempArrayTarifa['cantidad'];
            $tempArrayTarifa['prorrateado'] = false;
        }

        // Nombre y título de la tarifa
        $tempArrayTarifa['nombre'] = $tarifa->getTarifa()->getNombre();
        if (!empty($tarifa->getTarifa()->getTitulo())) {
            $tempArrayTarifa['titulo'] = $tarifa->getTarifa()->getTitulo();
        }

        // Moneda original
        $tempArrayTarifa['moneda'] = $tarifa->getMoneda()->getId();

        // Conversión de montos según moneda
        if ($tarifa->getMoneda()->getId() == MaestroMoneda::DB_VALOR_DOLAR) {
            $tempArrayTarifa['montosoles'] = number_format(
                (float)($tempArrayTarifa['montounitario'] * (float)$tipocambio->getPromedio()),
                2,
                '.',
                ''
            );
            $tempArrayTarifa['montodolares'] = $tempArrayTarifa['montounitario'];
        } elseif ($tarifa->getMoneda()->getId() == MaestroMoneda::DB_VALOR_SOL) {
            $tempArrayTarifa['montosoles'] = $tempArrayTarifa['montounitario'];
            $tempArrayTarifa['montodolares'] = number_format(
                (float)($tempArrayTarifa['montounitario'] / (float)$tipocambio->getPromedio()),
                2,
                '.',
                ''
            );
        } else {
            $this->requestStack->getSession()->getFlashBag()->add('error', 'La aplicación solo puede utilizar Soles y dólares en las tarifas.');
            return false;
        }

        $tempArrayTarifa['monedaOriginal'] = $tarifa->getMoneda()->getNombre();
        $tempArrayTarifa['montoOriginal'] = number_format((float)$tarifa->getMonto(), 2, '.', '');

        // Aplicación de comisión si la tarifa es comisionable
        $factorComision = 1;
        if ($tarifa->getTipotarifa()->isComisionable() == true) {
            $factorComision = 1 + ($cotizacion->getComision() / 100);
        }
        $tempArrayTarifa['ventasoles'] = number_format((float)($tempArrayTarifa['montosoles'] * $factorComision), 2, '.', '');
        $tempArrayTarifa['ventadolares'] = number_format((float)($tempArrayTarifa['montodolares'] * $factorComision), 2, '.', '');

        // Validaciones y campos adicionales
        if (!empty($tarifa->getTarifa()->getValidezInicio())) {
            $tempArrayTarifa['validezInicio'] = $tarifa->getTarifa()->getValidezInicio();
        }
        if (!empty($tarifa->getTarifa()->getValidezFin())) {
            $tempArrayTarifa['validezFin'] = $tarifa->getTarifa()->getValidezFin();
        }
        if (!empty($tarifa->getTarifa()->getCapacidadmin())) {
            $tempArrayTarifa['capacidadMin'] = $tarifa->getTarifa()->getCapacidadmin();
        }
        if (!empty($tarifa->getTarifa()->getCapacidadmax())) {
            $tempArrayTarifa['capacidadMax'] = $tarifa->getTarifa()->getCapacidadmax();
        }
        if (!empty($tarifa->getTarifa()->getEdadmin())) {
            $tempArrayTarifa['edadMin'] = $tarifa->getTarifa()->getEdadmin();
        }
        if (!empty($tarifa->getTarifa()->getEdadmax())) {
            $tempArrayTarifa['edadMax'] = $tarifa->getTarifa()->getEdadmax();
        }

        // Datos del tipo de pasajero
        if (!empty($tarifa->getTarifa()->getTipopax())) {
            $tempArrayTarifa['tipoPaxId'] = $tarifa->getTarifa()->getTipopax()->getId();
            $tempArrayTarifa['tipoPaxNombre'] = $tarifa->getTarifa()->getTipopax()->getNombre();
            $tempArrayTarifa['tipoPaxTitulo'] = $tarifa->getTarifa()->getTipopax()->getTitulo();
        } else {
            $tempArrayTarifa['tipoPaxId'] = 0;
            $tempArrayTarifa['tipoPaxNombre'] = 'Cualquier_nacionalidad';
            $tempArrayTarifa['tipoPaxTitulo'] = ucfirst($this->translator->trans('cualquier_nacionalidad', [], 'messages'));
        }

        // Datos del tipo de tarifa
        $tempArrayTarifa['tipoTarId'] = $tarifa->getTipotarifa()->getId();
        $tempArrayTarifa['tipoTarNombre'] = $tarifa->getTipotarifa()->getNombre();
        $tempArrayTarifa['tipoTarTitulo'] = $tarifa->getTipotarifa()->getTitulo();
        $tempArrayTarifa['tipoTarListacolor'] = $tarifa->getTipotarifa()->getListacolor();
        $tempArrayTarifa['tipoTarOcultoenresumen'] = $tarifa->getTipotarifa()->isOcultoenresumen();

        return $tempArrayTarifa;
    }

    /**
     * Obtiene las tarifas que ya fueron clasificadas en clases.
     *
     * @return array Arreglo de tarifas clasificadas.
     */
    public function getTarifasClasificadas(): array
    {
        return $this->tarifasClasificadas;
    }

    /**
     * Devuelve el resumen final de la clasificación de tarifas.
     *
     * @return array Arreglo con el resumen de las tarifas clasificadas.
     */
    public function getResumenDeClasificado(): array
    {
        return $this->resumenDeClasificado;
    }

    /**
     * Prepara y clasifica las tarifas de un componente según la edad y tipo de pasajero.
     *
     * @param array $componente Lista de tarifas del componente.
     * @param int $cantidadTotalPasajeros Cantidad total de pasajeros a considerar.
     * @return bool Devuelve true si las tarifas se procesaron correctamente, false en caso contrario.
     */
    private function obtenerTarifasComponente(array $componente, int $cantidadTotalPasajeros): bool
    {
        $tarifasParaClasificar = [];
        $tiposAux = [];

        foreach ($componente as $tarifa) {
            $min = $tarifa['edadMin'] ?? self::EDAD_MINIMA_DEFAULT;
            $max = $tarifa['edadMax'] ?? self::EDAD_MAXIMA_DEFAULT;

            $temp = [
                'cantidad'          => $tarifa['cantidad'],
                'tipoPaxId'         => $tarifa['tipoPaxId'],
                'tipoPaxNombre'     => $tarifa['tipoPaxNombre'],
                'tipoPaxTitulo'     => $tarifa['tipoPaxTitulo'],
                'prorrateado'       => $tarifa['prorrateado'],
                'edadMin'           => $min,
                'edadMax'           => $max,
                'tipo'              => 'r' . $min . '-' . $max . 't' . $tarifa['tipoPaxId'],
                'tituloOTipoTarifa' => $tarifa['titulo'] ?? $tarifa['tipoTarTitulo'],
                'tituloPersistente' => false,
                'tarifa'            => $tarifa,
            ];

            if (in_array($temp['tipo'], $tiposAux, true)) {
                $temp['tituloPersistente'] = true;
            }

            $tarifasParaClasificar[] = $temp;
            $tiposAux[] = $temp['tipo'];
        }

        usort($tarifasParaClasificar, function ($a, $b) {
            $aPax = $a['tipoPaxId'] != 0 ? 1 : 0;
            $bPax = $b['tipoPaxId'] != 0 ? 1 : 0;
            if ($aPax !== $bPax) {
                return $bPax <=> $aPax;
            }
            $aRango = $a['edadMax'] - $a['edadMin'];
            $bRango = $b['edadMax'] - $b['edadMin'];
            if ($aRango !== $bRango) {
                return $aRango <=> $bRango;
            }
            return $b['cantidad'] <=> $a['cantidad'];
        });

        if (!empty($tarifasParaClasificar)) {
            if ($this->procesarTarifa($tarifasParaClasificar, $cantidadTotalPasajeros)) {
                $this->resetClasificacionTarifas();
                return true;
            }
        }

        return false;
    }

    /**
     * Reinicia la cantidad restante de todas las clases clasificadas para iterar el siguiente componente.
     *
     * @return void
     */
    private function resetClasificacionTarifas(): void
    {
        foreach ($this->tarifasClasificadas as &$clase) {
            $clase['cantidadRestante'] = $clase['cantidad'];
        }
        unset($clase);
    }

    /**
     * Delega la clasificación recursiva a cada tarifa en el componente actual.
     *
     * @param array $tarifasParaClasificar Lista de tarifas a procesar y clasificar.
     * @param int $cantidadTotalPasajeros Cantidad total de pasajeros.
     * @return bool True si todas las tarifas fueron clasificadas.
     */
    private function procesarTarifa(array $tarifasParaClasificar, int $cantidadTotalPasajeros): bool
    {
        foreach ($tarifasParaClasificar as $key => &$tarifa) {
            $ejecucion = 0;
            $this->clasificarTarifas($tarifa, $ejecucion, $tarifa['tituloPersistente']);

            if ($tarifa['cantidad'] < 1) {
                unset($tarifasParaClasificar[$key]);
            }
        }
        unset($tarifa);

        if (!empty($tarifasParaClasificar)) {
            $tarifasDisplay = $this->generarResumenTarifas();
            $this->registrarErroresTarifas($tarifasParaClasificar, $tarifasDisplay);
            return false;
        }

        return true;
    }

    /**
     * Genera un resumen legible de las tarifas clasificadas.
     *
     * @return string Resumen de las tarifas clasificadas.
     */
    private function generarResumenTarifas(): string
    {
        $tarifasDisplay = '';
        $menorCantidadRestante = 0;

        foreach ($this->tarifasClasificadas as $tarifa) {
            $detalle = [];

            if (isset($tarifa['edadMin'])) {
                $detalle[] = 'E min:' . $tarifa['edadMin'];
            }
            if (isset($tarifa['edadMax'])) {
                $detalle[] = 'E max:' . $tarifa['edadMax'];
            }
            if (isset($tarifa['tipoPaxNombre'])) {
                $detalle[] = 'tipo: ' . $tarifa['tipoPaxNombre'];
            }
            if (isset($tarifa['cantidad'])) {
                $detalle[] = 'cantidad: ' . $tarifa['cantidad'];
            }
            if (isset($tarifa['cantidadRestante'])) {
                $detalle[] = 'cantidad restante: ' . $tarifa['cantidadRestante'];
            }

            $tarifasDisplay .= '[' . implode(', ', $detalle) . '] ';

            if (($tarifa['cantidadRestante'] ?? 0) > $menorCantidadRestante) {
                $menorCantidadRestante = $tarifa['cantidadRestante'];
            }
        }

        if ($menorCantidadRestante === 0) {
            $this->requestStack->getSession()->getFlashBag()->add(
                'error',
                'No hay espacio en las tarifas, verifique la cantidad total de pasajeros del componente.'
            );
        } else {
            $this->requestStack->getSession()->getFlashBag()->add(
                'error',
                'Hay tarifas que no se acomodan a las clases actuales.'
            );
        }

        return $tarifasDisplay;
    }

    /**
     * Registra errores específicos de clasificación de tarifas en FlashBag.
     *
     * @param array $tarifasRestantes Tarifas que no pudieron clasificarse.
     * @param string $tarifasDisplay Resumen legible de las tarifas clasificadas.
     * @return void
     */
    private function registrarErroresTarifas(array $tarifasRestantes, string $tarifasDisplay): void
    {
        $tarifaError = reset($tarifasRestantes);

        $detalle = $tarifaError['tarifa']['nombreServicio']
            . ' - ' . $tarifaError['tarifa']['nombreComponente']
            . ' - ' . $tarifaError['tarifa']['nombre'];

        if (isset($tarifaError['tarifa']['edadMin'])) {
            $detalle .= ' - E min: ' . $tarifaError['tarifa']['edadMin'];
        }
        if (isset($tarifaError['tarifa']['edadMax'])) {
            $detalle .= ' - E max: ' . $tarifaError['tarifa']['edadMax'];
        }
        if (isset($tarifaError['tipoPaxNombre'])) {
            $detalle .= ' - tipo: ' . $tarifaError['tipoPaxNombre'];
        }

        $detalle .= ' - cantidad a clasificar: ' . $tarifaError['cantidad'];

        $this->requestStack->getSession()->getFlashBag()->add(
            'error',
            sprintf('No se pudo clasificar: %s.', $detalle)
        );

        if (!empty($tarifasDisplay)) {
            $this->requestStack->getSession()->getFlashBag()->add('error', 'Clasificación actual: ' . $tarifasDisplay);
        }
    }


    /**
     * Vierte la cantidad de la tarifa en los contenedores maestros designados.
     *
     * Los contenedores son inmutables (no cambian sus propiedades de edad o nacionalidad
     * basándose en las tarifas que ingresan).
     *
     * @param array $tarifaParaClasificar La tarifa que se desea clasificar.
     * @param int $ejecucion Número de iteraciones recursivas para evitar loops infinitos.
     * @param bool $tituloPersistente Si se deben concatenar títulos persistentes.
     * @return int La cantidad que no se pudo clasificar.
     */
    private function clasificarTarifas(array &$tarifaParaClasificar, int $ejecucion, bool $tituloPersistente = false): int
    {
        $ejecucion++;

        $voterIndex = $this->voter($tarifaParaClasificar);

        if ($voterIndex < 0 && $this->cotizacion->getNumeropasajeros() == $tarifaParaClasificar['cantidad'] && $tarifaParaClasificar['prorrateado']) {
            foreach ($this->tarifasClasificadas as &$clase) {
                $clase['tarifas'][] = $tarifaParaClasificar['tarifa'];
            }
            unset($clase);
            $tarifaParaClasificar['cantidad'] = 0;
            return 0;
        }

        if ($voterIndex < 0) {
            // Failsafe por si llega un borde no detectado en el perfil maestro
            $nuevaClase = [
                'edadMin' => $tarifaParaClasificar['edadMin'] ?? self::EDAD_MINIMA_DEFAULT,
                'edadMax' => $tarifaParaClasificar['edadMax'] ?? self::EDAD_MAXIMA_DEFAULT,
                'tipoPaxId' => $tarifaParaClasificar['tipoPaxId'],
                'tipoPaxNombre' => $tarifaParaClasificar['tipoPaxNombre'],
                'tipoPaxTitulo' => $tarifaParaClasificar['tipoPaxTitulo'],
                'tipo' => $tarifaParaClasificar['tipo'],
                'cantidad' => $tarifaParaClasificar['cantidad'],
                'cantidadRestante' => $tarifaParaClasificar['cantidad'],
                'tarifas' => [$tarifaParaClasificar['tarifa']],
            ];

            $this->tarifasClasificadas[] = $nuevaClase;
            $tarifaParaClasificar['cantidad'] = 0;
            return 0;
        }

        if ($tituloPersistente) {
            $this->tarifasClasificadas[$voterIndex]['tituloPersistente'] =
                isset($this->tarifasClasificadas[$voterIndex]['tituloPersistente'])
                    ? sprintf('%s %s', $this->tarifasClasificadas[$voterIndex]['tituloPersistente'], $tarifaParaClasificar['tituloOTipoTarifa'])
                    : $tarifaParaClasificar['tituloOTipoTarifa'];
        }

        $cantidadClase = $this->tarifasClasificadas[$voterIndex]['cantidadRestante'];

        if ($tarifaParaClasificar['cantidad'] <= $cantidadClase) {
            // La tarifa encaja completamente en el contenedor restante
            $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] -= $tarifaParaClasificar['cantidad'];
            $this->tarifasClasificadas[$voterIndex]['tarifas'][] = $tarifaParaClasificar['tarifa'];
            $tarifaParaClasificar['cantidad'] = 0;
        } else {
            // La tarifa desborda este contenedor. Llenamos y recurrimos el remanente.
            $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] = 0;
            $this->tarifasClasificadas[$voterIndex]['tarifas'][] = $tarifaParaClasificar['tarifa'];
            $tarifaParaClasificar['cantidad'] -= $cantidadClase;
        }

        if ($tarifaParaClasificar['cantidad'] > 0 && $ejecucion < self::MAX_RECURSION) {
            $tarifaParaClasificar['cantidad'] = $this->clasificarTarifas($tarifaParaClasificar, $ejecucion, $tituloPersistente);
        }

        return $tarifaParaClasificar['cantidad'];
    }

    /**
     * Determina la clase inmutable más adecuada para una tarifa.
     * Al usar moldes pre-generados estrictos, la tarifa es la que debe demostrar compatibilidad
     * con el molde, y no al revés.
     *
     * @param array $tarifaParaClasificar Los datos de la tarifa que se desea clasificar.
     * @return int Retorna la clave del contenedor con mayor puntaje, o -1 si no encaja lógicamente.
     */
    private function voter(array $tarifaParaClasificar): int
    {
        $voterArray = [];

        foreach ($this->tarifasClasificadas as $key => $clase) {
            $voterArray[$key] = 0;

            $edadMinClase = $clase['edadMin'] ?? self::EDAD_MINIMA_DEFAULT;
            $edadMaxClase = $clase['edadMax'] ?? self::EDAD_MAXIMA_DEFAULT;
            $edadMinTarifa = $tarifaParaClasificar['edadMin'] ?? self::EDAD_MINIMA_DEFAULT;
            $edadMaxTarifa = $tarifaParaClasificar['edadMax'] ?? self::EDAD_MAXIMA_DEFAULT;

            // Para encajar:
            // 1. Debe haber cupo en la clase.
            // 2. Coincidencia de Nacionalidad estricta (o que uno de los dos sea genérico ID 0)
            // 3. Traslape lógico de edad (ej. Tarifa genérica 0-120 encaja en molde Peruano 14-17)
            if ($clase['cantidadRestante'] > 0 &&
                ($tarifaParaClasificar['tipoPaxId'] == $clase['tipoPaxId'] ||
                    $tarifaParaClasificar['tipoPaxId'] == 0 ||
                    $clase['tipoPaxId'] == 0) &&
                $edadMinTarifa <= $edadMaxClase &&
                $edadMaxTarifa >= $edadMinClase
            ) {
                // Puntaje base por encaje lógico
                $voterArray[$key] += 0.1;

                // Bono fuerte por coincidencia exacta de nacionalidad
                if ($tarifaParaClasificar['tipoPaxId'] == $clase['tipoPaxId'] && $clase['tipoPaxId'] != 0) {
                    $voterArray[$key] += 10.0;
                }

                // Bono por coincidencia exacta de límites de edad
                if ($edadMinTarifa == $edadMinClase) {
                    $voterArray[$key] += 2.0;
                }
                if ($edadMaxTarifa == $edadMaxClase) {
                    $voterArray[$key] += 2.0;
                }

                // Bono si la tarifa llena justo el hueco del contenedor
                if ($clase['cantidadRestante'] == $tarifaParaClasificar['cantidad']) {
                    $voterArray[$key] += 5.0;
                }
            }
        }

        if (empty($voterArray) || max($voterArray) <= 0) return -1;

        return array_search(max($voterArray), $voterArray);
    }

    /**
     * Inicializa un resumen global con claves cortas.
     * Este resumen se usa para el total consolidado.
     *
     * @param array $tarifa Tarifa base
     * @return array Resumen inicializado
     */
    private function inicializarResumenGlobal(array $tarifa): array
    {
        return [
            'nombre'          => $tarifa['tipoTarNombre'],
            'titulo'          => $tarifa['tipoTarTitulo'],
            'listacolor'      => $tarifa['tipoTarListacolor'],
            'ocultoenresumen' => $tarifa['tipoTarOcultoenresumen'],
            'montosoles'      => 0.0,
            'montodolares'    => 0.0,
            'ventasoles'      => 0.0,
            'ventadolares'    => 0.0,
            'adelantosoles'   => 0.0,
            'adelantodolares' => 0.0,
            'gananciasoles'   => 0.0,
            'gananciadolares' => 0.0,
        ];
    }

    /**
     * Inicializa un resumen de clase con claves largas.
     * Este resumen se usa para el detalle por cada clase de tarifa.
     *
     * @param array $tarifa Tarifa base
     * @return array Resumen de clase inicializado
     */
    private function inicializarResumenClase(array $tarifa): array
    {
        return [
            'tipoTarNombre'          => $tarifa['tipoTarNombre'],
            'tipoTarTitulo'          => $tarifa['tipoTarTitulo'],
            'tipoTarListacolor'      => $tarifa['tipoTarListacolor'],
            'tipoTarOcultoenresumen' => $tarifa['tipoTarOcultoenresumen'],
            'montosoles'      => 0.0,
            'montodolares'    => 0.0,
            'ventasoles'      => 0.0,
            'ventadolares'    => 0.0,
            'adelantosoles'   => 0.0,
            'adelantodolares' => 0.0,
            'gananciasoles'   => 0.0,
            'gananciadolares' => 0.0,
        ];
    }

    /**
     * Acumula montos en un resumen.
     * - Suma montos y ventas en soles/dólares.
     * - Calcula adelantos y ganancias según el % de adelanto configurado.
     *
     * @param array &$resumen Referencia al arreglo del resumen a modificar.
     * @param array $tarifa Datos de la tarifa.
     * @param int $cantidad Factor multiplicador de la cantidad.
     * @return void
     */
    private function acumularMontos(array &$resumen, array $tarifa, int $cantidad = 1): void
    {
        $resumen['montosoles']   += $tarifa['montosoles']   * $cantidad;
        $resumen['montodolares'] += $tarifa['montodolares'] * $cantidad;
        $resumen['ventasoles']   += $tarifa['ventasoles']   * $cantidad;
        $resumen['ventadolares'] += $tarifa['ventadolares'] * $cantidad;

        $adelantoPct = $this->cotizacion->getAdelanto() / 100;
        $resumen['adelantosoles']   = $resumen['ventasoles']   * $adelantoPct;
        $resumen['adelantodolares'] = $resumen['ventadolares'] * $adelantoPct;
        $resumen['gananciasoles']   = $resumen['ventasoles']   - $resumen['montosoles'];
        $resumen['gananciadolares'] = $resumen['ventadolares'] - $resumen['montodolares'];

        foreach ([
                     'montosoles','montodolares',
                     'ventasoles','ventadolares',
                     'adelantosoles','adelantodolares',
                     'gananciasoles','gananciadolares'
                 ] as $key) {
            $resumen[$key] = number_format((float) $resumen[$key], 2, '.', '');
        }
    }

    /**
     * Construye el resumen de tarifas finalizando los valores consolidados.
     *
     * @return void
     */
    public function resumirTarifas(): void
    {
        $resumenGlobal = [];

        foreach ($this->tarifasClasificadas as $index => $clase) {
            $resumenClase = [];

            foreach ($clase['tarifas'] as $tarifa) {
                $tipoId = $tarifa['tipoTarId'];

                if (!isset($resumenGlobal[$tipoId])) {
                    $resumenGlobal[$tipoId] = $this->inicializarResumenGlobal($tarifa);
                }

                $this->acumularMontos($resumenGlobal[$tipoId], $tarifa, $clase['cantidad']);

                if (!isset($resumenClase[$tipoId])) {
                    $resumenClase[$tipoId] = $this->inicializarResumenClase($tarifa);
                }

                $this->acumularMontos($resumenClase[$tipoId], $tarifa);
            }

            ksort($resumenClase);
            $this->tarifasClasificadas[$index]['resumen'] = $resumenClase;
        }

        ksort($resumenGlobal);
        $this->resumenDeClasificado = $resumenGlobal;
    }

}