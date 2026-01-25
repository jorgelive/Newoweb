<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;

class TransporteUnidadAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'OPERACIONES';
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('placa')
            ->add('abreviatura')
            ->add('color')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('placa', null, [
                'editable' => true
            ])
            ->add('abreviatura', null, [
                'editable' => true
            ])
            ->add('color', null, [
                'editable' => true
            ])
            ->add(ListMapper::NAME_ACTIONS, 'actions', [
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                ],
                'label' => 'Acciones'
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper
            ->add('nombre')
            ->add('placa')
            ->add('abreviatura')
            ->add('color')
            ->add('unidadbitacoras', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Bitacoras'
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
            ->add('nombre')
            ->add('placa')
            ->add('abreviatura')
            ->add('color')
            ->add('unidadbitacoras', null, [
                'label' => 'Bitacoras'
            ])
        ;
    }

}
