<?php
namespace App\Trait;

/**
 * Trait global para el control de traducciones automáticas.
 * Permite a cualquier entidad del sistema gestionar si debe disparar
 * peticiones externas a APIs de traducción durante el ciclo de vida de Doctrine.
 */
trait AutoTranslateControlTrait
{
    /**
     * Flag virtual (no persistido) para autorizar la traducción.
     */
    private bool $ejecutarTraduccion = false;

    /**
     * Getter explícito para el estado de traducción.
     */
    public function getEjecutarTraduccion(): bool
    {
        return $this->ejecutarTraduccion;
    }

    /**
     * Setter explícito que permite encadenamiento de métodos.
     */
    public function setEjecutarTraduccion(bool $ejecutarTraduccion): self
    {
        $this->ejecutarTraduccion = $ejecutarTraduccion;

        return $this;
    }
}