<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lo que hace falta para emitir una Orden de Servicio de un tirón.
 *
 * Se manda la lista de filas y el destinatario; el servidor valida, crea, enlaza, congela y
 * devuelve la orden entera. Antes esto eran *N* `PATCH` para atar cada fila más un `POST` para
 * la cabecera: si la pestaña se caía en medio quedaban filas atadas a una orden inexistente.
 */
final class EmitirOrdenInput
{
    /**
     * Las filas de La Biblia que entran en la orden.
     *
     * @var list<string>
     */
    #[Groups(['operacion:orden:write'])]
    #[Assert\Count(min: 1, minMessage: 'Elige al menos un servicio para la orden.')]
    // La forma se garantiza AQUÍ, en el borde, y no con un `is_string()` río abajo: así el
    // error sale como 422 con el campo señalado, y el procesador puede fiarse del tipo.
    #[Assert\All([new Assert\Type('string'), new Assert\Uuid(message: '«{{ value }}» no es un identificador de servicio válido.')])]
    public array $servicioIds = [];

    #[Groups(['operacion:orden:write'])]
    #[Assert\NotBlank(message: 'La orden necesita un número.')]
    public string $numeroOs = '';

    /** A quién se le manda el encargo. Vacío = se toma el comprador efectivo de las filas. */
    #[Groups(['operacion:orden:write'])]
    public ?string $compradorMaestroId = null;

    #[Groups(['operacion:orden:write'])]
    public ?string $compradorNombre = null;

    /**
     * La orden que ésta sustituye, si se está reemitiendo.
     *
     * Se anula ahí mismo: reemitir es **anular y crear la sucesora**, no reescribir el
     * documento anterior.
     */
    #[Groups(['operacion:orden:write'])]
    public ?string $reemplazaAId = null;

    /**
     * ¿Se deja en borrador en vez de emitir?
     *
     * Un borrador NO congela: sigue siendo una vista viva mientras se compone.
     */
    #[Groups(['operacion:orden:write'])]
    public bool $soloBorrador = false;
}
