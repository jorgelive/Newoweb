<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/** Output de POST .../operacion/aplicar: qué se hizo realmente, no qué se propuso. */
final class ResultadoAplicacion
{
    public function __construct(
        #[Groups(['operacion:plan:read'])]
        public int $creados = 0,

        #[Groups(['operacion:plan:read'])]
        public int $actualizados = 0,

        #[Groups(['operacion:plan:read'])]
        public int $borrados = 0,

        /** Cambios del plan que la persona no aprobó. Se cuentan para que quede constancia. */
        #[Groups(['operacion:plan:read'])]
        public int $omitidos = 0,

        #[Groups(['operacion:plan:read'])]
        public string $mensaje = '',
    ) {}
}
