<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoImagen;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Vich\UploaderBundle\Form\Type\VichImageType;

class TravelSegmentoImagenCrudController extends BaseCrudController
{
    public function __construct(
        #[Autowire('%travel.path.segmento_imagenes%')]
        private readonly string $uploadPath,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($this->adminUrlGenerator, $this->requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return TravelSegmentoImagen::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Imagen')
            ->setEntityLabelInPlural('Imágenes de segmento')
            ->setDefaultSort(['segmento' => 'ASC', 'orden' => 'ASC']);
    }

    /**
     * 🔥 NUEVO: Filtro por segmento
     */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('segmento', 'Segmento'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $uploadAction = Action::new('massUpload', 'Carga Masiva')
            ->linkToCrudAction('renderMassUpload')
            ->createAsGlobalAction()
            ->setIcon('fa-solid fa-cloud-arrow-up')
            ->setCssClass('btn btn-primary action-mass-upload');

        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $uploadAction);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_WRITE)
            ->setPermission('massUpload', Roles::MAESTROS_WRITE);
    }

    public function renderMassUpload(EntityManagerInterface $em): Response
    {
        $segmentos = $em->getRepository(TravelSegmento::class)->findBy([], ['nombreInterno' => 'ASC']);

        return $this->render('panel/travel/travel_segmento_imagen/mass_upload.html.twig', [
            'segmentos' => $segmentos,
            'crud' => $this->configureCrud(Crud::new()),
        ]);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('segmento', 'Segmento')
            ->autocomplete()
            ->setColumns(12)
            ->setHelp('Segmento narrativo al que pertenece esta imagen.');

        yield TextField::new('imageFile', 'Subir Imagen')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setColumns(12);

        yield ImageField::new('imageName', 'Previsualización')
            ->setBasePath($this->uploadPath)
            ->onlyOnIndex();

        // 🔥 NUEVO: nombre y título del segmento padre
        yield TextField::new('virtualSegmentoNombre', 'Segmento (ID)')
            ->onlyOnIndex();

        yield TextField::new('virtualSegmentoTituloEs', 'Título Segmento (ES)')
            ->hideOnForm();

        yield IntegerField::new('orden', 'Orden')
            ->setHelp('Determina la posición de la imagen. Un número menor indica mayor prioridad (ej: 0 es el primero).')
            ->setColumns(6);

        yield BooleanField::new('isPortada', 'Es Portada')
            ->setHelp('Marca esta imagen como la imagen principal o de cabecera del segmento.')
            ->setColumns(6);
    }
}