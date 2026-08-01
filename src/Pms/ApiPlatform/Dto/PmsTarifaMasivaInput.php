<?php

declare(strict_types=1);

namespace App\Pms\ApiPlatform\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input de POST /pms/pms_tarifa_rangos/generar-masivo — equivalente del botón
 * "Generar Masivo" de EasyAdmin (PmsTarifaRangoCrudController::generarMasivo).
 *
 * Espeja campo a campo GeneradorTarifaMasivaDto (la lógica de negocio sigue
 * viviendo en GeneradorTarifaMasivaService; este DTO solo es la puerta HTTP).
 * Las fechas viajan como string ISO — misma convención que PmsReservaCrearInput —
 * y las parsea el processor, para no depender del formato que negocie el serializer.
 */
final class PmsTarifaMasivaInput
{
    #[Groups(['pms_tarifa_masiva:write'])]
    #[Assert\NotBlank(message: 'La fecha de inicio es obligatoria.')]
    public ?string $fechaInicio = null;

    #[Groups(['pms_tarifa_masiva:write'])]
    #[Assert\NotBlank(message: 'La fecha de fin es obligatoria.')]
    public ?string $fechaFin = null;

    /** Porcentaje de ajuste sobre la tarifa base de cada unidad (ej: 20 = +20%, -10 = -10%). */
    #[Groups(['pms_tarifa_masiva:write'])]
    #[Assert\NotNull]
    public float $porcentaje = 0.0;

    #[Groups(['pms_tarifa_masiva:write'])]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero(message: 'La estancia mínima no puede ser negativa.')]
    public int $minStay = 2;

    #[Groups(['pms_tarifa_masiva:write'])]
    #[Assert\NotNull]
    public int $prioridad = 0;

    #[Groups(['pms_tarifa_masiva:write'])]
    #[Assert\NotNull]
    public bool $importante = false;
}
