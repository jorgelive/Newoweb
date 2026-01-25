<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsUnidadBeds24Map;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

final class PmsUnidadBeds24MapCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsUnidadBeds24Map::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Map Beds24')
            ->setEntityLabelInPlural('Maps Beds24')
            ->setDefaultSort(['pmsUnidad' => 'ASC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);
        return parent::configureActions($actions);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('beds24Config')
            ->add('beds24PropertyId')
            ->add('pmsUnidad')
            ->add('beds24RoomId')
            ->add('beds24UnitId')
            ->add('channelPropId')
            ->add('activo')
            ->add('esPrincipal')
            ->add('created')
            ->add('updated');
    }

    public function configureFields(string $pageName): iterable
    {
        $isNew = (Crud::PAGE_NEW === $pageName);

        // ----------------
        // Campos principales
        // ----------------
        $id = IdField::new('id')->onlyOnIndex();

        $beds24Config = AssociationField::new('beds24Config', 'Beds24 Config');
        $pmsUnidad = AssociationField::new('pmsUnidad', 'Unidad PMS');

        $beds24PropertyId = IntegerField::new('beds24PropertyId', 'Beds24 Property ID')
            ->setRequired(false)
            ->setHelp('PropertyId real de Beds24 asociado a este room/unit.');

        $beds24RoomId = IntegerField::new('beds24RoomId', 'Beds24 Room ID');

        $beds24UnitId = IntegerField::new('beds24UnitId', 'Beds24 Unit ID (opcional)')
            ->setRequired(false)
            ->setHelp('Solo si Beds24 usa Units físicas. En modo Room-based, dejar vacío.');

        $channelPropId = TextField::new('channelPropId', 'Channel Prop ID')
            ->setRequired(false)
            ->setHelp('ID de propiedad en canal externo (Booking Hotel ID, Airbnb Listing ID).');

        $nota = TextField::new('nota', 'Nota')->setRequired(false);

        $activo = BooleanField::new('activo', 'Activo');
        $esPrincipal = BooleanField::new('esPrincipal', 'Principal')
            ->setHelp('Solo puede existir una asignación PRINCIPAL por unidad.');

        // ----------------
        // Auditoría (colapsada + solo lectura)
        // ----------------
        $created = DateTimeField::new('created', 'Creado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setDisabled(true);

        $updated = DateTimeField::new('updated', 'Actualizado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setDisabled(true);

        // ===================== INDEX =====================
        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $id,
                $pmsUnidad,
                $beds24Config,
                $esPrincipal,
                $beds24PropertyId,
                $beds24RoomId,
                $beds24UnitId,
                $channelPropId,
                $activo,
                $nota,
            ];
        }

        // ===================== DETAIL =====================
        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                FormField::addPanel('Relación')->setIcon('fa fa-link'),
                $beds24Config,
                $pmsUnidad,
                $beds24PropertyId,
                $beds24RoomId,
                $beds24UnitId,
                $channelPropId,
                $nota,

                FormField::addPanel('Flags')->setIcon('fa fa-flag'),
                $activo,
                $esPrincipal,

                FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
                $created,
                $updated,
            ];
        }

        // ===================== NEW / EDIT =====================
        // Nota: En EDIT la sección Proceso suele ser inútil/vacía (y además es read-only),
        // así que aquí no la mostramos.
        return [
            FormField::addPanel('Relación')->setIcon('fa fa-link'),
            $beds24Config,
            $pmsUnidad,
            $beds24PropertyId,
            $beds24RoomId,
            $beds24UnitId,
            $channelPropId,
            $nota,

            FormField::addPanel('Flags')->setIcon('fa fa-flag'),
            $activo,
            $esPrincipal,

            FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            // En NEW no existen timestamps todavía
            $isNew ? FormField::addPanel('')->onlyOnForms() : $created,
            $isNew ? FormField::addPanel('')->onlyOnForms() : $updated,
        ];
    }
}