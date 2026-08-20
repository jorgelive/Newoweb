<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * El cambio de estado de una Orden, como acción y no como campo.
 *
 * ⚠️ `estadoOs` **no está en `operacion:write`**, y es deliberado: con dos puertas a la misma
 * transición las reglas se escapan por la que no mira nadie. Emitir congela el contenido y
 * anular suelta las filas — dos cosas que un `PATCH` genérico no sabe distinguir de corregir
 * el número de la orden.
 */
final class CambiarEstadoOrdenInput
{
    #[Groups(['operacion:orden:write'])]
    #[Assert\NotBlank(message: 'Falta el estado al que se pasa.')]
    #[Assert\Choice(
        choices: ['borrador', 'emitida', 'confirmada', 'completada', 'cancelada'],
        message: '«{{ value }}» no es un estado de orden válido.'
    )]
    public string $estado = '';

    /** Por qué se anula. Queda en la orden como parte de su historia. */
    #[Groups(['operacion:orden:write'])]
    public ?string $motivo = null;
}
