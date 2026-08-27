<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Lo que el chequeo de coherencia encontró en una cotización.
 *
 * Se separan `reparables` y `avisos` en el DTO y no en el front por la misma razón por la que se
 * separan en el servicio: **es la distinción que decide si un botón puede tocar datos.** Dejarla
 * en la pantalla invitaría a que la próxima vista la interpretara distinto.
 */
final class InformeCoherencia
{
    /**
     * @param list<HallazgoCoherencia> $reparables lo que tiene una sola respuesta posible
     * @param list<HallazgoCoherencia> $avisos     lo que es una decisión y no se toca
     */
    public function __construct(
        #[Groups(['coherencia:read'])]
        public readonly array $reparables = [],
        #[Groups(['coherencia:read'])]
        public readonly array $avisos = [],
        /** Si esta llamada ya reparó, para que la pantalla no ofrezca hacerlo otra vez. */
        #[Groups(['coherencia:read'])]
        public readonly bool $reparado = false,
    ) {
    }
}
