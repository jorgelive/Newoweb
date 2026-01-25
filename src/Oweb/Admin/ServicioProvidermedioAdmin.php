<?php

namespace App\Oweb\Admin;

use App\Oweb\Entity\ServicioProvidertipocaracteristica;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class ServicioProvidermedioAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'OPERACIONES';
    }

    public function configure(): void
    {
        $this->classnameLabel = "Multimedia";
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'DESC';
        $sortValues[DatagridInterface::SORT_BY] = 'id';
    }

    protected function configureFilterParameters(array $parameters): array
    {

        return $parameters;
    }

    protected function configureActionButtons(array $buttonList, string $action, ?object $object = null): array
    {
        $buttonList['carga'] = ['template' => 'oweb/admin/servicio_providermedio/carga_button.html.twig'];

        return $buttonList;
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('provider', null, [
                'label' => 'Proveedor'
            ])
            ->add('nombre')
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título'
            ])
            ->add('enlace')
            ->add('prioridad')
        ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('provider', null, [
                'label' => 'Proveedor'
            ])
            ->add('webThumbPath', 'string', [
                    'label' => 'Archivo',
                    'template' => 'oweb/admin/base_sonata/list_image.html.twig'
                ]
            )
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('titulo', null, [
                'label' => 'Título',
                'editable' => true
            ])
            ->add('prioridad', null, [
                'label' => 'Prioridad',
                'editable' => true
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => [
                        'template' => 'oweb/admin/servicio_providermedio/list__action_traducir.html.twig'
                    ]
                ]
            ])
        ;
    }

    protected function configureFormFields(FormMapper $formMapper): void
    {

        if($this->getRoot()->getClass() != 'App\Oweb\Entity\ServicioProvider'){
            $formMapper->add('provider', null, [
                'label' => 'Proveedor'
            ]);
        }

        $formMapper
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('enlace')
            ->add('prioridad')
            ->add('archivo', FileType::class, [
                'required' => false
            ])
        ;

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('nombre', null, [
                    'label' => 'Nombre',
                    'attr' => [
                        'style' => 'min-width: 150px;'
                    ]
                ])
                ->add('titulo', null, [
                    'label' => 'Título',
                    'attr' => [
                        'style' => 'min-width: 150px;'
                    ]
                ])
                ->add('enlace', null, [
                    'label' => 'Enlace',
                    'attr' => [
                        'style' => 'min-width: 250px;'
                    ]
                ])
                ->add('prioridad')
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($widthModifier) {
                if($event->getData()
                    && $this->getRoot()->getClass() == 'App\Oweb\Entity\ServicioProvider'
                ){
                    $widthModifier($event->getForm());
                }
            }
        );
    }

    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('provider', null, [
                'label' => 'Proveedor'
            ])
            ->add('webThumbPath', null, [
                    'label' => 'Archivo',
                    'template' => 'oweb/admin/base_sonata/show_image.html.twig'
                ]
            )
            ->add('enlace')
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
        ;
    }

    public function prePersist($unitmedio): void
    {
        $this->manageFileUpload($unitmedio);
    }

    public function preUpdate($unitmedio): void
    {
        $this->manageFileUpload($unitmedio);
    }

    private function manageFileUpload($unitmedio): void
    {
        if($unitmedio->getArchivo()) {
            $unitmedio->refreshModificado();
        }
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
        $collection->add('carga', 'carga');
        $collection->add('ajaxcrear', 'ajaxcrear');
    }

}
