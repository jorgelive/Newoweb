<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationHtmlType;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelSegmentoImagen;
use App\Security\Roles;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
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

class TravelSegmentoCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelSegmento::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Segmento')
            ->setEntityLabelInPlural('Segmentos de itinerario');
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        try {
            $entityManager->remove($entityInstance);
            $entityManager->flush();
        } catch (ForeignKeyConstraintViolationException $e) {
            $this->addFlash('danger', '⛔ <strong>Acción denegada:</strong> No puedes eliminar este segmento porque está siendo utilizado en uno o más Itinerarios.');
            $entityManager->refresh($entityInstance);
        }
    }

    public function configureActions(Actions $actions): Actions
    {
        $cloneAction = Action::new('cloneAction', 'Clonar', 'fa fa-copy')
            ->linkToCrudAction('cloneSegmento')
            ->setCssClass('btn btn-info')
            ->setHtmlAttributes([
                'data-controller' => 'panel--confirm',
                'data-action' => 'click->panel--confirm#ask',
                'data-panel--confirm-title-value' => '¿Clonar segmento?',
                'data-panel--confirm-text-value' => 'Se duplicará este segmento narrativo con todas sus notas y componentes logísticos.',
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

    public function cloneSegmento(
        AdminContext $context,
        EntityManagerInterface $em,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        /** @var TravelSegmento $original */
        $original = $context->getEntity()->getInstance();

        $clon = clone $original;
        $em->persist($clon);
        $em->flush();

        $this->addFlash('success', 'Segmento narrativo y su logística clonados exitosamente.');

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($clon->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Configuración de la Pieza')->setIcon('fa fa-puzzle-piece');

        // 🔥 LECTURA (Getter Virtual)
        yield TextField::new('virtualServicios', 'Servicios')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                $servicios = $entity->getServicios();
                if ($servicios->isEmpty()) return '<span class="text-muted small"><i class="fas fa-info-circle"></i> Sin servicios vinculados</span>';

                $html = '<ul style="max-height: 160px; overflow-y: auto; text-align: left; min-width: 240px; margin: 0; padding: 0 5px 0 0; list-style: none;">';
                foreach ($servicios as $servicio) {
                    $nombre = htmlspecialchars((string) $servicio);
                    $html .= sprintf('<li class="px-2 py-1 mb-1 bg-white border rounded small text-truncate" title="%s" style="display: block;"><i class="fas fa-layer-group text-primary" style="font-size: 0.8em; margin-right: 4px;"></i> <span class="text-dark fw-medium">%s</span></li>', $nombre, $nombre);
                }
                $html .= '</ul>';
                return $html;
            })
            ->renderAsHtml();

        // 🔥 ESCRITURA (Campo Real)
        yield AssociationField::new('servicios', 'Servicios')
            ->setFormTypeOptions(['by_reference' => false, 'multiple' => true])
            ->setHelp('Pools de Servicios (Contenedores) donde estará disponible.')
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(6);

        yield TextField::new('nombreInterno', 'Nombre Administrativo (ID)')->setColumns(6);
        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        yield FormField::addPanel('Contenido Narrativo')->setIcon('fa fa-pen-fancy');

        // 🔥 LECTURA (Getter Virtual)
        yield TextField::new('virtualTitulo', 'Título del Segmento')
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
        yield CollectionField::new('titulo', 'Título del Segmento')
            ->setEntryType(TranslationTextType::class)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);

        yield CollectionField::new('contenido', 'Cuerpo del Relato')
            ->setEntryType(TranslationHtmlType::class)
            ->setColumns(12);

        yield FormField::addPanel('Logística y Multimedia')->setIcon('fa fa-cogs');

        // 🔥 LECTURA (Getter Virtual ya existente)
        yield TextField::new('virtualLogistica', 'Logística Inyectada')
            ->onlyOnIndex()
            ->formatValue(function ($value, $entity) {
                $coleccion = $entity->getSegmentoComponentes();
                if ($coleccion->isEmpty()) return '<span class="badge bg-light text-muted border">Sin logística</span>';

                $html = '<div class="d-flex flex-column gap-1" style="font-size: 11px; min-width: 250px; max-height: 220px; overflow-y: auto; padding-right: 5px;">';
                foreach ($coleccion as $sc) {
                    $compName = $sc->getComponente() ? htmlspecialchars((string) $sc->getComponente()) : 'N/A';
                    $hora = $sc->getHora() ? $sc->getHora()->format('H:i') : 'Horario BD';
                    $ctx = $sc->getItinerarioContexto() ? htmlspecialchars($sc->getItinerarioContexto()->getNombreInterno()) : 'Global';
                    $colorCtx = $sc->getItinerarioContexto() ? 'text-primary' : 'text-success';
                    $iconCtx = $sc->getItinerarioContexto() ? 'fa-filter' : 'fa-globe';
                    $html .= sprintf('<div class="p-1 border rounded bg-white shadow-sm"><strong class="d-block text-truncate mb-1" style="max-width: 280px;" title="%s">%s</strong><span class="text-muted"><i class="far fa-clock"></i> %s</span> <span class="mx-1 text-muted">|</span> <span class="%s fw-bold" title="Contexto de Plantilla"><i class="fas %s"></i> %s</span></div>', $compName, $compName, $hora, $colorCtx, $iconCtx, $ctx);
                }
                $html .= '</div>';
                return $html;
            })
            ->renderAsHtml();

        // 🔥 ESCRITURA (Campo Real)
        yield CollectionField::new('segmentoComponentes', 'Componentes Logísticos Vinculados')
            ->hideOnIndex()
            ->useEntryCrudForm(TravelSegmentoComponenteCrudController::class)
            ->setFormTypeOptions(['by_reference' => false, 'prototype' => true])
            ->setFormTypeOption('prototype_data', new TravelSegmentoComponente())
            ->setColumns(12);

        yield CollectionField::new('imagenes', 'Galería de Fotos')
            ->useEntryCrudForm(TravelSegmentoImagenCrudController::class)
            ->setFormTypeOptions(['by_reference' => false, 'prototype' => true])
            ->setFormTypeOption('prototype_data', new TravelSegmentoImagen())
            ->setColumns(12);

        yield FormField::addPanel('Contenido Introductorio y Notas Específicas')->setIcon('fa fa-book-open');

        // 🔥 LECTURA (Getter Virtual)
        yield TextField::new('virtualNotas', 'Intros y tips')
            ->hideOnForm()
            ->formatValue(static function ($value, $entity) {
                $notas = $entity->getNotas();
                if ($notas->isEmpty()) return '<span class="text-muted small"><i class="fas fa-info-circle"></i> Sin notas vinculadas</span>';

                $html = '<ul style="max-height: 160px; overflow-y: auto; text-align: left; min-width: 240px; margin: 0; padding: 0 5px 0 0; list-style: none;">';
                foreach ($notas as $nota) {
                    $nombre = htmlspecialchars((string) $nota);
                    $html .= sprintf('<li class="px-2 py-1 mb-1 bg-light border rounded small text-truncate" title="%s" style="display: block;"><i class="fas fa-sticky-note text-warning" style="font-size: 0.8em; margin-right: 4px;"></i> <span class="text-dark">%s</span></li>', $nombre, $nombre);
                }
                $html .= '</ul>';
                return $html;
            })
            ->renderAsHtml();

        // 🔥 ESCRITURA (Campo Real)
        yield AssociationField::new('notas', 'Intros y tips')
            ->setFormTypeOptions(['by_reference' => false, 'multiple' => true])
            ->setHelp('Selecciona la Historia (Intro) notas, recomendaciones o tips para este segmento.')
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12);
    }
}