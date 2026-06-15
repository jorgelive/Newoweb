<?php

namespace App\Cotizacion\Enum;

/**
 * Define los estados operativos y logísticos de un componente o tarifa con un proveedor.
 *
 * Este Enum existe para garantizar la integridad de los datos en la base de datos y
 * asegurar que el motor de operaciones solo maneje transiciones de estado válidas.
 * Al ser un "Backed Enum" de tipo string, Doctrine puede persistirlo directamente
 * en la base de datos y API Platform puede serializar/deserializar automáticamente
 * el JSON desde y hacia el frontend en Vue.
 */
enum EstadoOperativo: string
{
    case SIN_SOLICITAR = 'Sin Solicitar';
    case SOLICITADO = 'Solicitado';
    case CONFIRMADO = 'Confirmado';
    case RECONFIRMADO = 'Reconfirmado';
    case PENDIENTE_PAGO = 'Pendiente Pago';
}