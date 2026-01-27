<?php
declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

trait LocatorTrait
{
    #[ORM\Column(type: 'string', length: 12, unique: true)]
    private ?string $localizador = null;

    public function getLocalizador(): ?string
    {
        return $this->localizador;
    }

    public function setLocalizador(string $localizador): self
    {
        $this->localizador = strtoupper($localizador);
        return $this;
    }

    /**
     * Genera un código aleatorio profesional (ej: XJ922M).
     * Se debe llamar en el constructor de la entidad.
     */
    public function initializeLocator(): void
    {
        if ($this->localizador === null) {
            $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
            $code = '';
            $max = strlen($alphabet) - 1;
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            $this->localizador = $code;
        }
    }
}