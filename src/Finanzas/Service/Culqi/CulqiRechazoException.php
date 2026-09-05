<?php

declare(strict_types=1);

namespace App\Finanzas\Service\Culqi;

use RuntimeException;

/**
 * Un rechazo de Culqi, con su cuerpo entero.
 *
 * 🔥 **Nace porque «rechazado» no es una sola cosa.** «Fondos insuficientes» es definitivo;
 * «Denegación sospecha de fraude, se solicita autenticación 3DS» es una **instrucción**: repite el
 * cobro después de autenticar. Con un `RuntimeException` de mensaje suelto los dos llegaban
 * iguales a quien los captura, y los dos se contestaban al cliente como un «no» final.
 *
 * El resultado medido: entre el 26/08 y el 05/09 **ninguna tarjeta extranjera pudo pagar** —cinco
 * denegaciones `DNGE0116` de España y Australia— mientras las peruanas pasaban. No fallaba el
 * cobro: fallaba que la respuesta no se leía entera.
 */
final class CulqiRechazoException extends RuntimeException
{
    /** @param array<string, mixed> $datos El cuerpo tal cual lo devolvió Culqi. */
    public function __construct(string $mensaje, private readonly array $datos)
    {
        parent::__construct($mensaje);
    }

    /** @return array<string, mixed> */
    public function datos(): array
    {
        return $this->datos;
    }

    /**
     * ¿Culqi está pidiendo autenticación 3-D Secure?
     *
     * ⚠️ **Se enumera lo que conocemos, y lo que no se registra.** `DNGE0116` es el código que han
     * devuelto las cinco denegaciones reales. La lista de códigos es de Culqi y crece, así que
     * tratar «cualquier cosa que no reconozco» como 3DS lanzaría retos sobre tarjetas sin fondos;
     * y al revés, callarse los desconocidos nos dejaría otra vez a ciegas. Por eso: se reconoce lo
     * medido y {@see self::codigo()} deja el resto a la vista en el log.
     */
    public function pideAutenticacion3DS(): bool
    {
        return $this->codigo() === 'DNGE0116';
    }

    public function codigo(): ?string
    {
        $codigo = $this->datos['outcome']['code'] ?? $this->datos['code'] ?? null;

        return is_string($codigo) ? $codigo : null;
    }

    public function motivoDelComercio(): ?string
    {
        $motivo = $this->datos['outcome']['merchant_message'] ?? $this->datos['merchant_message'] ?? null;

        return is_string($motivo) ? $motivo : null;
    }
}
