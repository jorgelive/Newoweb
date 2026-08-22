<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filas de La Biblia que se suman a una Orden que ya existe.
 *
 * El destinatario no viaja: lo pone la orden, que ya lo tiene decidido. Mandarlo otra vez sería
 * ofrecer la posibilidad de cambiarlo por la puerta de atrás, y una orden que cambia de comprador
 * a mitad de composición es dos órdenes distintas con el mismo número.
 */
final class AgregarAOrdenInput
{
    /** @var list<string> */
    #[Groups(['operacion:orden:write'])]
    #[Assert\Count(min: 1, minMessage: 'Elige al menos un servicio para agregar.')]
    #[Assert\All([new Assert\Type('string'), new Assert\Uuid(message: '«{{ value }}» no es un identificador de servicio válido.')])]
    public array $servicioIds = [];
}
