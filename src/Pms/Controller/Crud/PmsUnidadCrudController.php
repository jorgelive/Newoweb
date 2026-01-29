<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsUnidad;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * PmsUnidadCrudController.
 * Gestión de unidades habitacionales (apartamentos/habitaciones).
 * Vincula establecimientos, tarifas base y mapeos con Beds24.
 */
final class PmsUnidadCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsUnidad::class;
    }

    /**
     * ✅ Configuración de acciones y permisos.
     * Prioridad absoluta a Roles sobre la configuración base del panel.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        // Obtenemos configuración global del panel base
        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Unidad')
            ->setEntityLabelInPlural('Unidades')
            ->setDefaultSort(['nombre' => 'ASC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('establecimiento')
            ->add('nombre')
            ->add('codigoInterno')
            ->add('capacidad')
            ->add('activo')
            ->add('tarifaBaseActiva')
            ->add('tarifaBasePrecio')
            ->add('tarifaBaseMinStay')
            ->add('tarifaBaseMoneda')
            ->add('beds24Maps')
            ->add('tarifaQueues')
            ->add('pullQueueJobs');
    }

    public function configureFields(string $pageName): iterable
    {
        $isNew = (Crud::PAGE_NEW === $pageName);

        // ✅ Manejo de UUID (IdTrait)
        $id = TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        $idFull = TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        $establecimiento = AssociationField::new('establecimiento', 'Establecimiento')
            ->setRequired(true);
        $nombre = TextField::new('nombre', 'Nombre');
        $codigoInterno = TextField::new('codigoInterno', 'Código interno')
            ->setRequired(false);
        $capacidad = IntegerField::new('capacidad', 'Capacidad')
            ->setRequired(false);

        $activo = BooleanField::new('activo', 'Activo')
            ->renderAsSwitch(true);

        // --- Tarifa base (Restricciones NotBlank obligatorias) ---
        $tarifaBaseActiva = BooleanField::new('tarifaBaseActiva', 'Tarifa base activa');

        $tarifaBasePrecio = NumberField::new('tarifaBasePrecio', 'Precio base')
            ->setNumDecimals(2)
            ->setRequired(true)
            ->setFormTypeOption('constraints', [new NotBlank()]);

        $tarifaBaseMinStay = IntegerField::new('tarifaBaseMinStay', 'Min. stay base')
            ->setRequired(true)
            ->setFormTypeOption('constraints', [new NotBlank()]);

        $tarifaBaseMoneda = AssociationField::new('tarifaBaseMoneda', 'Moneda base')
            ->setRequired(true)
            ->setFormTypeOptions([
                'constraints' => [new NotBlank()],
                'placeholder' => '',
                'attr' => [
                    'data-ea-widget' => 'ea-autocomplete',
                    'data-ea-autocomplete-allow-clear' => '0',
                ]
            ]);

        // --- Relaciones / Proceso ---
        $beds24Maps = AssociationField::new('beds24Maps', 'Beds24 Maps');

        $tarifaQueues = AssociationField::new('tarifaQueues', 'Tarifa Queues')
            ->setDisabled(true)
            ->onlyOnDetail();

        $pullQueueJobs = AssociationField::new('pullQueueJobs', 'Pull Queue Jobs')
            ->setDisabled(true)
            ->onlyOnDetail();

        // ✅ Auditoría mediante TimestampTrait (createdAt / updatedAt)
        $createdAt = DateTimeField::new('createdAt', 'Creado')
            ->setFormat('yyyy/MM/dd HH:mm');

        $updatedAt = DateTimeField::new('updatedAt', 'Actualizado')
            ->setFormat('yyyy/MM/dd HH:mm');

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $id, $nombre, $establecimiento, $codigoInterno, $capacidad,
                $tarifaBaseActiva, $tarifaBasePrecio, $tarifaBaseMinStay,
                $tarifaBaseMoneda, $activo, $beds24Maps,
            ];
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                FormField::addPanel('Información de Unidad')->setIcon('fa fa-home'),
                $idFull, $establecimiento, $nombre, $codigoInterno, $capacidad,
                FormField::addPanel('Estado Operativo')->setIcon('fa fa-toggle-on'),
                $activo,
                FormField::addPanel('Tarifario Base (Fallback)')->setIcon('fa fa-money-bill'),
                $tarifaBaseActiva, $tarifaBasePrecio, $tarifaBaseMinStay, $tarifaBaseMoneda,
                FormField::addPanel('Integración Beds24')->setIcon('fa fa-link'),
                $beds24Maps,
                FormField::addPanel('Trazabilidad Técnica')->setIcon('fa fa-cogs'),
                $tarifaQueues, $pullQueueJobs,
                FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
                $createdAt, $updatedAt,
            ];
        }

        return [
            FormField::addPanel('General')->setIcon('fa fa-home'),
            $establecimiento, $nombre, $codigoInterno, $capacidad,
            FormField::addPanel('Estado')->setIcon('fa fa-toggle-on'),
            $activo,
            FormField::addPanel('Tarifa base')->setIcon('fa fa-money-bill'),
            $tarifaBaseActiva, $tarifaBasePrecio, $tarifaBaseMinStay, $tarifaBaseMoneda,
            FormField::addPanel('Beds24')->setIcon('fa fa-link'),
            $beds24Maps,
            FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            $createdAt->onlyOnForms()->setFormTypeOption('disabled', true)->hideWhenCreating(),
            $updatedAt->onlyOnForms()->setFormTypeOption('disabled', true)->hideWhenCreating(),
        ];
    }
}