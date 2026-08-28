<?php

declare(strict_types=1);

namespace App\Pms\Enum;

/**
 * Por qué no hay nada que pedir. Un enum y no una frase: la frase la redacta quien rinde.
 *
 * Cada caso lleva una consecuencia distinta para el que lo lee, y por eso no se colapsan en
 * un `null`: «ya pagó» y «no puedo darte cifras» se parecen en el saldo y en nada más.
 */
enum PmsMotivoSinCobro
{
    /** Está saldada: cargos y pagos cuadran. */
    case SALDADA;

    /** Pagó de más. No es «nada que pedir»: ese dinero es suyo. */
    case SALDO_A_FAVOR;

    /** El canal cobró por nosotros y no quedan extras. Se dice sin cifras. */
    case COBRA_EL_CANAL;

    /** Cancelada y sin penalización que reclamar. */
    case CANCELADA;

    /** La reserva todavía no tiene cargos. */
    case SIN_CARGOS;

    /**
     * Pagó en una moneda en la que no debe nada, y nadie ha imputado ese cobro.
     *
     * ⚠️ No es «saldo a favor» ni «debe»: es que **ya pagó y el sistema todavía no lo sabe**.
     * El caso real es la reserva GASUNN — cargos por US$ 66.17 y un Yape de S/ 223.70 sin
     * imputar. Sumando cada moneda por su lado, esa ficha dice «debe US$ 65.97» y «tiene
     * S/ 223.70 a favor», y pedirle el total a esa persona es reclamarle dinero que ya dio.
     *
     * Lo resuelve un humano con un clic —imputar el cobro a la deuda en dólares, §12.2b— y
     * hasta que lo haga **no se le pide nada**.
     */
    case CRUCE_DE_MONEDAS;

    /**
     * Hay cargos que no suman —les falta el tipo de cambio— así que **no se dan cifras**.
     *
     * Sin este caso, el modelo lava datos malos con confianza. Los dos fallos más caros de
     * agosto de 2026 fueron de datos, no de código.
     */
    case DATOS_INCOMPLETOS;
}
