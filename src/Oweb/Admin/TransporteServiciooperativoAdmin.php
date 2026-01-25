<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class TransporteServiciooperativoAdmin extends AbstractSecureAdmin
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
            ->add('tiposeroperativo', null, [
                'label' => 'Tipo'
            ])
            ->add('texto', null, [
                'label' => 'Contenido'
            ])
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('servicio')
            ->add('servicio.fechahorainicio', null, [
                'format' => 'Y/m/d H:i',
                'label' => 'Inicio'
            ])
            ->add('servicio.serviciocomponentes', null, [
                'label' => 'Componentes'
            ])
            ->add('tiposeroperativo', null, [
                'label' => 'Tipo'
            ])
            ->add('texto', null, [
                'label' => 'Contenido',
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
        if($this->getRoot()->getClass() != 'App\Oweb\Entity\TransporteServicio'){
            $formMapper->add('servicio');
        }
        $formMapper
            ->add('tiposeroperativo', null, [
                'label' => 'Tipo'
            ])
            ->add('texto', null, [
                'label' => 'Contenido'
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
            ->add('servicio')
            ->add('servicio.fechahorainicio', null, [
                'format' => 'Y/m/d H:i',
                'label' => 'Inicio'
            ])
            ->add('servicio.serviciocomponentes', null, [
                'label' => 'Componentes'
            ])
            ->add('tiposeroperativo', null, [
                'label' => 'Tipo'
            ])
            ->add('texto', null, [
                'label' => 'Contenido'
            ])
        ;
    }

}
