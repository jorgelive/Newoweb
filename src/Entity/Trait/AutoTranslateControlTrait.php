<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Trait global para el control de traducciones automáticas.
 * Permite a cualquier entidad gestionar si debe disparar traducciones
 * y si debe forzar la sobreescritura de datos existentes.
 */
trait AutoTranslateControlTrait
{
    /**
     * Flag virtual (no mapeado en base de datos) para activar/desactivar el proceso en tiempo de ejecución.
     * Ideal para apagar el listener temporalmente durante importaciones masivas (fixtures, comandos).
     */
    private bool $ejecutarTraduccion = true;

    /**
     * Flag físico (mapeado en BD) para controlar la sobreescritura y "despertar" a Doctrine.
     * false (Default) = "Modo Seguro": Solo traduce idiomas que estén vacíos. Respeta lo existente.
     * true            = "Modo Forzado": Vuelve a traducir basándose en el idioma origen.
     *
     * Al estar mapeado en la base de datos, cualquier cambio desde EasyAdmin obligará a
     * Doctrine a calcular un ChangeSet y disparar el evento preUpdate.
     * El AutoTranslationService lo devolverá a false automáticamente tras ejecutarse.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
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