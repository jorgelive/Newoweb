<?php
namespace App\Oweb\Admin;

use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

final class MaestroTipocontactoAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'MAESTROS';
    }

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
            ->add('creado', null, [
                'label' => 'Creado',
                'format' => 'Y/m/d']
            )
            ->add('modificado', null, [
                'label' => 'Modificado',
                'format' => 'Y/m/d']
            )
            ->add(ListMapper::NAME_ACTIONS, null, [
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
                ->add('titulo', null, [
                    'label' => 'Título']
                )
                ->add('creado', null, [
                    'label' => 'Creado',
                    'format' => 'Y/m/d']
                )
                ->add('modificado', null, [
                    'label' => 'Modificado',
                    'format' => 'Y/m/d'])
            ->end();
    }
}
