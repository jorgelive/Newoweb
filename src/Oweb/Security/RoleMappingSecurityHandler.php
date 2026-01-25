<?php

namespace App\Oweb\Security;

use App\Oweb\Admin\SecureModuleInterface;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Security\Handler\SecurityHandlerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

class RoleMappingSecurityHandler implements SecurityHandlerInterface
{
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker
    ) {}

    // Mapeo: Acción de Oweb => Tu Sufijo
    private const ACTION_MAP = [
        'list'      => '_SHOW',
        'show'      => '_SHOW',
        'view'      => '_SHOW',
        'export'    => '_SHOW',

        'create'    => '_WRITE',
        'edit'      => '_WRITE',
        'history'   => '_WRITE',

        'delete'    => '_DELETE',
        'batch'     => '_DELETE',
    ];

    public function isGranted(AdminInterface $admin, $attributes, ?object $object = null): bool
    {
        // Si no es un array, lo convertimos (Oweb a veces manda strings)
        if (!is_array($attributes)) {
            $attributes = [$attributes];
        }

        foreach ($attributes as $action) {
            // Normalizamos la acción
            $action = strtolower($action);

            // 1. Si el Admin implementa nuestra interfaz, usamos su prefijo
            if ($admin instanceof SecureModuleInterface && isset(self::ACTION_MAP[$action])) {
                $prefix = strtoupper($admin->getModulePrefix());
                $suffix = self::ACTION_MAP[$action];

                // Construimos tu rol: ROLE_RESERVAS_WRITE
                $requiredRole = sprintf('ROLE_%s%s', $prefix, $suffix);

                try {
                    if ($this->authorizationChecker->isGranted($requiredRole, $object)) {
                        return true;
                    }
                } catch (AuthenticationCredentialsNotFoundException $e) {
                    return false;
                }
            }

            // 2. FALLBACK: Si no implementa la interfaz o es una acción rara (ej: 'acl'),
            // dejamos pasar a los Super Admins por defecto o bloqueamos.
            // Aquí decimos: si es Super Admin pasa, si no, denegado.
            elseif ($this->authorizationChecker->isGranted('ROLE_SUPER_ADMIN')) {
                return true;
            }
        }

        return false;
    }

    public function getBaseRole(AdminInterface $admin): string
    {
        // Método requerido por la interfaz pero irrelevante para nuestra lógica custom
        return 'ROLE_SONATA_ADMIN';
    }

    public function buildSecurityInformation(AdminInterface $admin): array
    {
        return [];
    }

    public function createObjectSecurity(AdminInterface $admin, object $object): void
    {
        // No usamos ACLs, no hacemos nada.
    }

    public function deleteObjectSecurity(AdminInterface $admin, object $object): void
    {
        // No usamos ACLs, no hacemos nada.
    }
}