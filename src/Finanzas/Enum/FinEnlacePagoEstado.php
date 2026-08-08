<?php

declare(strict_types=1);

namespace App\Finanzas\Enum;

/**
 * Ciclo de vida de un enlace de pago.
 *
 * Ojo con la asimetría: PAGADO es **terminal e irreversible** desde el sistema. Un cobro
 * que ya pasó por la pasarela no se "des-paga" borrando el enlace; se anula devolviendo el
 * dinero en el Backoffice de la pasarela y eliminando después el pago que generó. Por eso
 * `esFinal()` incluye PAGADO: ningún webhook posterior puede sacarlo de ahí (ver
 * `FinEnlacePagoService::confirmarPago()`, que ignora reintentos sobre enlaces ya pagados).
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

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::PAGADO    => 'Pagado',
            self::FALLIDO   => 'Fallido',
            self::EXPIRADO  => 'Expirado',
            self::ANULADO   => 'Anulado',
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
            self::PAGADO, self::EXPIRADO, self::ANULADO => true,
            self::PENDIENTE, self::FALLIDO              => false,
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
