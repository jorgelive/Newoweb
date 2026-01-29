<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsEventoEstadoPagoCrudController.
 * Maestro para la gestión de estados de pago (Pendiente, Parcial, Pagado).
 * Hereda de BaseCrudController y utiliza UUID v7 con prioridad de Roles.
 */
class PmsEventoEstadoPagoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventoEstadoPago::class;
    }

    /**
     * ✅ Configuración de acciones y permisos.
     * Prioridad absoluta a la clase Roles sobre la configuración base.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Estado de Pago')
            ->setEntityLabelInPlural('Estados de Pago')
            ->setDefaultSort([
                'orden' => 'ASC',
                'nombre' => 'ASC',
            ])
            ->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('nombre')
            ->add('color');
    }

    public function configureFields(string $pageName): iterable
    {
        // ✅ Manejo de UUID para visualización
        $id = TextField::new('id', 'Código (ID)')
            ->setHelp('ID único del sistema (Natural Key). Ej: pagado, pago-parcial, no-pagado')
            ->setFormTypeOption('attr', [
                'placeholder' => 'Ej: confirmada',
                'maxlength' => 50
            ]);

        // Lógica de visualización del ID:
        if (Crud::PAGE_NEW === $pageName) {
            // En creación es OBLIGATORIO escribirlo
            $id->setRequired(true);
        } elseif (Crud::PAGE_EDIT === $pageName) {
            // En edición se BLOQUEA (no se debe cambiar la PK)
            $id->setFormTypeOption('disabled', true);
        }

        $nombre = TextField::new('nombre', 'Nombre');

        $color = TextField::new('color', 'Color (HEX)')
            ->setHelp('Formato: #RRGGBB. Se usa si el Estado de Evento no fuerza su propio color.')
            ->setFormTypeOption('attr', [
                'maxlength' => 7,
                'pattern' => '^#?[0-9A-Fa-f]{6}$',
                'placeholder' => '#1A2B3C',
            ]);

        $orden = IntegerField::new('orden', 'Orden');

        // ✅ Auditoría mediante TimestampTrait (createdAt / updatedAt)
        $createdAt = DateTimeField::new('createdAt', 'Registrado')
            ->setFormat('yyyy/MM/dd HH:mm');

        $updatedAt = DateTimeField::new('updatedAt', 'Última Modificación')
            ->setFormat('yyyy/MM/dd HH:mm');

        // --- INDEX ---
        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $nombre,
                $color,
                $orden,
            ];
        }

        // --- DETAIL ---
        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                FormField::addPanel('Definición')->setIcon('fa fa-tag'),
                $id,
                $nombre,
                $orden,
                $color,

                FormField::addPanel('Auditoría')->setIcon('fa fa-history')->renderCollapsed(),
                $createdAt,
                $updatedAt,
            ];
        }

        // --- NEW / EDIT ---
        return [
            FormField::addPanel('Definición')->setIcon('fa fa-tag'),
            $nombre,
            $orden,

            FormField::addPanel('Visualización')->setIcon('fa fa-palette'),
            $color,

            FormField::addPanel('Tiempos de Sistema')->setIcon('fa fa-clock')->renderCollapsed(),
            $createdAt->onlyOnForms()->setFormTypeOption('disabled', true),
            $updatedAt->onlyOnForms()->setFormTypeOption('disabled', true),
        ];
    }
}