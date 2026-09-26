<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Dto\Lee;

/**
 * Lo que manda el navegador al suscribirse (o desuscribirse) a las notificaciones push, leído una
 * vez. Es la `PushSubscription` que da la Push API: el `endpoint` y las dos claves de cifrado.
 *
 * Los dos endpoints de `PushSubscriptionController` comparten campos; desuscribirse sólo usa el
 * `endpoint`.
 *
 * Se leen TAL CUAL (`Lee::texto()`, sin recortar): son claves criptográficas y una URL, y un
 * carácter de menos es una suscripción que no funciona. Lo único que cambia respecto a la lectura
 * de antes es que lo que no es texto —un array— es «no llegó» (400) en vez de guardar la palabra
 * «Array» como clave.
 */
final readonly class CuerpoDeSuscripcionPush
{
    private function __construct(
        public ?string $endpoint,
        public ?string $p256dh,
        public ?string $auth,
    ) {}

    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): self
    {
        return new self(
            endpoint: self::noVacio($datos['endpoint'] ?? null),
            p256dh: self::noVacio($datos['p256dh'] ?? null),
            auth: self::noVacio($datos['auth'] ?? null),
        );
    }

    private static function noVacio(mixed $valor): ?string
    {
        $texto = Lee::texto($valor);

        return $texto === '' ? null : $texto;
    }
}
