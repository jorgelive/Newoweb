<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Sonata\AdminBundle\Datagrid\DatagridInterface;

class ServicioServicioAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'OPERACIONES';
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'cuenta';
    }


    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('nombre')
            ->add('paralelo')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('codigo', null, [
                'label' => 'Código',
                'editable' => true
            ])
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('paralelo', null, [
                'editable' => true
            ])
            ->add('componentes')
            ->add('itinerarios')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'clonar' => [
                        'template' => 'oweb/admin/servicio_servicio/list__action_clonar.html.twig'
                    ]
                ],
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('nombre')
            ->add('paralelo')
            ->add('componentes', null, [
                'by_reference' => false,
                'label' => 'Componentes'
            ])
            ->add('itinerarios', null, [
                'by_reference' => false,
                'label' => 'Itinerarios'
            ])
        ;
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('nombre')
            ->add('paralelo')
            ->add('componentes')
            ->add('itinerarios')
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('clonar', $this->getRouterIdParameter() . '/clonar');
    }
}
