<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoImagen;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Vich\UploaderBundle\Form\Type\VichImageType;

class TravelSegmentoImagenCrudController extends BaseCrudController
{
    /**
     * Constructor para inyectar la ruta de subida de imágenes para la previsualización del CRUD.
     *
     * @param string $uploadPath Ruta base inyectada vía Autowire.
     * @param AdminUrlGenerator $adminUrlGenerator Generador de URLs para EasyAdmin.
     * @param RequestStack $requestStack Pila de peticiones.
     */
    public function __construct(
        #[Autowire('%travel.path.segmento_imagenes%')]
        private readonly string $uploadPath,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($this->adminUrlGenerator, $this->requestStack);
    }

    /**
     * Define la entidad administrada por este controlador.
     *
     * @return string
     */
    public static function getEntityFqcn(): string
    {
        return TravelSegmentoImagen::class;
    }

    /**
     * Configuración general del comportamiento del CRUD.
     *
     * @param Crud $crud
     * @return Crud
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Imagen')
            ->setEntityLabelInPlural('Imágenes de segmento')
            ->setDefaultSort(['segmento' => 'ASC', 'orden' => 'ASC']);
    }

    /**
     * Configuración de acciones y botones globales del CRUD.
     *
     * @param Actions $actions
     * @return Actions
     */
    public function configureActions(Actions $actions): Actions
    {
        $uploadAction = Action::new('massUpload', 'Carga Masiva')
            ->linkToCrudAction('renderMassUpload')
            ->createAsGlobalAction()
            ->setIcon('fa-solid fa-cloud-arrow-up')
            ->setCssClass('btn btn-primary action-mass-upload');

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $uploadAction);
    }

    /**
     * Acción personalizada para renderizar la vista de carga masiva.
     * Obtiene los segmentos disponibles y renderiza la plantilla Twig.
     *
     * @param EntityManagerInterface $em
     * @return Response
     */
    public function renderMassUpload(EntityManagerInterface $em): Response
    {
        // Obtenemos los segmentos ordenados para el selector de TomSelect.
        // Asumiendo que TravelSegmento tiene un campo 'nombre' o similar. Ajústalo si tu campo se llama distinto.
        $segmentos = $em->getRepository(TravelSegmento::class)->findBy([], ['nombreInterno' => 'ASC']);

        return $this->render('panel/travel/travel_segmento_imagen/mass_upload.html.twig', [
            'segmentos' => $segmentos,
            'crud' => $this->configureCrud(Crud::new()),
        ]);
    }

    /**
     * Configuración de los campos visibles en el panel.
     *
     * @param string $pageName
     * @return iterable
     */
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('imageFile', 'Subir Imagen')
            ->setFormType(VichImageType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield ImageField::new('imageName', 'Previsualización')
            ->setBasePath($this->uploadPath) // Usando la variable inyectada
            ->onlyOnIndex();

        yield IntegerField::new('orden', 'Orden')
            ->setHelp('Determina la posición de la imagen. Un número menor indica mayor prioridad (ej: 0 es el primero).')
            ->setColumns(6);

        yield BooleanField::new('isPortada', 'Es Portada')
            ->setHelp('Marca esta imagen como la imagen principal o de cabecera del segmento.')
            ->setColumns(6);
    }
}