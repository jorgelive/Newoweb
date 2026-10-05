<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Define el rol COMERCIAL de una tarifa frente a las demás del mismo componente: si es la que se
 * vende o una alternativa que compite con ella (ej. Expedition/Vistadome/Hiram Bingham en el tren).
 *
 * A diferencia de los otros enums de este namespace, es intercambiable a nivel de snapshot: el
 * cliente puede promover una alternativa a estándar y viceversa.
 *
 * ⚠️ **Tuvo un tercer valor, `operativo`, y no era de esta familia** (quitado el 05/10/2026, fase 5
 * de `docs/PlanModalidadDeTarifa.md`). «Operativo» no decía si la línea compite con otra —no
 * compite con nada— sino **cómo se cuenta su dinero y si el cliente la ve**, que es justo lo que
 * responde {@see TarifaCalculoEnum}. Tenerlo aquí obligaba a preguntar por dos ejes a la vez para
 * saber una sola cosa, y dejaba sin expresar la combinación que hacía falta: multiplicar por
 * cantidad **y** repartir.
 */
enum TarifaRolEnum: string
{
    case ESTANDAR = 'estandar';
    case ALTERNATIVA = 'alternativa';

    /**
     * ¿Se la enseña al cliente?
     *
     * Las dos, sí: la estándar como lo contratado y la alternativa como opción de upgrade. Quien
     * esconde una línea es el CÁLCULO —`TarifaCalculoEnum::visibleParaCliente()`—, no el rol.
     */
    public function esVisibleParaCliente(): bool
    {
        return true;
    }

    /** Ajustable por el operador en las dos. La comisión 0 la impone el cálculo operativo. */
    public function comisionEditablePorDefecto(): bool
    {
        return true;
    }

    /** Sólo la estándar suma a la rama principal; la alternativa es venta opcional. */
    public function sumaRamaPrincipal(): bool
    {
        return $this === self::ESTANDAR;
    }
}