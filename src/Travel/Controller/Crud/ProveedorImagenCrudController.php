<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use App\Travel\Entity\Proveedor;
use App\Travel\Entity\ProveedorImagen;
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

class ProveedorImagenCrudController extends BaseCrudController
{
    /**
     * Constructor para inyectar la ruta de subida de imágenes para la previsualización del CRUD.
     *
     * @param string $uploadPath Ruta base inyectada vía Autowire.
     * @param AdminUrlGenerator $adminUrlGenerator Generador de URLs para EasyAdmin.
     * @param RequestStack $requestStack Pila de peticiones.
     */
    public function __construct(
        #[Autowire('%travel.path.proveedor_galeria%')]
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
        return ProveedorImagen::class;
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
            ->setEntityLabelInSingular('Imagen de Proveedor')
            ->setEntityLabelInPlural('Galería de Proveedores')
            ->setDefaultSort(['proveedor' => 'ASC', 'orden' => 'ASC']);
    }

    /**
     * Configuración de acciones, botones globales y permisos de acceso del CRUD.
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
     * Acción personalizada para renderizar la vista de carga masiva.
     * Obtiene los proveedores disponibles para alimentar el select de TomSelect.
     *
     * @param EntityManagerInterface $em
     * @return Response
     */
    public function renderMassUpload(EntityManagerInterface $em): Response
    {
        // Obtenemos los proveedores.
        $proveedores = $em->getRepository(Proveedor::class)->findBy([], ['nombreComercial' => 'ASC']);

        return $this->render('panel/travel/proveedor_imagen/mass_upload.html.twig', [
            'proveedores' => $proveedores,
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
            ->setHelp('Marca esta imagen como la imagen principal o de cabecera del proveedor.')
            ->setColumns(6);
    }
}