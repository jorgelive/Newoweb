<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

class ServicioServicioAdmin extends AbstractAdmin
{

    protected $datagridValues = [
        '_page' => 1,
        '_sort_order' => 'ASC',
        '_sort_by' => 'cuenta',
    ];

    public function configure(): void
    {
        $this->setFormTheme([0 => 'servicio_servicio_admin/form_admin_fields.html.twig']);
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
                    'delete' => []
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
            ->add('componentes')
            ->add('itinerarios', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Itinerarios'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
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
}
