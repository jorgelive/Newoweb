<?php

namespace App\Panel\Controller\Crud;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

// Necesario para detectar el ID por atributo

/**
 * Controlador Base "Cerebro Central".
 * BLINDADO: Soporta entidades con IDs exóticos (Strings, Custom Keys) sin romper la navegación.
 */
abstract class BaseCrudController extends AbstractCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {}

    public function configureActions(Actions $actions): Actions
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) return $actions;

        $returnTo = $request->query->get('returnTo');

        // 1. AUTO-GENERACIÓN
        if (!$returnTo && $this->isIndexPage($request)) {
            $returnTo = base64_encode($request->getUri());
            $request->query->set('returnTo', $returnTo);
        }

        // 2. GESTIÓN DE BOTONES
        if ($returnTo) {

            // A. PROPAGADOR (Hacia adelante)
            // CAMBIO: Usamos getSafeId() en lugar de getId() directo
            $propagator = function (Action $action) use ($returnTo) {
                return $action->linkToUrl(function ($entity = null) use ($action, $returnTo) {
                    return $this->adminUrlGenerator
                        ->setController(static::class)
                        ->setAction($action->getAsDto()->getName())
                        ->setEntityId($this->getSafeId($entity)) // <--- AQUÍ ESTÁ EL PARCHE
                        ->set('returnTo', $returnTo)
                        ->generateUrl();
                });
            };

            // B. CONSUMIDOR (Hacia atrás)
            $consumer = function (Action $action) use ($returnTo) {
                $decodedUrl = base64_decode((string) $returnTo, true);
                if ($decodedUrl && filter_var($decodedUrl, FILTER_VALIDATE_URL)) {
                    return $action->linkToUrl($decodedUrl);
                }
                return $action;
            };

            $this->updateIfExisting($actions, Crud::PAGE_INDEX, Action::NEW, $propagator);
            $this->updateIfExisting($actions, Crud::PAGE_INDEX, Action::EDIT, $propagator);
            $this->updateIfExisting($actions, Crud::PAGE_INDEX, Action::DETAIL, $propagator);
            $this->updateIfExisting($actions, Crud::PAGE_DETAIL, Action::EDIT, $propagator);
            $this->updateIfExisting($actions, Crud::PAGE_DETAIL, Action::INDEX, $consumer);
            $this->updateIfExisting($actions, Crud::PAGE_EDIT, Action::DETAIL, $propagator);
            $this->updateIfExisting($actions, Crud::PAGE_EDIT, Action::INDEX, $consumer);
            $this->updateIfExisting($actions, Crud::PAGE_NEW, Action::INDEX, $consumer);
        }

        return $actions;
    }

    private function updateIfExisting(Actions $actions, string $pageName, string $actionName, callable $callable): void
    {
        try {
            $actions->update($pageName, $actionName, $callable);
        } catch (\InvalidArgumentException $e) { }
    }

    protected function isIndexPage(Request $request): bool
    {
        $crudAction = $request->query->get(EA::CRUD_ACTION);
        if ($crudAction === Action::INDEX) return true;
        if ($crudAction === Action::DETAIL || $crudAction === Action::EDIT || $crudAction === Action::NEW) return false;

        $path = $request->getPathInfo();

        if (str_ends_with($path, '/new') ||
            str_contains($path, '/edit') ||
            str_contains($path, '/batch') ||
            str_contains($path, '/render-filters') ||
            str_contains($path, '/autocomplete') ||
            str_contains($path, '/login')) {
            return false;
        }

        if (preg_match('/\/(?:\d+|[a-f0-9-]{20,})$/i', $path)) {
            return false;
        }

        return true;
    }

    /**
     * 🔥 MAGIA ANTICRASH: Obtiene el ID de cualquier entidad.
     * 1. Intenta getId().
     * 2. Si falla, busca la propiedad marcada con #[Id] o #[ORM\Id] usando Reflexión.
     */
    private function getSafeId(mixed $entity): mixed
    {
        if ($entity === null) {
            return null;
        }

        // Opción A: Estándar (Rápida)
        if (method_exists($entity, 'getId')) {
            return $entity->getId();
        }

        // Opción B: Reflexión para encontrar la Primary Key (Lenta pero segura)
        try {
            $reflection = new \ReflectionClass($entity);
            foreach ($reflection->getProperties() as $property) {
                // Buscamos atributos de Doctrine
                $attributes = $property->getAttributes();
                foreach ($attributes as $attribute) {
                    $name = $attribute->getName();
                    // Soporte para atributos modernos de PHP 8 (Doctrine ORM)
                    if (str_contains($name, 'Mapping\\Id') || $name === 'Doctrine\ORM\Mapping\Id') {
                        // Permitir leer privados
                        return $property->getValue($entity);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Si todo falla, no rompemos la app, devolvemos null.
            return null;
        }

        return null;
    }
}