<?php

namespace App\Oweb\Admin;

interface SecureModuleInterface
{
    /**
     * Retorna el prefijo del rol. Ej: 'RESERVAS', 'OPERACIONES'.
     */
    public function getModulePrefix(): string;
}