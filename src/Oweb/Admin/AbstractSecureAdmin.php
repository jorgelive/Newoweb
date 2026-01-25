<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;

/**
 * Solo necesitamos extender e implementar la interfaz.
 * Toda la lógica sucia está ahora en el SecurityHandler.
 */
abstract class AbstractSecureAdmin extends AbstractAdmin implements SecureModuleInterface
{
    // Forzamos a los hijos a definir esto
    abstract public function getModulePrefix(): string;
}