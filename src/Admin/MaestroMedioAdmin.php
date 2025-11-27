<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Route\RouteCollection;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Symfony\Component\Form\Extension\Core\Type\FileType;

class MaestroMedioAdmin extends AbstractAdmin
{
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

    protected function configureActionButtons(array $buttonList, string $action, ?object $object = null): array
    {
        $buttonList['carga'] = ['template' => 'admin/maestro_medio/carga_button.html.twig'];

        return $buttonList;
    }

    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('clasemedio', null, [
                    'label' => 'Clase'
                ]
            )
            ->add('nombre')
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título'
            ])
            ->add('enlace')
        ;
    }

    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('clasemedio', null, [
                    'label' => 'Clase'
                ]
            )
            ->add('webThumbPath', 'string', [
                    'label' => 'Archivo',
                    'template' => 'admin/base_sonata/list_image.html.twig'
                ]
            )
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('titulo', null, [
                'label' => 'Título',
                'editable' => true
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'copiar_url' => [
                        'template' => 'admin/maestro_medio/list__action_url_clipboard.html.twig',
                    ],
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => [
                        'template' => 'admin/maestro_medio/list__action_traducir.html.twig',
                    ],
                ],
            ])
        ;
    }

    protected function configureFormFields(FormMapper $formMapper): void
    {

        $formMapper
            ->add('clasemedio', null, [
                    'label' => 'Clase'
                ]
            )
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('enlace')
            ->add('archivo', FileType::class, [
                'required' => false
            ])
        ;
    }

    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('webThumbPath', null, [
                    'label' => 'Archivo',
                    'template' => 'admin/base_sonata/show_image.html.twig'
                ]
            )
            ->add('enlace')
            ->add('clasemedio', null, [
                    'label' => 'Clase'
                ]
            )
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
        ;
    }

    public function prePersist($object): void
    {
        $this->manageFileUpload($object);
    }

    public function preUpdate($object): void
    {
        $this->manageFileUpload($object);
    }

    private function manageFileUpload($medio): void
    {
        if($medio->getArchivo()) {
            $medio->refreshModificado();
        }
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
        $collection->add('carga', 'carga');
        $collection->add('ajaxcrear', 'ajaxcrear');
    }

}
