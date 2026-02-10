<?php

declare(strict_types=1);

namespace App\Entity\Trait;

/**
 * Trait global para el control de traducciones automáticas.
 * Permite a cualquier entidad gestionar si debe disparar traducciones
 * y si debe forzar la sobreescritura de datos existentes.
 */
trait AutoTranslateControlTrait
{
    /**
     * Flag virtual para activar/desactivar todo el proceso.
     */
    private bool $ejecutarTraduccion = true;

    /**
     * Flag virtual para controlar la sobreescritura.
     * false (Default) = "Modo Seguro": Solo traduce idiomas que estén vacíos. Respeta lo existente.
     * true            = "Modo Forzado": Vuelve a traducir todo basándose en el idioma origen.
     */
    private bool $sobreescribirTraduccion = false;

    // --- Getters & Setters ---

    public function getEjecutarTraduccion(): bool
    {
        return $this->ejecutarTraduccion;
    }

    public function setEjecutarTraduccion(bool $ejecutarTraduccion): self
    {
        $this->ejecutarTraduccion = $ejecutarTraduccion;

        return $this;
    }

    public function getSobreescribirTraduccion(): bool
    {
        return $this->sobreescribirTraduccion;
    }

    public function setSobreescribirTraduccion(bool $sobreescribirTraduccion): self
    {
        $this->sobreescribirTraduccion = $sobreescribirTraduccion;

        return $this;
    }
}