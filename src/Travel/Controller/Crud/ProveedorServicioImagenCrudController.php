<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use App\Travel\Entity\ProveedorServicio;
use App\Travel\Entity\ProveedorServicioImagen;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Vich\UploaderBundle\Form\Type\VichImageType;

class ProveedorServicioImagenCrudController extends BaseCrudController
{
    /**
     * Constructor del controlador CRUD para las imágenes de los servicios.
     * Inyecta dependencias críticas como el path físico de las imágenes, el generador de URLs y la pila de peticiones.
     *
     * @param string $uploadPath Ruta base inyectada vía Autowire desde services.yaml (%travel.path.proveedor_servicio_galeria%).
     * @param AdminUrlGenerator $adminUrlGenerator Generador de URLs para redirecciones y acciones internas de EasyAdmin.
     * @param RequestStack $requestStack Pila de peticiones HTTP en curso.
     */
    public function __construct(
        #[Autowire('%travel.path.proveedor_servicio_galeria%')]
        private readonly string $uploadPath,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($this->adminUrlGenerator, $this->requestStack);
    }

    /**
     * Define la entidad administrada por este controlador.
     * Este método es requerido por EasyAdmin para saber qué entidad mapear en las vistas y formularios.
     *
     * @return string Retorna el FQCN (Fully Qualified Class Name) de ProveedorServicioImagen.
     */
    public static function getEntityFqcn(): string
    {
        return ProveedorServicioImagen::class;
    }

    /**
     * Configuración general del comportamiento del CRUD para la galería de los servicios.
     * Define etiquetas, ordenamiento por defecto basado en la jerarquía del servicio y orden numérico, y presentación visual.
     *
     * @param Crud $crud Objeto de configuración inicial provisto por EasyAdmin.
     * @return Crud
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Imagen de Servicio')
            ->setEntityLabelInPlural('Galería de Servicios')
            ->setDefaultSort(['proveedorServicio' => 'ASC', 'orden' => 'ASC']);
    }

    /**
     * Configuración de acciones, botones globales y permisos de acceso del CRUD.
     * Registra la acción personalizada de carga masiva y aplica los controles de acceso basados en Roles del sistema.
     *
     * @param Actions $actions Objeto de gestión de acciones de EasyAdmin.
     * @return Actions
     */
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

    /**
     * Acción personalizada para renderizar la vista de carga masiva de imágenes para servicios.
     * Obtiene todos los servicios disponibles para alimentar el select dinámico en el frontend (ej. TomSelect).
     * Es crucial para permitir a los administradores subir múltiples fotos asociadas a una habitación en un solo paso.
     *
     * @param EntityManagerInterface $em Gestor de entidades de Doctrine para consultar los servicios activos.
     * @return Response Retorna la vista renderizada del componente de carga masiva.
     */
    public function renderMassUpload(EntityManagerInterface $em): Response
    {
        // Obtenemos los servicios de los proveedores ordenados alfabéticamente para facilitar la búsqueda.
        $proveedorServicios = $em->getRepository(ProveedorServicio::class)->findBy([], ['nombre' => 'ASC']);

        return $this->render('panel/travel/proveedor_servicio_imagen/mass_upload.html.twig', [
            'proveedorServicios' => $proveedorServicios,
            'crud' => $this->configureCrud(Crud::new()),
        ]);
    }

    /**
     * Configuración de los campos visibles en los formularios y listas del panel.
     * Gestiona el campo de carga con VichUploader y la previsualización usando el path inyectado en el constructor.
     *
     * @param string $pageName Identificador de la página actual (Index, Edit, New, Detail).
     * @return iterable Colección de campos configurados de EasyAdmin.
     */
    public function configureFields(string $pageName): iterable
    {
        $isEmbedded = $this->isEmbedded();

        if (!$isEmbedded){
            yield AssociationField::new('proveedorServicio', 'Servicio del Proveedor')
                ->autocomplete()
                ->setColumns(12)
                ->setHelp('Servicio del proveedor al que pertenece la imágen.');
        }

        yield TextField::new('imageFile', 'Subir Imagen')
            ->setFormType(VichImageType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield ImageField::new('imageName', 'Previsualización')
            ->setBasePath($this->uploadPath) // Usando la variable inyectada dinámicamente
            ->onlyOnIndex();

        yield IntegerField::new('orden', 'Orden')
            ->setHelp('Determina la posición de la imagen dentro de la galería del servicio. Un número menor indica mayor prioridad (ej: 0 es el primero).')
            ->setColumns(6);

        yield BooleanField::new('isPortada', 'Es Portada')
            ->setHelp('Marca esta imagen como la principal o de cabecera para el servicio/habitación.')
            ->setColumns(6);
    }
}