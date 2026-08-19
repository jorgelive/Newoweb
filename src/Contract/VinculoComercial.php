<?php

declare(strict_types=1);

namespace App\Contract;

/**
 * Qué vínculo tiene con nosotros quien escribe.
 *
 * ### Por qué no basta con «huésped o prospecto»
 *
 * Hasta ahora eso lo decidía {@see \App\Agent\Service\AiConversationProcessor} mirando SOLO
 * el tipo de contexto: si la conversación colgaba de una reserva, quien escribía era huésped.
 * Pero una consulta de Airbnb **es** una reserva (`pms_reserva`) en estado `inquiry`: nadie ha
 * reservado nada todavía.
 *
 * Consecuencia observada en producción: a una interesada que preguntaba detalles para decidir
 * si reservaba se le habló de «su reserva» y se le dio la dirección exacta del alojamiento —
 * justo lo que el partner prohíbe antes de confirmar. El dato para distinguirla existía desde
 * el principio (`status_tag` viajaba en `context_data`), pero no lo leía nadie.
 *
 * ### El pago NO está aquí, y es a propósito
 *
 * Un cliente con saldo pendiente sigue siendo cliente: el dinero condiciona QUÉ se le entrega
 * —los códigos de acceso, por ejemplo— pero no CON QUIÉN estamos hablando. Ese eje vive en
 * {@see \App\Agent\Access\NivelRiesgo} y en la visibilidad de la guía, no en este enum. Meterlo aquí obligaría a
 * duplicar cada valor en «pagado» y «sin pagar».
 */
enum VinculoComercial: string
{
    /** Nadie todavía: un número desconocido preguntando precios. */
    case Ninguno = 'ninguno';

    /** Hay un contexto a su nombre, pero sin confirmar. La consulta de OTA vive aquí. */
    case Interesado = 'interesado';

    /** Contexto confirmado. Cliente de pleno derecho, deba o no deba dinero. */
    case Cliente = 'cliente';

    /** El contexto murió: cancelado. Ni se le vende como a un desconocido ni se le atiende como a un cliente vivo. */
    case Terminado = 'terminado';

    /**
     * Traduce la etiqueta de estado del contexto.
     *
     * Los valores los produce `MessageContextInterface::getStatusTag()`; hoy el único
     * implementador es el PMS y devuelve `inquiry`, `confirmed` o `cancelled`. Un tipo que no
     * reconozcamos cae en {@see self::Interesado} y NO en `Cliente`: ante la duda, el trato
     * más restringido. Equivocarse hacia `Interesado` cuesta una venta algo torpe; hacia
     * `Cliente` cuesta filtrar datos que el partner prohíbe dar.
     */
    public static function deStatusTag(?string $statusTag): self
    {
        return match ($statusTag) {
            'confirmed' => self::Cliente,
            'cancelled' => self::Terminado,
            null => self::Ninguno,
            default => self::Interesado,
        };
    }

    /** ¿Hay un contexto vivo detrás al que se le pueda consultar algo suyo? */
    public function tieneContextoVivo(): bool
    {
        return self::Interesado === $this || self::Cliente === $this;
    }

    /** ¿Se le vende? Tanto al desconocido como al que aún no ha confirmado. */
    public function seLeVende(): bool
    {
        return self::Ninguno === $this || self::Interesado === $this;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ninguno => 'sin vínculo',
            self::Interesado => 'interesado',
            self::Cliente => 'cliente',
            self::Terminado => 'contexto terminado',
        };
    }
}
