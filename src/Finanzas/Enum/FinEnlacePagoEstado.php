<?php

declare(strict_types=1);

namespace App\Finanzas\Enum;

/**
 * Ciclo de vida de un enlace de pago.
 *
 * Ojo con la asimetría: PAGADO es **terminal** desde el sistema. Ningún webhook posterior
 * puede sacarlo de ahí (ver `FinEnlacePagoService::confirmarPago()`, que ignora reintentos
 * sobre enlaces ya pagados), y `esFinal()` lo incluye por eso.
 *
 * Del PAGADO sólo se sale por una puerta, y es hacia adelante: REEMBOLSADO. **No se vuelve a
 * PENDIENTE ni se borra nada** — el cobro ocurrió, y lo que hubo después fue otro hecho, no
 * la cancelación del primero.
 */
enum FinEnlacePagoEstado: string
{
    /** Creado y vigente: el cliente todavía puede pagar. */
    case PENDIENTE = 'pendiente';

    /** La pasarela confirmó el cobro por webhook con firma válida. */
    case PAGADO = 'pagado';

    /** La pasarela rechazó la transacción. El enlace sigue siendo reutilizable. */
    case FALLIDO = 'fallido';

    /** Pasó `expiraEn` sin pagarse. Lo marca `FinEnlacePago::estaVigente()` al leerlo. */
    case EXPIRADO = 'expirado';

    /** El operador lo canceló a mano. */
    case ANULADO = 'anulado';

    /**
     * Se cobró y luego se devolvió el dinero por la pasarela.
     *
     * Sólo se llega desde PAGADO, y la transición la escribe `FinEnlacePagoService::reembolsar()`
     * **después** de que Culqi confirme la devolución — nunca antes: un estado que se adelanta al
     * dinero es exactamente la mentira que este estado vino a quitar.
     */
    case REEMBOLSADO = 'reembolsado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::PAGADO    => 'Pagado',
            self::FALLIDO   => 'Fallido',
            self::EXPIRADO  => 'Expirado',
            self::ANULADO   => 'Anulado',
            self::REEMBOLSADO => 'Reembolsado',
        };
    }

    /**
     * ¿El estado admite todavía un intento de pago?
     *
     * FALLIDO no es final a propósito: que una tarjeta rebote no invalida el enlace, el
     * cliente reintenta con otra en la misma URL.
     */
    public function esFinal(): bool
    {
        return match ($this) {
            self::PAGADO, self::EXPIRADO, self::ANULADO, self::REEMBOLSADO => true,
            self::PENDIENTE, self::FALLIDO                                 => false,
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(
            static fn (self $caso): array => ['value' => $caso->value, 'label' => $caso->etiqueta()],
            self::cases(),
        );
    }
}
