<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class ComprobanteProductoservicioAdmin extends AbstractSecureAdmin
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
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('codigosunat', null, [
                'label' => 'Código Sunat'
            ])
            ->add('tipoproductoservicio', null, [
                'label' => 'Tipo'
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
            ->add('nombre')
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('codigosunat', null, [
                'label' => 'Código Sunat'
            ])
            ->add('tipoproductoservicio', null, [
                'label' => 'Tipo'
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
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('codigosunat', null, [
                'label' => 'Código Sunat'
            ])
            ->add('tipoproductoservicio', null, [
                'label' => 'Tipo'
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
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('codigosunat', null, [
                'label' => 'Código Sunat'
            ])
            ->add('tipoproductoservicio', null, [
                'label' => 'Tipo'
            ])
        ;
    }

}
