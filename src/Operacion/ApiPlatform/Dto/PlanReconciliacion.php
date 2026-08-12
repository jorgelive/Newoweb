<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Output de POST .../operacion/plan. **No escribe nada**: es el diff para revisar.
 *
 * La `firma` es lo que convierte la aprobación en algo real. Entre ver el plan y
 * aprobarlo pueden pasar minutos, y en ese hueco otro operador puede cambiar una hora.
 * Si `aplicar` se limitara a ejecutar la lista de ids, estaría aplicando decisiones
 * tomadas sobre una realidad que ya no existe. Con la firma —un hash del estado que se
 * leyó— `aplicar` puede negarse: «esto cambió mientras revisabas, vuelve a calcular».
 * Sin ella la revisión sería decorativa.
 */
final class PlanReconciliacion
{
    public function __construct(
        #[Groups(['operacion:plan:read'])]
        public string $firma = '',

        /** @var CambioPropuesto[] */
        #[Groups(['operacion:plan:read'])]
        public array $cambios = [],

        /** Filas que ya coinciden con la cotización. Se cuentan, no se listan. */
        #[Groups(['operacion:plan:read'])]
        public int $sinCambios = 0,

        #[Groups(['operacion:plan:read'])]
        public string $mensaje = '',
    ) {}
}
