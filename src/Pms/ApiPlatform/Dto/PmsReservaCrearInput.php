<?php

declare(strict_types=1);

namespace App\Pms\ApiPlatform\Dto;

use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroPais;
use App\Pms\Entity\PmsUnidad;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input de POST /pms/pms_reservas — "Reserva completa" creada desde el calendario SPA.
 * Combina los datos del titular (PmsReserva) con la estancia inicial (PmsEventoCalendario)
 * en un único payload atómico: PmsReservaCrearProcessor persiste ambas entidades en un solo flush.
 *
 * Solo puede crear reservas DIRECTAS: no expone `channel`, el processor lo fuerza siempre a "directo".
 * Las relaciones (pmsUnidad, pais, idioma) se declaran con el tipo de entidad para que el
 * serializer de API Platform las resuelva automáticamente desde su IRI, igual que en los
 * demás recursos escribibles del módulo PMS.
 */
final class PmsReservaCrearInput
{
    // ==================== Titular ====================
    #[Groups(['pms_reserva_crear:write'])]
    #[Assert\NotBlank(message: 'El nombre del cliente es obligatorio.')]
    #[Assert\Length(max: 180)]
    public ?string $nombreCliente = null;

    #[Groups(['pms_reserva_crear:write'])]
    #[Assert\Length(max: 180)]
    public ?string $apellidoCliente = null;

    #[Groups(['pms_reserva_crear:write'])]
    #[Assert\Length(max: 30)]
    public ?string $telefono = null;

    #[Groups(['pms_reserva_crear:write'])]
    #[Assert\Length(max: 30)]
    public ?string $telefono2 = null;

    #[Groups(['pms_reserva_crear:write'])]
    #[Assert\Email(message: 'El formato del email no es válido.')]
    public ?string $emailCliente = null;

    #[Groups(['pms_reserva_crear:write'])]
    public ?MaestroPais $pais = null;

    /** Si no se envía, el processor usa MaestroIdioma::DEFAULT_IDIOMA. */
    #[Groups(['pms_reserva_crear:write'])]
    public ?MaestroIdioma $idioma = null;

    #[Groups(['pms_reserva_crear:write'])]
    public ?string $nota = null;

    // ==================== Estancia inicial ====================
    #[Groups(['pms_reserva_crear:write'])]
    #[Assert\NotNull(message: 'La unidad es obligatoria.')]
    public ?PmsUnidad $pmsUnidad = null;

    #[Groups(['pms_reserva_crear:write'])]
    #[Assert\NotBlank(message: 'La fecha de llegada es obligatoria.')]
    public ?string $inicio = null;

    #[Groups(['pms_reserva_crear:write'])]
    #[Assert\NotBlank(message: 'La fecha de salida es obligatoria.')]
    public ?string $fin = null;

    #[Groups(['pms_reserva_crear:write'])]
    #[Assert\PositiveOrZero]
    public int $cantidadAdultos = 1;

    #[Groups(['pms_reserva_crear:write'])]
    #[Assert\PositiveOrZero]
    public int $cantidadNinos = 0;

    #[Groups(['pms_reserva_crear:write'])]
    public ?string $descripcion = null;

    #[Groups(['pms_reserva_crear:write'])]
    public string $monto = '0.00';

    #[Groups(['pms_reserva_crear:write'])]
    public string $comision = '0.00';
}
