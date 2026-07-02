<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelServicio;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class TravelServicioCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelServicio::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Servicio')
            ->setEntityLabelInPlural('Servicios');
    }

    public function configureActions(Actions $actions): Actions
    {
        $cloneAction = Action::new('cloneAction', 'Clonar', 'fa fa-copy')
            ->linkToCrudAction('cloneServicio')
            ->setCssClass('btn btn-info')
            ->setHtmlAttributes([
                'data-controller' => 'panel--confirm',
                'data-action' => 'click->panel--confirm#ask',
                'data-panel--confirm-title-value' => '¿Clonar servicio?',
                'data-panel--confirm-text-value' => 'Se duplicará este servicio y todos sus componentes logísticos internos. Podrás editarlo a continuación.',
                'data-panel--confirm-icon-value' => 'question',
                'data-panel--confirm-confirm-button-text-value' => 'Sí, clonar',
                'data-panel--confirm-confirm-color-value' => '#0ea5e9'
            ]);

        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $cloneAction)
            ->add(Crud::PAGE_DETAIL, $cloneAction)
            ->add(Crud::PAGE_EDIT, $cloneAction);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_WRITE)
            ->setPermission('cloneAction', Roles::MAESTROS_WRITE);
    }

    public function cloneServicio(
        AdminContext $context,
        EntityManagerInterface $em,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        /** @var TravelServicio $original */
        $original = $context->getEntity()->getInstance();

        $clon = clone $original;
        $em->persist($clon);
        $em->flush();

        $this->addFlash('success', 'Servicio y sus componentes logísticos clonados exitosamente.');

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($clon->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Datos Generales')->setIcon('fa fa-info-circle');

        yield TextField::new('codigo', 'Código (SKU)')->setColumns(4)->setHelp('Ej: VINI-1D');
        yield TextField::new('nombreInterno', 'Nombre Operativo')->setColumns(8);

        yield FormField::addPanel('Contenido Comercial')->setIcon('fa fa-bullhorn');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        // 🔥 LECTURA (Getter Virtual)
        yield TextField::new('virtualTitulo', 'Título de Venta')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                if (is_iterable($entity->getTitulo())) {
                    foreach ($entity->getTitulo() as $item) {
                        if (isset($item['language'], $item['content']) && $item['language'] === 'es') {
                            return sprintf('<span class="fw-bold">%s</span>', htmlspecialchars(strip_tags($item['content'])));
                        }
                    }
                }
                return '<span class="text-muted small"><i class="fas fa-language"></i> Sin título en español</span>';
            })
            ->renderAsHtml();

        // 🔥 ESCRITURA (Campo Real)
        yield CollectionField::new('titulo', 'Título de Venta')
            ->setEntryType(TranslationTextType::class)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);

        yield FormField::addPanel('Pool Logístico (La Bolsa)')->setIcon('fa fa-cubes');

        // 🔥 LECTURA (Getter Virtual)
        yield TextField::new('virtualComponentes', 'Componentes Disponibles')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                $componentes = $entity->getComponentes();
                if ($componentes->isEmpty()) return '<span class="text-muted small"><i class="fas fa-info-circle"></i> Sin componentes vinculados</span>';

                $html = '<ul style="max-height: 180px; overflow-y: auto; text-align: left; min-width: 240px; margin: 0; padding: 0 5px 0 0; list-style: none;">';
                foreach ($componentes as $componente) {
                    $nombre = htmlspecialchars((string) $componente);
                    $html .= sprintf('<li class="px-2 py-1 mb-1 bg-white border rounded small text-truncate" title="%s" style="display: block;"><i class="fas fa-check-circle text-success" style="font-size: 0.8em; margin-right: 4px;"></i> <span class="text-dark fw-medium">%s</span></li>', $nombre, $nombre);
                }
                $html .= '</ul>';
                return $html;
            })
            ->renderAsHtml();

        // 🔥 ESCRITURA (Campo Real)
        yield AssociationField::new('componentes', 'Componentes Disponibles')
            ->setFormTypeOptions(['by_reference' => false, 'multiple' => true])
            ->setHelp('Añade aquí todos los componentes que este tour podría utilizar.')
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);
    }
}