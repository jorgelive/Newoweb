<?php
namespace App\Admin;

use App\Entity\MaestroTipocontacto;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

final class MaestroTipocontactoAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('nombre', null, ['label' => 'Nombre interno'])
            ->add('titulo', null, ['label' => 'Título'])
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('nombre', null, ['label' => 'Nombre interno'])
            ->add('titulo', null, ['label' => 'Título']);
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('nombre', null, ['label' => 'Nombre interno'])
            ->add('titulo', null, ['label' => 'Título'])
            ->add('creado', null, ['label' => 'Creado'])
            ->add('modificado', null, ['label' => 'Modificado'])
            ->add('_action', null, [
                'actions' => [
                    'edit' => [],
                    'delete' => [],
                    'show' => [],
                ],
            ]);
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->with('Detalles del Tipo de Contacto')
                ->add('nombre', null, ['label' => 'Nombre interno'])
                ->add('titulo', null, ['label' => 'Título'])
                ->add('creado', null, ['label' => 'Creado'])
                ->add('modificado', null, ['label' => 'Modificado'])
            ->end();
    }
}
