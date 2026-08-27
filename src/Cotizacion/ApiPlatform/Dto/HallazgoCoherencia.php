<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Annotation\Groups;

/** Un tipo de incoherencia y cuántas filas lo tienen. */
final class HallazgoCoherencia
{
    public function __construct(
        #[Groups(['coherencia:read'])]
        public readonly string $clave,
        #[Groups(['coherencia:read'])]
        public readonly string $titulo,
        /** Qué se rompe por culpa de esto, en una frase. No es prosa: es lo que decide si urge. */
        #[Groups(['coherencia:read'])]
        public readonly string $detalle,
        #[Groups(['coherencia:read'])]
        public readonly int $filas,
    ) {
    }
}
