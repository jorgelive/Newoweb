<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

use App\Entity\PushSubscription;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controlador CRUD para la auditoría de Suscripciones Push (WebPush).
 * Hereda de BaseCrudController para preservar la lógica transversal del panel.
 */
class PushSubscriptionCrudController extends BaseCrudController
{
    /**
     * Inyección de dependencias estricta alineada con el constructor de BaseCrudController.
     */
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PushSubscription::class;
    }

    /**
     * Configuración de permisos y acciones.
     * ✅ Se integran las constantes de la clase Roles para restringir el acceso.
     * ✅ Se deshabilitan NEW y EDIT para preservar la integridad (es una tabla de auditoría pura).
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            // Deshabilitamos creación y edición manual (los genera el navegador)
            ->disable(Action::NEW, Action::EDIT)

            // Permisos de lectura (Auditoría)
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)

            // Permisos de eliminación (Purga manual de dispositivos)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE)
            ->setPermission(Action::BATCH_DELETE, Roles::MAESTROS_DELETE);
    }

    /**
     * Opciones visuales generales y campos de búsqueda.
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Suscripción Push (Dispositivo)')
            ->setEntityLabelInPlural('Auditoría de Suscripciones Push')
            ->setSearchFields(['id', 'endpoint', 'user.email', 'user.username'])
            ->setDefaultSort(['id' => 'DESC'])
            ->showEntityActionsInlined();
    }

    /**
     * Filtros laterales para ubicar fácilmente los dispositivos de un inquilino/usuario.
     */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('user', 'Usuario Propietario'))
            ->add(TextFilter::new('endpoint', 'URL del Endpoint'));
    }

    /**
     * Configuración de columnas visibles en la tabla y en la vista de detalle.
     */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'ID')
            ->setColumns(2);

        yield AssociationField::new('user', 'Usuario (Dueño del Dispositivo)')
            ->setHelp('Usuario al que se le despacharán las notificaciones push si este dispositivo está activo.')
            ->setColumns(4);

        // UrlField es útil aquí porque corta los enlaces inmensos de FCM/Mozilla en el INDEX
        yield UrlField::new('endpoint', 'Endpoint del Proveedor')
            ->setHelp('Servidor puente que gestiona el enrutamiento (ej: fcm.googleapis.com).')
            ->setColumns(12);

        // --- Criptografía (Solo visibles en Detail para no saturar la tabla) ---

        yield TextField::new('p256dhKey', 'Llave Pública (P-256 DH)')
            ->hideOnIndex()
            ->setHelp('Llave asimétrica del cliente utilizada para encriptar los payloads.')
            ->setColumns(12);

        yield TextField::new('authToken', 'Auth Token (Secreto)')
            ->hideOnIndex()
            ->setHelp('Secreto compartido utilizado por el servidor para firmar los mensajes.')
            ->setColumns(12);
    }
}