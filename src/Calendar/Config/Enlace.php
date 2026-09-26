<?php

declare(strict_types=1);

namespace App\Calendar\Config;

use App\Dto\Lee;

/**
 * Un enlace del panel legacy (EasyAdmin) que el provider pinta en el evento: `urledit`/`urlshow`.
 *
 * ```yaml
 * edit:
 *     route: panel_dashboard_pms_tarifa_rango_edit
 *     role: ROLE_RESERVAS_WRITE
 *     params: { tl: es }
 * ```
 *
 * ⚠️ **`rolDeclarado` existe por el provider de estancias, y no es redundante con `rol`.** Allí el
 * rol es OPCIONAL (sin `role` el enlace sale para cualquiera), y un `role` que viniera con otro tipo
 * —una lista, por ejemplo— se le pasaba tal cual a `isGranted()`, que lo DENEGABA. Si el DTO lo
 * leyera como «no hay rol», un rol mal escrito abriría el enlace a todos. Con los dos campos, el
 * provider sigue denegando: «declarado pero ilegible» no es «no declarado».
 */
final class Enlace
{
    /**
     * @param array<mixed> $parametros Se pasan tal cual al router, como antes: la forma la decide
     *        la ruta, no este DTO.
     */
    public function __construct(
        public readonly ?string $nombreRuta,
        public readonly ?string $rol,
        public readonly bool $rolDeclarado,
        public readonly array $parametros,
    ) {}

    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): self
    {
        return new self(
            nombreRuta: Lee::texto($datos['route'] ?? null),
            rol: Lee::texto($datos['role'] ?? null),
            rolDeclarado: isset($datos['role']),
            parametros: Lee::mapa($datos['params'] ?? null),
        );
    }
}
