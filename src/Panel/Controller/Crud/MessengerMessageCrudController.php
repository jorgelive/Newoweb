<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

use App\Entity\MessengerMessage;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controlador CRUD para la auditoría de la Cola de Mensajes (Symfony Messenger).
 * Hereda de BaseCrudController para preservar la lógica transversal del panel.
 */
class MessengerMessageCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MessengerMessage::class;
    }

    /**
     * Configuración de permisos y acciones.
     * Auditoría pura: Se deshabilitan NEW y EDIT.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            ->disable(Action::NEW, Action::EDIT)

            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)

            // Permitir DELETE sirve por si un job "tóxico" bloquea la cola y necesitas purgarlo manual.
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE)
            ->setPermission(Action::BATCH_DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Mensaje en Cola (Worker)')
            ->setEntityLabelInPlural('Auditoría de Colas (Messenger)')
            ->setSearchFields(['id', 'queueName', 'body'])
            // Los mensajes más nuevos primero
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    /**
     * Filtros para buscar tareas atascadas o fallidas.
     */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('queueName', 'Nombre de la Cola'))
            ->add(DateTimeFilter::new('createdAt', 'Fecha de Creación'))
            ->add(DateTimeFilter::new('availableAt', 'Disponible Para Ejecución'))
            ->add(DateTimeFilter::new('deliveredAt', 'Fecha de Ejecución/Fallo'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'ID')
            ->setColumns(2);

        // Dependiendo de tu config, puedes tener 'default', 'async', 'failed', etc.
        yield TextField::new('queueName', 'Cola de Destino')
            ->setColumns(10)
            ->formatValue(fn($val) => sprintf('<span class="badge badge-info">%s</span>', $val));

        // --- LÍNEA DE TIEMPO ---
        yield DateTimeField::new('createdAt', 'Creado El')
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->setColumns(4);

        yield DateTimeField::new('availableAt', 'Ejecutable Desde')
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->setHelp('A veces es en el futuro si usaste delay (retardos).')
            ->hideOnIndex()
            ->setColumns(4);

        yield DateTimeField::new('deliveredAt', 'Entregado / Fallido')
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->setHelp('Si está en la cola "failed" y tiene esta fecha, es cuando ocurrió el error fatal.')
            ->setColumns(4);

        // --- CARGAS ÚTILES (PAYLOADS) Y METADATOS ---
        // Symfony Messenger guarda los stamps (retry_count, error_messages) en los headers (JSON).
        yield CodeEditorField::new('headers', 'Cabeceras y Stamps (JSON)')
            ->hideOnIndex()
            ->setLanguage('javascript')
            ->setColumns(12)
            ->formatValue(function ($value) {
                if (!$value) return '{}';
                // Los headers nativos vienen en JSON
                $decoded = json_decode((string) $value, true);
                return $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $value;
            });

        // El Body contiene la clase de tu Job/Message serializada.
        yield CodeEditorField::new('body', 'Cuerpo del Mensaje (Clase Serializada)')
            ->hideOnIndex()
            ->setLanguage('javascript')
            ->setHelp('Contiene los parámetros exactos con los que se llamó a la tarea asíncrona.')
            ->setColumns(12)
            ->formatValue(function ($value) {
                if (!$value) return '';
                // Intenta formatear JSON si el serializador está en json
                $decoded = json_decode((string) $value, true);
                if ($decoded) {
                    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                // Si es un string serializado nativo de PHP, lo dejamos tal cual (o puedes aplicar un prettify básico)
                return $value;
            });
    }
}
