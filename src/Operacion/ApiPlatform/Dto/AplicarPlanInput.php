<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body de POST .../operacion/aplicar: qué aprobó exactamente la persona que revisó.
 *
 * `aprobados` es un mapa `idDelCambio → campos`. Para `crear` y `huerfano` la lista de
 * campos se ignora (el cambio es atómico); para `actualizar` sólo se tocan los campos
 * nombrados, que es lo que permite aceptar la fecha nueva y conservar la hora que el
 * operador pactó con el proveedor.
 *
 * Lo que NO aparece aquí no se aplica. No hay «aplicar todo» implícito.
 */
final class AplicarPlanInput
{
    public function __construct(
        /** Firma del plan que se revisó. Si el estado se movió, `aplicar` lo rechaza. */
        #[Assert\NotBlank(message: 'Falta la firma del plan: vuelve a calcularlo.')]
        #[Groups(['operacion:plan:write'])]
        public string $firma = '',

        /** @var array<string, string[]> idDelCambio → nombres de campo aprobados */
        #[Groups(['operacion:plan:write'])]
        public array $aprobados = [],
    ) {}
}
