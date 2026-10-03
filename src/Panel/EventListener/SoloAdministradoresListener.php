<?php

declare(strict_types=1);

namespace App\Panel\EventListener;

use App\Panel\Contract\SoloAdministradores;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Cierra a quien no es admin cualquier acción de un CRUD marcado con {@see SoloAdministradores}:
 * el listado, la ficha, editar, borrar y las acciones propias. Lo mismo que ya decía el menú.
 */
#[AsEventListener(event: BeforeCrudActionEvent::class)]
final readonly class SoloAdministradoresListener
{
    public function __construct(private Security $security) {}

    public function __invoke(BeforeCrudActionEvent $evento): void
    {
        $crud = $evento->getAdminContext()?->getCrud()?->getControllerFqcn();

        if ($crud !== null && is_subclass_of($crud, SoloAdministradores::class) && !$this->security->isGranted(Roles::ADMIN)) {
            throw new AccessDeniedException('Esta sección es sólo para administradores.');
        }
    }
}
