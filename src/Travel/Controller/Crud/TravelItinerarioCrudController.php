<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

class TravelItinerarioCrudController extends BaseCrudController
{
    /**
     * @param string $apiUrl URL base de la API inyectada desde las variables de entorno (.env).
     * @param AdminUrlGenerator $adminUrlGenerator Inyectado para el BaseCrudController
     * @param RequestStack $requestStack Inyectado para el BaseCrudController
     */
    public function __construct(
        #[Autowire('%env(API_HOST_URL)%')] private string $apiUrl,
        AdminUrlGenerator $adminUrlGenerator,
        RequestStack $requestStack
    ) {
        // Inicializamos el constructor blindado del padre
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return TravelItinerario::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Plantilla de Itinerario')
            ->setEntityLabelInPlural('Plantillas Comerciales')
            ->setSearchFields(['id', 'nombreInterno'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    /**
     * Inyecta los assets y variables de entorno necesarios para el funcionamiento
     * de los componentes anidados (como el modal de logística operado por Stimulus).
     *
     * @param Assets $assets Contenedor de configuración de assets de EasyAdmin.
     * @return Assets
     */
    public function configureAssets(Assets $assets): Assets
    {
        return parent::configureAssets($assets)
            ->addAssetMapperEntry('panel')
            ->addHtmlContentToHead(sprintf('<meta name="api-url" content="%s">', $this->apiUrl));
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_WRITE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Definición de Plantilla')->setIcon('fa fa-book');

        yield AssociationField::new('servicio', 'Servicio Vinculado')->setColumns(6);
        yield TextField::new('nombreInterno', 'Nombre de Plantilla')->setColumns(6);
        yield IntegerField::new('duracionDias', 'Duración Total (Días)')->setColumns(4);

        yield FormField::addPanel('Presentación')->setIcon('fa fa-bullhorn');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        yield CollectionField::new('titulo', 'Título Comercial')
            ->setEntryType(TranslationTextType::class)
            ->setColumns(12);

        yield FormField::addPanel('Estructura del Itinerario (Narrativa)')->setIcon('fa fa-stream');

        yield CollectionField::new('itinerarioSegmentos', 'Pasos del Itinerario')
            ->useEntryCrudForm(TravelItinerarioSegmentoRelCrudController::class)
            ->setFormTypeOptions([
                'by_reference' => false,
                'prototype'    => true,
            ])
            ->setFormTypeOption('prototype_data', new TravelItinerarioSegmentoRel())
            ->setColumns(12)
            ->setHelp('Selecciona los segmentos del pool del servicio y ordénalos por día.');

        yield FormField::addPanel('Contenido Introductorio y Notas Específicas')->setIcon('fa fa-book-open');

        yield AssociationField::new('notas', 'Historias / Intros Seleccionadas')
            ->setFormTypeOptions([
                'by_reference' => false,
                'multiple' => true,
            ])
            ->setHelp('Selecciona la Historia (Intro) u otras políticas exclusivas para esta plantilla de itinerario.')
            ->setColumns(12);
    }
}