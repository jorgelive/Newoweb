<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsChannel;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField; // ✅ Importante
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsChannelCrudController.
 * Gestión de Canales de Venta (Airbnb, Booking, Directo).
 * El ID es natural (String) y hereda de BaseCrudController.
 */
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

    /**
     * Configuración de Acciones y Permisos.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Canal')
            ->setEntityLabelInPlural('Canales')
            // ✅ ORDENAMIENTO POR DEFECTO: Prioridad > Nombre
            ->setDefaultSort(['orden' => 'ASC', 'nombre' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('id')
            ->add('nombre')
            ->add('esExterno')
            ->add('esDirecto');
    }

    public function configureFields(string $pageName): iterable
    {
        // ✅ El ID es natural (Slug). Se permite entrada manual solo al crear.
        $id = TextField::new('id', 'Código (ID)')
            ->setHelp('Slug interno (ej: booking, airbnb, directo).')
            ->setFormTypeOption('attr', ['placeholder' => 'airbnb']);

        // Lógica de visualización del ID:
        if (Crud::PAGE_NEW === $pageName) {
            $id->setRequired(true);
        } elseif (Crud::PAGE_EDIT === $pageName) {
            // En edición se BLOQUEA (no se debe cambiar la PK)
            $id->setFormTypeOption('disabled', true);
        }

        $nombre = TextField::new('nombre', 'Nombre Comercial')->setColumns(6);

        // ✅ NUEVO CAMPO ORDEN
        $orden = IntegerField::new('orden', 'Prioridad Visual')
            ->setHelp('0 sale primero, números altos salen al final.')
            ->setColumns(6);

        $beds24ChannelId = TextField::new('beds24ChannelId', 'Beds24 Channel ID')
            ->setHelp('ID técnico del canal en la API v2 de Beds24.')
            ->setRequired(false);

        $esExterno = BooleanField::new('esExterno', 'Es Externo (OTA)')
            ->renderAsSwitch(true);

        $esDirecto = BooleanField::new('esDirecto', 'Es Venta Directa')
            ->renderAsSwitch(true);

        $color = TextField::new('color', 'Color Identificador')
            ->setHelp('Formato HEX: #RRGGBB')
            ->setRequired(false);

        // ✅ Auditoría mediante TimestampTrait (createdAt / updatedAt)
        $createdAt = DateTimeField::new('createdAt', 'Registrado')
            ->setFormat('yyyy/MM/dd HH:mm');

        $updatedAt = DateTimeField::new('updatedAt', 'Última Modificación')
            ->setFormat('yyyy/MM/dd HH:mm');

        if (Crud::PAGE_INDEX === $pageName) {
            return [
                $orden, // Ver el orden en la lista ayuda mucho
                $id,
                $nombre,
                $esExterno,
                $esDirecto,
                $color,
            ];
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            return [
                FormField::addPanel('Detalle del Canal')->setIcon('fa fa-plug'),
                $id,
                $nombre,
                $orden,
                $beds24ChannelId,
                $esExterno,
                $esDirecto,
                $color,

                FormField::addPanel('Auditoría Técnica')->setIcon('fa fa-shield-alt')->renderCollapsed(),
                $createdAt->onlyOnDetail(),
                $updatedAt->onlyOnDetail(),
            ];
        }

        // NEW / EDIT
        return [
            FormField::addPanel('Configuración Básica')->setIcon('fa fa-plug'),
            $id,
            $nombre,
            $orden,

            FormField::addPanel('Integración con Beds24')->setIcon('fa fa-cloud'),
            $beds24ChannelId,
            $esExterno,
            $esDirecto,

            FormField::addPanel('Interfaz de Usuario (UI)')->setIcon('fa fa-palette'),
            $color,

            FormField::addPanel('Tiempos')->setIcon('fa fa-clock')->renderCollapsed(),
            $createdAt->onlyOnForms()->setFormTypeOption('disabled', true),
            $updatedAt->onlyOnForms()->setFormTypeOption('disabled', true),
        ];
    }
}