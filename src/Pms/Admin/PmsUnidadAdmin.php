<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsUnidad;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsUnidadAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'nombre';
        $sortValues['_sort_order'] = 'ASC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('General', ['class' => 'col-md-8'])
                ->add('establecimiento', ModelType::class, [
                    'label' => 'Establecimiento',
                    'required' => true,
                    'btn_add' => false,
                ])
                ->add('nombre', TextType::class, [
                    'required' => true,
                ])
                ->add('codigoInterno', TextType::class, [
                    'required' => false,
                ])
                ->add('capacidad', IntegerType::class, [
                    'required' => false,
                ])
            ->end()
            ->with('Estado', ['class' => 'col-md-4'])
                ->add('activo', CheckboxType::class, [
                    'required' => false,
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('establecimiento')
            ->add('nombre')
            ->add('codigoInterno')
            ->add('activo');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('nombre')
            ->add('establecimiento')
            ->add('codigoInterno')
            ->add('capacidad')
            ->add('activo', null, ['editable' => true])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'actions' => [
                    'edit' => [],
                    'delete' => [],
                ],
            ]);
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('establecimiento')
            ->add('nombre')
            ->add('codigoInterno')
            ->add('capacidad')
            ->add('activo');
    }
}
