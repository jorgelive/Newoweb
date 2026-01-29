<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEventoEstado;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsEventoEstadoCrudController.
 * Gestión de los estados lógicos de una reserva/evento (Confirmado, Bloqueo, etc.).
 * Hereda de BaseCrudController y utiliza UUID v7.
 */
class PmsEventoEstadoCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEventoEstado::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Estado de Evento')
            ->setEntityLabelInPlural('Estados de Evento')
            ->setDefaultSort(['orden' => 'ASC', 'id' => 'ASC'])
            ->showEntityActionsInlined();
    }

    /**
     * ✅ Configuración de acciones y permisos prioritarios.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        // Los permisos se aplican después para que la clase Roles mande sobre la base
        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('id')
            ->add('nombre')
            ->add('codigoBeds24')
            ->add('colorOverride');
    }

    public function configureFields(string $pageName): iterable
    {
        // ✅ Manejo de UUID para visualización
        $id = TextField::new('id', 'Código (ID)')
            ->setHelp('ID único del sistema (Natural Key). Ej: confirmada, bloqueo, new.')
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

        $nombre = TextField::new('nombre', 'Nombre visible');

        $codigoBeds24 = TextField::new('codigoBeds24', 'Código Beds24')
            ->setHelp('Mapeo técnico para la API de Beds24.')
            ->setRequired(false);

        $color = TextField::new('color', 'Color (HEX)')
            ->setHelp('Ejemplo: #FFB300. Se usa para el renderizado del calendario.')
            ->setFormTypeOption('attr', [
                'maxlength' => 7,
                'pattern' => '^#?[0-9A-Fa-f]{6}$',
                'placeholder' => '#FFB300',
            ])
            ->setRequired(false);

        $colorOverride = BooleanField::new('colorOverride', 'Prioridad de Color')
            ->setHelp('Si se activa, este color prevalece sobre el color del estado de pago.')
            ->renderAsSwitch(true);

        $orden = IntegerField::new('orden', 'Orden Visual')
            ->setHelp('Posicionamiento en listas y selectores.')
            ->setRequired(false);

        // ✅ Auditoría mediante TimestampTrait (createdAt / updatedAt)
        $createdAt = DateTimeField::new('createdAt', 'Registrado')
            ->setFormat('yyyy/MM/dd HH:mm');

        $updatedAt = DateTimeField::new('updatedAt', 'Última Modificación')
            ->setFormat('yyyy/MM/dd HH:mm');

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                TextField::new('id', 'ID')->formatValue(fn($v) => substr((string)$v, 0, 8) . '...'),
                $nombre,
                $codigoBeds24,
                $color,
                $colorOverride,
                $orden,
            ];
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                FormField::addPanel('Identidad del Estado')->setIcon('fa fa-tag'),
                $id,
                $nombre,
                $codigoBeds24,

                FormField::addPanel('Configuración Visual')->setIcon('fa fa-palette'),
                $color,
                $colorOverride,
                $orden,

                FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->renderCollapsed(),
                $createdAt,
                $updatedAt,
            ];
        }

        // NEW / EDIT
        return [
            FormField::addPanel('Definición')->setIcon('fa fa-tag'),
            $nombre,
            $codigoBeds24,

            FormField::addPanel('Visualización')->setIcon('fa fa-palette'),
            $color,
            $colorOverride,
            $orden,

            FormField::addPanel('Tiempos')->setIcon('fa fa-history')->renderCollapsed(),
            $createdAt->onlyOnForms()->setFormTypeOption('disabled', true),
            $updatedAt->onlyOnForms()->setFormTypeOption('disabled', true),
        ];
    }
}