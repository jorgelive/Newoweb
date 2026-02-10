<?php

declare(strict_types=1);

namespace App\Pms\Service\Tarifa\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class GeneradorTarifaMasivaDto
{
    #[Assert\NotBlank]
    public ?\DateTimeInterface $fechaInicio = null;

    #[Assert\NotBlank]
    #[Assert\GreaterThan(propertyPath: 'fechaInicio')]
    public ?\DateTimeInterface $fechaFin = null;

    /**
     * Porcentaje de ajuste sobre la tarifa base (ej: 20 para +20%, -10 para -10%).
     */
    #[Assert\NotNull]
    public float $porcentaje = 0.0;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public int $minStay = 2;

    #[Assert\NotNull]
    public int $prioridad = 0;

    #[Assert\NotNull]
    public bool $importante = false;
}