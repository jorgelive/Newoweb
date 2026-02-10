<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsEstablecimientoVirtual;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsEstablecimientoVirtualCrudController.
 * Gestión de Agrupaciones Lógicas o "Listings Comerciales".
 * Ej: "Saphy", "Inti".
 */
final class PmsEstablecimientoVirtualCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsEstablecimientoVirtual::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Establecimiento Virtual')
            ->setEntityLabelInPlural('Establecimientos Virtuales')
            ->setDefaultSort(['establecimiento' => 'ASC', 'nombre' => 'ASC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('establecimiento') // ✅ Filtro por Hotel
            ->add('nombre')
            ->add('codigo')
            ->add('codigoExterno')
            ->add('esPrincipal')
            ->add('createdAt');
    }

    public function configureFields(string $pageName): iterable
    {
        $id = TextField::new('id', 'UUID')
            ->onlyOnIndex()
            ->formatValue(fn($value) => substr((string)$value, 0, 8) . '...');

        $idFull = TextField::new('id', 'UUID Completo')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        // ✅ Ahora se vincula al Hotel, no a la Unidad
        $establecimiento = AssociationField::new('establecimiento', 'Establecimiento (Hotel)')
            ->setRequired(true)
            ->setHelp('El hotel o edificio físico al que pertenece este listing.');

        $nombre = TextField::new('nombre', 'Nombre Comercial')
            ->setHelp('Ej: Saphy, Inti, Booking Lujo.');

        $codigo = TextField::new('codigo', 'Código de Agrupación')
            ->setHelp('CRÍTICO: Identificador único del listing (Ej: "SAPHY").')
            ->setRequired(true);

        $codigoExterno = TextField::new('codigoExterno', 'Channel Prop ID')
            ->setHelp('ID de la propiedad en el canal (Ej: Hotel ID de Booking).')
            ->setRequired(false);

        $esPrincipal = BooleanField::new('esPrincipal', 'Es Principal')
            ->setHelp('Si se activa, las reservas manuales usarán este listing por defecto.')
            ->renderAsSwitch(true);

        $beds24Maps = AssociationField::new('beds24Maps', 'Mapas Técnicos')
            ->onlyOnDetail();

        $createdAt = DateTimeField::new('createdAt', 'Creado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        $updatedAt = DateTimeField::new('updatedAt', 'Actualizado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $id,
                $establecimiento,
                $nombre,
                $codigo,
                $esPrincipal,
                $codigoExterno,
            ];
        }

        return [
            FormField::addPanel('Identidad Comercial')->setIcon('fa fa-tag'),
            $idFull->onlyOnDetail(),
            $establecimiento,
            $nombre,
            $codigo,

            FormField::addPanel('Configuración de Canal')->setIcon('fa fa-globe'),
            $codigoExterno,
            $esPrincipal,

            FormField::addPanel('Vinculación Técnica')->setIcon('fa fa-link'),
            $beds24Maps,

            FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            $createdAt->hideWhenCreating(),
            $updatedAt->hideWhenCreating(),
        ];
    }
}