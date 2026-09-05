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
     * ⚠️ **Se enumera lo que conocemos, y lo que no se registra.** La lista de códigos es de Culqi
     * y crece, así que tratar «cualquier cosa que no reconozco» como 3DS lanzaría retos sobre
     * tarjetas sin fondos; y al revés, callarse los desconocidos nos dejaría otra vez a ciegas.
     * Por eso: se reconoce lo medido y {@see self::codigo()} deja el resto a la vista en el log.
     *
     * Se miran **tres** señales, y las tres hacen falta porque llegan por caminos distintos:
     *
     * | Señal | De dónde sale |
     * |---|---|
     * | `action_code: REVIEW` | el cuerpo 200 del POST — es lo que mira el demo oficial de Culqi |
     * | `outcome.decline_code: authentication_required` | el objeto `charge` denegado (GET) |
     * | `code: DNGE0116` | el código de las cinco denegaciones reales de España y Australia |
     *
     * La primera es la que de verdad importa: al pedir el reto, Culqi **no** contesta con un
     * error, contesta 200 con un cuerpo que no es un cargo. Reconocer sólo el código dejaba el
     * reto inalcanzable, porque ese código llega por la puerta del cargo ya denegado.
     */
    public function pideAutenticacion3DS(): bool
    {
        if (($this->datos['action_code'] ?? null) === 'REVIEW') {
            return true;
        }

        $motivo = $this->datos['outcome']['decline_code'] ?? $this->datos['decline_code'] ?? null;

        if ($motivo === 'authentication_required') {
            return true;
        }

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
