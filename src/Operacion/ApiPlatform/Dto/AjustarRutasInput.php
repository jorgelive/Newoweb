<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Qué extremos se le imprimen al proveedor, línea por línea.
 *
 * Se manda el mapa completo de lo que cambia: `{ "<itemId>": { "recojo": "oculto", "entrega": "auto" } }`.
 * Sólo hacen falta las líneas que se tocan; el resto se queda como está.
 */
final class AjustarRutasInput
{
    /**
     * @var array<string, array{recojo?: string, entrega?: string}>
     */
    #[Groups(['operacion:orden:write'])]
    #[Assert\Count(min: 1, minMessage: 'No hay nada que ajustar.')]
    public array $visibilidad = [];
}
