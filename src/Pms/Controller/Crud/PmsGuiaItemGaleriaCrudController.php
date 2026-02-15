<?php
declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Field\LiipImageField;
use App\Panel\Form\Type\TranslationTextType;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaItemGaleria;
use App\Pms\Repository\PmsGuiaItemGaleriaRepository;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
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

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Foto')
            ->setEntityLabelInPlural('Galería de Fotos')
            ->setDefaultSort(['item' => 'ASC', 'orden' => 'ASC'])
            ->setPaginatorPageSize(50)
            ->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('item', 'Filtrar por Item'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $uploadAction = Action::new('massUpload', 'Carga Masiva')
            ->linkToCrudAction('renderMassUpload')
            ->createAsGlobalAction()
            ->setIcon('fa-solid fa-cloud-arrow-up')
            ->setCssClass('btn btn-primary action-mass-upload');

        $moveUp = Action::new('moveUp', false, 'fa fa-arrow-up')
            ->linkToCrudAction('moveUp')
            ->setHtmlAttributes(['title' => 'Mover Arriba'])
            ->displayAsLink();

        $moveDown = Action::new('moveDown', false, 'fa fa-arrow-down')
            ->linkToCrudAction('moveDown')
            ->setHtmlAttributes(['title' => 'Mover Abajo'])
            ->displayAsLink();

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $uploadAction)
            ->add(Crud::PAGE_INDEX, $moveUp)
            ->add(Crud::PAGE_INDEX, $moveDown)
            ->reorder(Crud::PAGE_INDEX, ['moveUp', 'moveDown', Action::EDIT, Action::DELETE])
            ->setPermission('massUpload', Roles::RESERVAS_WRITE)
            ->setPermission('moveUp', Roles::RESERVAS_WRITE)
            ->setPermission('moveDown', Roles::RESERVAS_WRITE);
    }

    public function moveUp(AdminContext $context, EntityManagerInterface $em): Response
    {
        return $this->movePosition($context, $em, 'up');
    }

    public function moveDown(AdminContext $context, EntityManagerInterface $em): Response
    {
        return $this->movePosition($context, $em, 'down');
    }

    private function movePosition(AdminContext $context, EntityManagerInterface $em, string $direction): Response
    {
        /** @var PmsGuiaItemGaleria $entity */
        $entity = $context->getEntity()->getInstance();

        // ✅ importante: aseguramos estado real desde BD antes de mover
        $em->refresh($entity);

        /** @var PmsGuiaItemGaleriaRepository $repo */
        $repo = $em->getRepository(PmsGuiaItemGaleria::class);

        if ($direction === 'up') {
            $repo->moveUp($entity, 1);
        } else {
            $repo->moveDown($entity, 1);
        }

        $em->flush();

        $this->addFlash('success', 'Orden actualizado.');

        return $this->redirect(
            $context->getReferrer()
            ?? $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl()
        );
    }

    public function renderMassUpload(EntityManagerInterface $em): Response
    {
        $items = $em->getRepository(PmsGuiaItem::class)->findBy([], ['nombreInterno' => 'ASC']);

        return $this->render('panel/pms/pms_guia_item_galeria/mass_upload.html.twig', [
            'items' => $items,
            'crud' => $this->configureCrud(Crud::new()),
        ]);
    }

    public function configureFields(string $pageName): iterable
    {
        $pathRelativo = $this->params->get('pms.path.galeria_images');
        $basePath = '/' . ltrim($pathRelativo, '/');

        if (!$this->isEmbedded()) {
            yield AssociationField::new('item', 'Item')->setSortable(true);
        }

        yield LiipImageField::new('imageUrl', 'Vista Previa')
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(function ($value, $entity) {
                if ($entity instanceof PmsGuiaItemGaleria && method_exists($entity, 'isImage') && !$entity->isImage($entity->getImageName())) {
                    return $entity->getIconPathFor($entity->getImageName());
                }
                return $value;
            });

        yield IntegerField::new('orden', 'Orden')
            ->onlyOnIndex()
            ->setSortable(true);

        yield TextField::new('imageFile', 'Archivo / Imagen')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setColumns(12);

        yield BooleanField::new('ejecutarTraduccion', 'Ejecutar Traducción')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir')->onlyOnForms()->setColumns(6);

        yield CollectionField::new('descripcion', 'Pie de Foto')
            ->setEntryType(TranslationTextType::class)
            ->showEntryLabel(false)
            ->renderExpanded(true)
            ->setColumns(12)
            ->hideOnIndex()
            ->formatValue(function ($value) {
                if (empty($value) || !is_array($value)) return '';
                foreach ($value as $item) {
                    if (isset($item['language']) && $item['language'] === 'es') return $item['content'] ?? '';
                }
                return (string) (reset($value)['content'] ?? '');
            });

        yield ImageField::new('imageName', 'Archivo')
            ->setBasePath($basePath)
            ->onlyOnDetail();

        yield IntegerField::new('orden', 'Orden')
            ->setFormTypeOption('disabled', true)
            ->onlyOnForms()
            ->setColumns(2);
    }
}