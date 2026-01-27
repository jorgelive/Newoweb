<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Field\LiipImageField; // <--- Tu nuevo campo personalizado
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
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Vich\UploaderBundle\Form\Type\VichImageType;

class PmsReservaHuespedCrudController extends BaseCrudController
{
    // Constructor limpio: Ya no necesitamos CacheManager aquí
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
        // ---------------------------------------------------------------------
        // 1. CABECERA Y VÍNCULOS
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Vínculo de Reserva')->setIcon('fa fa-link');

        yield AssociationField::new('reserva', 'Reserva Padre')
            ->setQueryBuilder(fn($queryBuilder) => $queryBuilder->orderBy('entity.created', 'DESC'))
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
        // 1. FORMULARIO: Subida con Vich
        yield TextField::new('documentoFile', 'Subir DNI/Pasaporte')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setColumns(6);

        // 2. INDEX: Miniatura Liip + Lightbox (Usando tu nuevo campo)
        yield LiipImageField::new('documentoUrl', 'DNI / Pasaporte')
            ->onlyOnIndex() // Solo en el listado
            ->setSortable(false)
            ->setColumns(6);

        // 3. DETAIL: Imagen grande estándar
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
            ->setSortable(false)
            ->setColumns(6);

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
            ->setSortable(false)
            ->setColumns(6);

        yield ImageField::new('firmaUrl', 'Firma Huésped')
            ->setBasePath('')
            ->onlyOnDetail()
            ->setColumns(6);

        yield DateTimeField::new('firmadoEn', 'Fecha Firma')
            ->onlyOnDetail();

        // ---------------------------------------------------------------------
        // 5. AUDITORÍA
        // ---------------------------------------------------------------------
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->renderCollapsed();

        yield DateTimeField::new('creado')->hideOnForm();
        yield DateTimeField::new('modificado')->hideOnForm();
    }
}