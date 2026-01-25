<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\DatePickerType;

class TransporteUnidadbitacoraAdmin extends AbstractSecureAdmin
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
            ->add('unidad')
            ->add('tipounibit', null, [
                'label' => 'Tipo'
            ])
            ->add('contenido')
            ->add('kilometraje')
            ->add('fecha')

        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('unidad')
            ->add('tipounibit', null, [
                'label' => 'Tipo'
            ])
            ->add('contenido')
            ->add('kilometraje')
            ->add('fecha')
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
        if($this->getRoot()->getClass() != 'App\Oweb\Entity\TransporteUnidad'){
            $formMapper->add('unidad');
        }

        $formMapper
            ->add('tipounibit', null, [
                'label' => 'Tipo'
            ])
            ->add('contenido')
            ->add('kilometraje')
            ->add('fecha', DatePickerType::class, [
                'label' => 'Fecha',
                'dp_use_current' => true,
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd'
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
            ->add('unidad')
            ->add('tipounibit', null, [
                'label' => 'Tipo'
            ])
            ->add('contenido')
            ->add('kilometraje')
            ->add('fecha')
        ;
    }

}
