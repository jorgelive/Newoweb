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
     * Valida que la cotización tenga servicios, que cada servicio tenga componentes,
     * y que cada componente tenga tarifas. Luego construye un array temporal de
     * tarifas y las clasifica en clases según edad y tipo de pasajero.
     *
     * Además genera alertas si los servicios están separados por más de 20 días y
     * asegura que la cantidad de pasajeros coincida con la cantidad del componente.
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

        $existeAlertaDiferencia = false;
        $fechaHoraPrimerServicio = null;

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
     * Esta función compara la cantidad de pasajeros del componente con la cantidad total de pasajeros
     * definida en la cotización. Si no coincide, agrega un mensaje de error en FlashBag.
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
     * Ordena las clases de tarifas por edad mínima descendente.
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
     * @param CotizacionCotizacion $cotizacion Cotización actual, necesaria para cálculos de cantidad y comisión.
     * @param int &$cantidadComponente Variable por referencia para acumular cantidad total del componente.
     *
     * @return array|false Arreglo con todos los datos de la tarifa listos para clasificación o false si hay error.
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
     * Esta función transforma cada tarifa del componente en un formato interno
     * listo para ser clasificado por la función `procesarTarifa`. También detecta
     * tarifas duplicadas por tipo y marca si se debe usar título persistente.
     *
     * @param array $componente Lista de tarifas del componente.
     * @param int $cantidadTotalPasajeros Cantidad total de pasajeros a considerar.
     * @return bool Devuelve true si las tarifas se procesaron correctamente, false en caso contrario.
     */
    private function obtenerTarifasComponente(array $componente, int $cantidadTotalPasajeros): bool
    {
        $tarifasParaClasificar = []; // Array que contendrá todas las tarifas listas para clasificar
        $tiposAux = []; // Array auxiliar para detectar tipos duplicados y marcar títulos persistentes

        foreach ($componente as $tarifa) {
            // Determinar rango de edad de la tarifa, usando valores por defecto si no están definidos
            $min = $tarifa['edadMin'] ?? self::EDAD_MINIMA_DEFAULT;
            $max = $tarifa['edadMax'] ?? self::EDAD_MAXIMA_DEFAULT;

            // Creamos un arreglo temporal con la información de la tarifa para clasificación
            $temp = [
                'cantidad'          => $tarifa['cantidad'],
                'tipoPaxId'         => $tarifa['tipoPaxId'],
                'tipoPaxNombre'     => $tarifa['tipoPaxNombre'],
                'tipoPaxTitulo'     => $tarifa['tipoPaxTitulo'],
                'prorrateado'       => $tarifa['prorrateado'],
                'edadMin'           => $min,
                'edadMax'           => $max,
                'tipo'              => 'r' . $min . '-' . $max . 't' . $tarifa['tipoPaxId'], // identificador único de tipo
                'tituloOTipoTarifa' => $tarifa['titulo'] ?? $tarifa['tipoTarTitulo'],
                'tituloPersistente' => false, // se actualizará si existe un tipo duplicado
                'tarifa'            => $tarifa,
            ];

            // Si ya existe una tarifa con el mismo tipo, marcamos título persistente
            if (in_array($temp['tipo'], $tiposAux, true)) {
                $temp['tituloPersistente'] = true;
            }

            $tarifasParaClasificar[] = $temp; // agregamos la tarifa al array de clasificación
            $tiposAux[] = $temp['tipo'];      // registramos el tipo para futuras comparaciones
        }

        // Procesamos las tarifas si existen
        if (!empty($tarifasParaClasificar)) {
            if ($this->procesarTarifa($tarifasParaClasificar, $cantidadTotalPasajeros)) {
                $this->resetClasificacionTarifas(); // reiniciamos la clasificación para la siguiente operación
                return true;
            }
            // Si procesarTarifa falla, se devuelve false
        }

        return false;
    }

    /**
     * Reinicia la cantidad restante de todas las clases clasificadas.
     *
     * Esta función se utiliza para restaurar el estado original de las clases,
     * asignando la cantidad restante igual a la cantidad total de cada clase.
     *
     * No altera las demás propiedades de las clases.
     *
     * @return void
     */
    private function resetClasificacionTarifas(): void
    {
        // Recorremos cada clase y restauramos la cantidad restante
        foreach ($this->tarifasClasificadas as &$clase) {
            $clase['cantidadRestante'] = $clase['cantidad'];
        }
        unset($clase); // destruimos la referencia para evitar efectos secundarios
    }

    /**
     * Procesa y clasifica un conjunto de tarifas según las clases existentes.
     *
     * Esta función se encarga de:
     * 1. Inicializar clases si aún no existen (`$this->tarifasClasificadas`).
     * 2. Clasificar cada tarifa usando `clasificarTarifas` de forma recursiva.
     * 3. Validar y registrar errores si hay tarifas que no pudieron clasificarse.
     *
     * @param array $tarifasParaClasificar Lista de tarifas a procesar y clasificar.
     * @param int $cantidadTotalPasajeros Cantidad total de pasajeros para controlar la duplicación de clases.
     * @return bool Devuelve true si todas las tarifas fueron clasificadas correctamente, false si quedan tarifas sin clasificar.
     */
    private function procesarTarifa(array $tarifasParaClasificar, int $cantidadTotalPasajeros): bool
    {
        // ---------- Inicialización de clases ----------
        if (empty($this->tarifasClasificadas)) {
            $cantidadTemporal = 0;

            foreach ($tarifasParaClasificar as &$tarifa) {
                $auxClase = [
                    'tipo'             => $tarifa['tipo'],
                    'cantidad'         => $tarifa['cantidad'],
                    'cantidadRestante' => $tarifa['cantidad'],
                    'tipoPaxId'        => $tarifa['tipoPaxId'],
                    'tipoPaxNombre'    => $tarifa['tipoPaxNombre'],
                    'tipoPaxTitulo'    => $tarifa['tipoPaxTitulo'],
                ];

                // Agregamos rango de edad si existe
                if (isset($tarifa['edadMin'])) {
                    $auxClase['edadMin'] = $tarifa['edadMin'];
                }
                if (isset($tarifa['edadMax'])) {
                    $auxClase['edadMax'] = $tarifa['edadMax'];
                }

                // Limpiamos campos internos de la tarifa que no son necesarios
                unset($tarifa['tarifa']['cantidad'], $tarifa['tarifa']['montototal']);

                // Evitamos duplicar clases si coincide exactamente con el total de pasajeros
                if ($cantidadTemporal > 0 && $cantidadTotalPasajeros == $tarifa['cantidad']) {
                    continue;
                }

                // Agregamos la clase inicial a las tarifas clasificadas
                $this->tarifasClasificadas[] = $auxClase;
                $cantidadTemporal += $tarifa['cantidad'];

                // Detenemos la inicialización si alcanzamos el total de pasajeros
                if ($cantidadTemporal >= $cantidadTotalPasajeros) {
                    break;
                }
            }
            unset($tarifa); // Limpiamos referencia de foreach
        }

        // ---------- Clasificación recursiva ----------
        foreach ($tarifasParaClasificar as $key => &$tarifa) {
            $ejecucion = 0; // contador de recursión
            $this->clasificarTarifas($tarifa, $ejecucion, $tarifa['tituloPersistente']);

            // Eliminamos tarifas ya completamente clasificadas
            if ($tarifa['cantidad'] < 1) {
                unset($tarifasParaClasificar[$key]);
            }
        }
        unset($tarifa);

        // ---------- Validación de errores ----------
        if (!empty($tarifasParaClasificar)) {
            $tarifasDisplay = $this->generarResumenTarifas();
            $this->registrarErroresTarifas($tarifasParaClasificar, $tarifasDisplay);
            return false; // Hay tarifas que no se pudieron clasificar
        }

        return true; // Todas las tarifas se clasificaron correctamente
    }


    /**
     * Genera un resumen legible de las tarifas clasificadas.
     *
     * Recorre todas las clases de tarifas y construye un string con los detalles
     * de edad mínima, edad máxima, tipo de pasajero y cantidades. Además, establece
     * un mensaje en FlashBag según la disponibilidad de espacio en las tarifas.
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

            // Concatenamos los detalles de cada tarifa
            $tarifasDisplay .= '[' . implode(', ', $detalle) . '] ';

            // Guardamos la mayor cantidad restante
            if (($tarifa['cantidadRestante'] ?? 0) > $menorCantidadRestante) {
                $menorCantidadRestante = $tarifa['cantidadRestante'];
            }
        }

        // Flash general según disponibilidad
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
     * Toma la primera tarifa que no pudo clasificarse y genera un mensaje detallado
     * con nombre del servicio, componente, tipo de pasajero, edad y cantidad.
     * También muestra el resumen actual de todas las tarifas clasificadas.
     *
     * @param array $tarifasRestantes Tarifas que no pudieron clasificarse.
     * @param string $tarifasDisplay Resumen legible de las tarifas clasificadas.
     * @return void
     */
    private function registrarErroresTarifas(array $tarifasRestantes, string $tarifasDisplay): void
    {
        // Tomamos la primera tarifa que no se pudo clasificar
        $tarifaError = reset($tarifasRestantes);

        // Construimos un detalle completo de la tarifa
        $detalle = $tarifaError['tarifa']['nombreServicio']
            . ' - ' . $tarifaError['tarifa']['nombreComponente']
            . ' - ' . $tarifaError['tarifa']['nombre'];

        if (isset($tarifaError['tarifa']['edadMin'])) {
            $detalle .= ' - E min: ' . $tarifaError['tarifa']['edadMin'];
        }
        if (isset($tarifaError['tarifa']['edadMax'])) {
            $detalle .= ' - E max: ' . $tarifaError['tarifa']['edadMax'];
        }
        if (isset($tarifaError['tarifa']['tipoPaxNombre'])) {
            $detalle .= ' - tipo: ' . $tarifaError['tarifa']['tipoPaxNombre'];
        }

        $detalle .= ' - cantidad a clasificar: ' . $tarifaError['cantidad'];

        // Mensaje principal de error para esta tarifa
        $this->requestStack->getSession()->getFlashBag()->add(
            'error',
            sprintf('No se pudo clasificar: %s.', $detalle)
        );

        // Mensaje adicional con resumen de todas las tarifas
        if (!empty($tarifasDisplay)) {
            $this->requestStack->getSession()->getFlashBag()->add('error', 'Clasificación actual: ' . $tarifasDisplay);
        }
    }


    /**
     * Clasifica una tarifa dentro de las clases existentes o crea una nueva clase si es necesario.
     *
     * @param array $tarifaParaClasificar La tarifa que se desea clasificar.
     * @param int $ejecucion Número de iteraciones recursivas para evitar loops infinitos.
     * @param bool $tituloPersistente Si se deben concatenar títulos persistentes.
     * @return int La cantidad que no se pudo clasificar (0 si se clasificó completamente).
     */
    private function clasificarTarifas(array &$tarifaParaClasificar, int $ejecucion, bool $tituloPersistente = false): int
    {
        $ejecucion++;

        // Obtenemos el índice de la clase más adecuada usando la heurística del voter
        $voterIndex = $this->voter($tarifaParaClasificar);

        // Validación especial para prorrateo: si no hay clase y tarifa prorrateada
        if ($voterIndex < 0 && $this->cotizacion->getNumeropasajeros() == $tarifaParaClasificar['cantidad'] && $tarifaParaClasificar['prorrateado']) {
            foreach ($this->tarifasClasificadas as &$clase) {
                $clase['tarifas'][] = $tarifaParaClasificar['tarifa'];
            }
            unset($clase);
            $tarifaParaClasificar['cantidad'] = 0;
            return 0;
        }

        // Si no encontramos clase válida
        if ($voterIndex < 0) {
            // Creamos una nueva clase automáticamente con los parámetros de la tarifa
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

        // Concatenamos títulos persistentes si aplica
        if ($tituloPersistente) {
            $this->tarifasClasificadas[$voterIndex]['tituloPersistente'] =
                isset($this->tarifasClasificadas[$voterIndex]['tituloPersistente'])
                    ? sprintf('%s %s', $this->tarifasClasificadas[$voterIndex]['tituloPersistente'], $tarifaParaClasificar['tituloOTipoTarifa'])
                    : $tarifaParaClasificar['tituloOTipoTarifa'];
        }

        // Creamos copia de la clase seleccionada para manipular sin afectar la original de inmediato
        $copia = $this->tarifasClasificadas[$voterIndex];
        $edadMin = $copia['edadMin'] ?? self::EDAD_MINIMA_DEFAULT;
        $edadMax = $copia['edadMax'] ?? self::EDAD_MAXIMA_DEFAULT;

        // Ajustamos rangos de edad si la tarifa los redefine
        if (isset($tarifaParaClasificar['edadMin']) && $tarifaParaClasificar['edadMin'] > $edadMin) {
            $copia['edadMin'] = $tarifaParaClasificar['edadMin'];
        }
        if (isset($tarifaParaClasificar['edadMax']) && $tarifaParaClasificar['edadMax'] < $edadMax) {
            $copia['edadMax'] = $tarifaParaClasificar['edadMax'];
        }

        // Ajuste de tipoPax si no es genérico
        if ($tarifaParaClasificar['tipoPaxId'] != 0) {
            $copia['tipoPaxId'] = $tarifaParaClasificar['tipoPaxId'];
            $copia['tipoPaxNombre'] = $tarifaParaClasificar['tipoPaxNombre'];
            $copia['tipoPaxTitulo'] = $tarifaParaClasificar['tipoPaxTitulo'];
        }

        // Asignamos cantidades y tarifas
        $copia['tipo'] = $tarifaParaClasificar['tipo'];
        $copia['cantidad'] = $tarifaParaClasificar['cantidad'];
        $copia['cantidadRestante'] = $tarifaParaClasificar['cantidad'];
        $copia['tarifas'][] = $tarifaParaClasificar['tarifa'];

        $cantidadClase = $this->tarifasClasificadas[$voterIndex]['cantidadRestante'];

        // Distribución de cantidades
        if ($tarifaParaClasificar['cantidad'] == $cantidadClase) {
            $copia['cantidadRestante'] = 0;
            $this->tarifasClasificadas[$voterIndex] = $copia;
            $tarifaParaClasificar['cantidad'] = 0;

        } elseif ($tarifaParaClasificar['cantidad'] < $cantidadClase) {
            $copia['cantidadRestante'] = 0;
            $this->tarifasClasificadas[] = $copia;

            $this->tarifasClasificadas[$voterIndex]['cantidad'] -= $tarifaParaClasificar['cantidad'];
            $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] -= $tarifaParaClasificar['cantidad'];

            $tarifaParaClasificar['cantidad'] = 0;

        } else { // $tarifaParaClasificar['cantidad'] > $cantidadClase
            $tarifaParaClasificar['cantidad'] -= $cantidadClase;

            // Ajustamos clase existente con parámetros nuevos de la tarifa
            if (isset($tarifaParaClasificar['edadMin']) && $tarifaParaClasificar['edadMin'] > $edadMin) {
                $this->tarifasClasificadas[$voterIndex]['edadMin'] = $tarifaParaClasificar['edadMin'];
            }
            if (isset($tarifaParaClasificar['edadMax']) && $tarifaParaClasificar['edadMax'] < $edadMax) {
                $this->tarifasClasificadas[$voterIndex]['edadMax'] = $tarifaParaClasificar['edadMax'];
            }
            if ($tarifaParaClasificar['tipoPaxId'] != 0) {
                $this->tarifasClasificadas[$voterIndex]['tipoPaxId'] = $tarifaParaClasificar['tipoPaxId'];
                $this->tarifasClasificadas[$voterIndex]['tipoPaxNombre'] = $tarifaParaClasificar['tipoPaxNombre'];
                $this->tarifasClasificadas[$voterIndex]['tipoPaxTitulo'] = $tarifaParaClasificar['tipoPaxTitulo'];
            }

            $this->tarifasClasificadas[$voterIndex]['cantidadRestante'] = 0;
            $this->tarifasClasificadas[$voterIndex]['tarifas'][] = $tarifaParaClasificar['tarifa'];
        }

        // Recursión controlada hasta 10 iteraciones
        if ($tarifaParaClasificar['cantidad'] > 0 && $ejecucion < self::MAX_RECURSION) {
            $tarifaParaClasificar['cantidad'] = $this->clasificarTarifas($tarifaParaClasificar, $ejecucion, $tituloPersistente);
        }

        return $tarifaParaClasificar['cantidad'];
    }

    /**
     * Determina la clase más adecuada para una tarifa usando un sistema heurístico.
     *
     * La función compara la tarifa con las clases clasificadas según:
     * - Cantidad restante en la clase.
     * - Tipo de pasajero (tipoPaxId) coincidencia o genérico (0).
     * - Rango de edades compatible.
     * - Coincidencia exacta o cercanía en edad mínima y máxima.
     * - Coincidencia exacta en cantidad de pasajeros.
     *
     * @param array $tarifaParaClasificar Los datos de la tarifa que se desea clasificar. Debe contener:
     *                                   - 'tipoPaxId' => int
     *                                   - 'edadMin' => int
     *                                   - 'edadMax' => int
     *                                   - 'cantidad' => int
     *
     * @return int Retorna la clave de la clase con mayor puntaje.
     *             Retorna -1 si no hay coincidencias válidas.
     */
    private function voter(array $tarifaParaClasificar): int
    {
        // Inicializamos el array de puntajes por cada clase
        $voterArray = [];

        foreach ($this->tarifasClasificadas as $key => $clase) {
            $voterArray[$key] = 0;

            // Definimos los rangos de edad de la clase y de la tarifa
            $edadMinClase = $clase['edadMin'] ?? self::EDAD_MINIMA_DEFAULT;
            $edadMaxClase = $clase['edadMax'] ?? self::EDAD_MAXIMA_DEFAULT;
            $edadMinTarifa = $tarifaParaClasificar['edadMin'] ?? self::EDAD_MINIMA_DEFAULT;
            $edadMaxTarifa = $tarifaParaClasificar['edadMax'] ?? self::EDAD_MAXIMA_DEFAULT;

            // Comprobamos que la clase tenga cupo y que el tipo de pasajero y edades sean compatibles
            if ($clase['cantidadRestante'] > 0 &&
                ($tarifaParaClasificar['tipoPaxId'] == $clase['tipoPaxId'] ||
                    $tarifaParaClasificar['tipoPaxId'] == 0 ||
                    $clase['tipoPaxId'] == 0) &&
                $edadMinTarifa <= $edadMaxClase &&
                $edadMaxTarifa >= $edadMinClase
            ) {
                // Puntaje base por compatibilidad general
                $voterArray[$key] += 0.1;

                // Ajustamos puntaje por coincidencia exacta o cercanía de edad mínima
                $voterArray[$key] += $edadMinTarifa == $edadMinClase ? 1 : 1 / abs($edadMinTarifa - $edadMinClase);

                // Ajustamos puntaje por coincidencia exacta o cercanía de edad máxima
                $voterArray[$key] += $edadMaxTarifa == $edadMaxClase ? 1 : 1 / abs($edadMaxTarifa - $edadMaxClase);

                // Bonificación si la cantidad coincide exactamente
                if ($clase['cantidad'] == $tarifaParaClasificar['cantidad']) {
                    $voterArray[$key] += 0.3;
                }
            }
        }

        // Si no hay coincidencias válidas, retornamos -1
        if (empty($voterArray) || max($voterArray) <= 0) return -1;

        // Retornamos la clase con mayor puntaje
        return array_search(max($voterArray), $voterArray);
    }

    /**
     * Inicializa un resumen global con claves cortas.
     * Este resumen se usa para el total consolidado.
     */
    private function inicializarResumenGlobal(array $tarifa): array
    {
        return [
            // Claves cortas (para vistas globales)
            'nombre'          => $tarifa['tipoTarNombre'],
            'titulo'          => $tarifa['tipoTarTitulo'],
            'listacolor'      => $tarifa['tipoTarListacolor'],
            'ocultoenresumen' => $tarifa['tipoTarOcultoenresumen'],

            // Montos iniciales en 0
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
     */
    private function inicializarResumenClase(array $tarifa): array
    {
        return [
            // Claves largas (para vistas de detalle)
            'tipoTarNombre'          => $tarifa['tipoTarNombre'],
            'tipoTarTitulo'          => $tarifa['tipoTarTitulo'],
            'tipoTarListacolor'      => $tarifa['tipoTarListacolor'],
            'tipoTarOcultoenresumen' => $tarifa['tipoTarOcultoenresumen'],

            // Montos iniciales en 0
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
     * - Calcula adelantos y ganancias según el % de adelanto configurado en la cotización.
     * - Formatea todos los valores a string con 2 decimales.
     */
    private function acumularMontos(array &$resumen, array $tarifa, int $cantidad = 1): void
    {
        // 1. Sumar montos y ventas
        $resumen['montosoles']   += $tarifa['montosoles']   * $cantidad;
        $resumen['montodolares'] += $tarifa['montodolares'] * $cantidad;
        $resumen['ventasoles']   += $tarifa['ventasoles']   * $cantidad;
        $resumen['ventadolares'] += $tarifa['ventadolares'] * $cantidad;

        // 2. Calcular adelantos y ganancias
        $adelantoPct = $this->cotizacion->getAdelanto() / 100;
        $resumen['adelantosoles']   = $resumen['ventasoles']   * $adelantoPct;
        $resumen['adelantodolares'] = $resumen['ventadolares'] * $adelantoPct;
        $resumen['gananciasoles']   = $resumen['ventasoles']   - $resumen['montosoles'];
        $resumen['gananciadolares'] = $resumen['ventadolares'] - $resumen['montodolares'];

        // 3. Formatear todos los valores a string con 2 decimales
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
     * Construye el resumen de tarifas.
     * - $resumenGlobal → estructura de claves cortas (nombre, titulo, listacolor, ocultoenresumen).
     *   Es el consolidado de todas las clases.
     * - $resumenClase → estructura de claves largas (tipoTarNombre, tipoTarTitulo, tipoTarListacolor, tipoTarOcultoenresumen).
     *   Es el detalle por cada clase.
     */
    public function resumirTarifas(): void
    {
        $resumenGlobal = [];

        // Recorremos todas las clases de tarifas
        foreach ($this->tarifasClasificadas as $index => $clase) {
            $resumenClase = [];

            foreach ($clase['tarifas'] as $tarifa) {
                $tipoId = $tarifa['tipoTarId'];

                // 🔹 Inicializar resumen global si no existe aún
                if (!isset($resumenGlobal[$tipoId])) {
                    $resumenGlobal[$tipoId] = $this->inicializarResumenGlobal($tarifa);
                }

                // Acumular en el global (multiplicado por la cantidad de la clase)
                $this->acumularMontos($resumenGlobal[$tipoId], $tarifa, $clase['cantidad']);

                // 🔹 Inicializar resumen de clase si no existe aún
                if (!isset($resumenClase[$tipoId])) {
                    $resumenClase[$tipoId] = $this->inicializarResumenClase($tarifa);
                }

                // Acumular en la clase (sin multiplicar por la cantidad global)
                $this->acumularMontos($resumenClase[$tipoId], $tarifa);
            }

            // Ordenar detalle de la clase por ID de tipo
            ksort($resumenClase);

            // Guardar el resumen de la clase en la estructura principal
            $this->tarifasClasificadas[$index]['resumen'] = $resumenClase;
        }

        // Ordenar resumen global por ID de tipo
        ksort($resumenGlobal);

        // Guardar consolidado en la propiedad final
        $this->resumenDeClasificado = $resumenGlobal;
    }

}