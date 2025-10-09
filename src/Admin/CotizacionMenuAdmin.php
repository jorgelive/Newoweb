<?php

namespace App\Admin;

use App\Entity\CotizacionMenu;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

final class CotizacionMenuAdmin extends AbstractAdmin
{
    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('id')
            ->add('nombre')
            ->add('titulo')
            ->add('modificado', 'doctrine_orm_datetime_range'); // útil
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('nombre')
            ->add('titulo')
            ->add('descripcion', null, ['truncate' => ['length' => 80]])
            ->add('creado')
            ->add('modificado')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'actions' => ['show' => [], 'edit' => [], 'delete' => []],
            ]);
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Básico', ['class' => 'col-md-8'])
            ->add('nombre')
            ->add('titulo')
            ->add('descripcion', null, ['required' => false])
            ->end()
            ->with('Metadatos', ['class' => 'col-md-4'])
            ->add('creado', null, ['disabled' => true, 'required' => false])
            ->add('modificado', null, ['disabled' => true, 'required' => false])
            ->end();
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('nombre')
            ->add('titulo')
            ->add('descripcion')
            ->add('creado')
            ->add('modificado');
    }
}
