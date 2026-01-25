<?php

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsChannel;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

final class PmsChannelCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsChannel::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)   // botón "Ver" en el listado
            ->add(Crud::PAGE_EDIT, Action::DETAIL);   // opcional: link a "Ver" desde edit

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Canal')
            ->setEntityLabelInPlural('Canales')
            ->setDefaultSort(['codigo' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('codigo')
            ->add('nombre')
            ->add('esExterno')
            ->add('esDirecto');
    }

    public function configureFields(string $pageName): iterable
    {
        $id = IdField::new('id')->onlyOnDetail();

        $codigo = TextField::new('codigo', 'Código')
            ->setHelp('Slug interno del canal (booking, airbnb, directo, etc.)');

        $nombre = TextField::new('nombre', 'Nombre');

        $beds24ChannelId = TextField::new('beds24ChannelId', 'Beds24 Channel ID')
            ->setHelp('ID real del canal en Beds24 (si aplica)')
            ->setRequired(false);

        $esExterno = BooleanField::new('esExterno', 'Externo');
        $esDirecto = BooleanField::new('esDirecto', 'Directo');

        $color = TextField::new('color', 'Color')
            ->setHelp('Color HEX para UI (#RRGGBB)')
            ->setRequired(false);

        $created = DateTimeField::new('created', 'Creado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        $updated = DateTimeField::new('updated', 'Actualizado')
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $codigo,
                $nombre,
                $beds24ChannelId,
                $esExterno,
                $esDirecto,
                $color,
            ];
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                $id,
                $codigo,
                $nombre,
                $beds24ChannelId,
                $esExterno,
                $esDirecto,
                $color,

                FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
                $created,
                $updated,
            ];
        }

        // NEW / EDIT
        return [
            FormField::addPanel('Datos del canal')->setIcon('fa fa-plug'),
            $codigo,
            $nombre,

            FormField::addPanel('Integración')->setIcon('fa fa-cloud'),
            $beds24ChannelId,
            $esExterno,
            $esDirecto,

            FormField::addPanel('UI')->setIcon('fa fa-palette'),
            $color,

            FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed(),
            $created->onlyWhenUpdating(),
            $updated->onlyWhenUpdating(),
        ];
    }
}