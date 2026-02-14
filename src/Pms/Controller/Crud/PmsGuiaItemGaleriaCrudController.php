<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Field\LiipImageField;
use App\Panel\Form\Type\TranslationTextType; // ✅ Importamos tu tipo de traducción
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaItemGaleria;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField; // ✅ Necesario para la colección
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Vich\UploaderBundle\Form\Type\VichImageType;

class PmsGuiaItemGaleriaCrudController extends BaseCrudController
{

    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private ParameterBagInterface $params
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsGuiaItemGaleria::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $uploadAction = Action::new('massUpload', 'Carga Masiva')
            ->linkToCrudAction('renderMassUpload') // Apunta al método de abajo
            ->createAsGlobalAction() // Botón arriba a la derecha (no por fila)
            ->setIcon('fa-solid fa-cloud-arrow-up')
            ->setCssClass('btn btn-primary action-mass-upload');

        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $uploadAction);;

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE)
            ->setPermission('massUpload', Roles::RESERVAS_WRITE);
    }

    public function renderMassUpload(EntityManagerInterface $em): Response
    {
        // Obtenemos todos los Items para llenar el <select> del template
        // Ordenados por nombre interno para facilitar la búsqueda
        $items = $em->getRepository(PmsGuiaItem::class)->findBy([], ['nombreInterno' => 'ASC']);

        return $this->render('panel/pms/pms_guia_item_galeria/mass_upload.html.twig', [
            'items' => $items,
            // Pasamos la configuración del CRUD actual para mantener el menú lateral activo
            'crud' => $this->configureCrud(Crud::new()),
        ]);
    }

    public function configureFields(string $pageName): iterable
    {
        // 1. Resolver rutas para el ImageField nativo
        $pathRelativo = $this->params->get('pms.path.galeria_images');
        $basePath = '/' . ltrim($pathRelativo, '/');
        $uploadDir = $this->params->get('app.public_dir') . '/' . ltrim($pathRelativo, '/');

        if(!$this->isEmbedded()) {
            yield AssociationField::new('item', 'Item');
        }

        // --- COLUMNA 1: VISTA PREVIA (Index) ---
        yield LiipImageField::new('imageUrl', 'Vista Previa')
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(function ($value, $entity) {
                if ($entity instanceof PmsGuiaItemGaleria && method_exists($entity, 'isImage') && !$entity->isImage($entity->getImageName())) {
                    return $entity->getIconPathFor($entity->getImageName());
                }
                return $value;
            });

        // --- COLUMNA 2: SUBIDA DE ARCHIVO ---
        yield TextField::new('imageFile', 'Archivo / Imagen')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setHelp('Soporta imágenes (JPG, PNG, WEBP). Máx 5MB.')
            ->setColumns(12);

        yield BooleanField::new('ejecutarTraduccion', 'Ejecutar Traducción')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setColumns(6);

        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir existentes')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE)
            ->setHelp('Si se activa, borrará las traducciones manuales y regenerará todo.')
            ->setColumns(6);

        // --- COLUMNA 3: DESCRIPCIÓN MULTI-IDIOMA (✅ NUEVO) ---
        yield CollectionField::new('descripcion', 'Pie de Foto / Descripción')
            ->setEntryType(TranslationTextType::class) // Usamos tu tipo personalizado
            ->setEntryIsComplex(true)
            ->showEntryLabel(false)
            ->renderExpanded(true)
            ->setColumns(12)
            ->addCssClass('field-full-width')
            // Formateador para que en el LISTADO se vea el texto en español y no "Array"
            ->formatValue(function ($value) {
                if (empty($value) || !is_array($value)) return '';
                foreach ($value as $item) {
                    if (isset($item['language']) && $item['language'] === 'es') return $item['content'] ?? '';
                }
                return reset($value)['content'] ?? ''; // Fallback al primero que encuentre
            });

        // --- COLUMNA 4: DETALLES TÉCNICOS ---
        yield ImageField::new('imageName', 'Archivo Original')
            ->setBasePath($basePath)
            ->setUploadDir($uploadDir)
            ->onlyOnDetail();

        yield IntegerField::new('orden', 'Orden')
            ->setColumns(2);
    }
}