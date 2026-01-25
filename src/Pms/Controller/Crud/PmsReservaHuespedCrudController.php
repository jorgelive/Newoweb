<?php

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsReservaHuesped;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Vich\UploaderBundle\Form\Type\VichImageType;

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
            // Aquí 'creado' está bien porque se refiere a PmsReservaHuesped
            ->setDefaultSort(['creado' => 'DESC'])
            ->setFormOptions(['attr' => ['enctype' => 'multipart/form-data']]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('pais')
            ->add('tipoDocumento')
            ->add('esPrincipal')
            ->add('fechaNacimiento');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        return parent::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        // Rutas base definidas en vich_uploader.yaml
        $pathDocs = 'uploads/carga/pms/pms_reserva_huesped/documento';
        $pathFirmas = 'uploads/carga/pms/pms_reserva_huesped/firmas';

        // 1. Cabecera y Relación
        yield FormField::addPanel('Vínculo de Reserva')->setIcon('fa fa-link');

        yield AssociationField::new('reserva', 'Reserva Padre')
            // CORRECCIÓN AQUÍ: Usamos 'entity.created' porque PmsReserva usa inglés
            ->setQueryBuilder(fn($queryBuilder) => $queryBuilder->orderBy('entity.created', 'DESC'))
            ->setColumns(6);

        yield BooleanField::new('esPrincipal', 'Titular de Reserva')->setColumns(6);

        // 2. Datos Personales
        yield FormField::addPanel('Datos Personales')->setIcon('fa fa-id-card');
        yield TextField::new('nombre')->setColumns(6);
        yield TextField::new('apellido')->setColumns(6);
        yield DateField::new('fechaNacimiento', 'F. Nacimiento')->setColumns(4);
        yield AssociationField::new('pais', 'Nacionalidad')->setColumns(4);
        yield AssociationField::new('tipoDocumento', 'Tipo Doc.')->setColumns(4);
        yield TextField::new('documentoNumero', 'Número Documento')->setColumns(4);

        // 3. Documentación Digital
        yield FormField::addPanel('Documentos Digitalizados')->setIcon('fa fa-camera');

        // --- A. DOCUMENTO DE IDENTIDAD ---
        yield TextField::new('documentoFile', 'Subir DNI/Pasaporte')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setColumns(6);

        yield TextField::new('documentoName', 'DNI / Pasaporte')
            ->setTemplatePath('panel/field/media.html.twig')
            ->setCustomOption('base_path', $pathDocs)
            ->hideOnForm()
            ->setColumns(6);

        // --- B. TARJETA TAM ---
        yield TextField::new('tamFile', 'Subir TAM')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setColumns(6);

        yield TextField::new('tamName', 'TAM (Migraciones)')
            ->setTemplatePath('panel/field/media.html.twig')
            ->setCustomOption('base_path', $pathDocs)
            ->hideOnForm()
            ->setColumns(6);

        // --- C. FIRMA DIGITAL ---
        yield FormField::addPanel('Conformidad Legal')->setIcon('fa fa-file-signature');

        yield TextField::new('firmaFile', 'Subir Firma')
            ->setFormType(VichImageType::class)
            ->onlyOnForms()
            ->setColumns(6);

        yield TextField::new('firmaName', 'Firma Huésped')
            ->setTemplatePath('panel/field/media.html.twig')
            ->setCustomOption('base_path', $pathFirmas)
            ->hideOnForm()
            ->setColumns(6);

        yield DateTimeField::new('firmadoEn', 'Fecha Firma')
            ->onlyOnDetail();

        // 4. Auditoría
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->renderCollapsed();

        // Aquí 'creado' está bien porque se refiere a la entidad actual (Huésped)
        yield DateTimeField::new('creado')->hideOnForm();
        yield DateTimeField::new('modificado')->hideOnForm();
    }
}