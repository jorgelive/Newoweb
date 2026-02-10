<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Field\LiipImageField;
use App\Pms\Entity\PmsReservaHuesped;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * PmsReservaHuespedCrudController.
 * Gestión de pasajeros y documentación digital (DNI, TAM, Firmas).
 * Implementa UUID v7 y herencia de BaseCrudController con permisos prioritarios.
 */
class PmsReservaHuespedCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsReservaHuesped::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Huésped')
            ->setEntityLabelInPlural('Namelist / Huéspedes')
            ->setSearchFields(['nombre', 'apellido', 'documentoNumero'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setFormOptions(['attr' => ['enctype' => 'multipart/form-data']])
            ->showEntityActionsInlined();
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
            ->add('pais')
            ->add('tipoDocumento')
            ->add('esPrincipal')
            ->add('fechaNacimiento');
    }

    public function configureFields(string $pageName): iterable
    {
        // ---------------------------------------------------------------------
        // 1. CABECERA Y VÍNCULOS
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Vínculo de Reserva')->setIcon('fa fa-link');

        // ✅ UUID para visualización técnica
        yield TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        yield TextField::new('localizador', 'Localizador')
            ->setFormTypeOption('disabled', true) // ✅ IMPRESCINDIBLE: Solo lectura
            ->setColumns(6)
            // En el listado (Index) lo mostramos como una "etiqueta" negrita
            ->formatValue(function ($value) {
                return $value ? sprintf('<span class="badge badge-secondary" style="font-size: 1.1em; letter-spacing: 1px;">%s</span>', $value) : '';
            })
            // En el formulario mostramos ayuda
            ->setHelp('Código único autogenerado (Referencia Interna).');

        yield AssociationField::new('reserva', 'Reserva Padre')
            ->setQueryBuilder(fn($queryBuilder) => $queryBuilder->orderBy('entity.createdAt', 'DESC'))
            ->setColumns(6);

        yield BooleanField::new('esPrincipal', 'Titular de Reserva')->setColumns(6);

        // ---------------------------------------------------------------------
        // 2. DATOS PERSONALES
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Datos Personales')->setIcon('fa fa-id-card');

        yield TextField::new('nombre')->setColumns(6);
        yield TextField::new('apellido')->setColumns(6);
        yield DateField::new('fechaNacimiento', 'F. Nacimiento')->setColumns(4);
        yield AssociationField::new('pais', 'Nacionalidad')->setColumns(4);
        yield AssociationField::new('tipoDocumento', 'Tipo Doc.')->setColumns(4);
        yield TextField::new('documentoNumero', 'Número Documento')->setColumns(4);

        // ---------------------------------------------------------------------
        // 3. DOCUMENTACIÓN DIGITAL
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Documentos Digitalizados')->setIcon('fa fa-camera');

        // --- A. DOCUMENTO DE IDENTIDAD ---
        yield TextField::new('documentoFile', 'Subir DNI/Pasaporte')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setColumns(6);

        yield LiipImageField::new('documentoUrl', 'DNI / Pasaporte')
            ->onlyOnIndex()
            ->setSortable(false);

        yield ImageField::new('documentoUrl', 'DNI / Pasaporte')
            ->setBasePath('')
            ->onlyOnDetail()
            ->setColumns(6);

        // --- B. TARJETA TAM ---
        yield TextField::new('tamFile', 'Subir TAM')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setColumns(6);

        yield LiipImageField::new('tamUrl', 'TAM (Migraciones)')
            ->onlyOnIndex()
            ->setSortable(false);

        yield ImageField::new('tamUrl', 'TAM (Migraciones)')
            ->setBasePath('')
            ->onlyOnDetail()
            ->setColumns(6);

        // ---------------------------------------------------------------------
        // 4. CONFORMIDAD LEGAL (FIRMAS)
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Conformidad Legal')->setIcon('fa fa-file-signature');

        yield TextField::new('firmaFile', 'Subir Firma')
            ->setFormType(VichImageType::class)
            ->onlyOnForms()
            ->setColumns(6);

        yield LiipImageField::new('firmaUrl', 'Firma Huésped')
            ->onlyOnIndex()
            ->setSortable(false);

        yield ImageField::new('firmaUrl', 'Firma Huésped')
            ->setBasePath('')
            ->onlyOnDetail()
            ->setColumns(6);

        yield DateTimeField::new('firmadoEn', 'Fecha Firma')
            ->onlyOnDetail();

        // ---------------------------------------------------------------------
        // 5. AUDITORÍA (TimestampTrait)
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Modificado')->onlyOnDetail();
    }
}