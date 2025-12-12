<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsUnidadBeds24Map;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsUnidadBeds24MapAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'pmsUnidad';
        $sortValues['_sort_order'] = 'ASC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Relación', ['class' => 'col-md-8'])
                ->add('pmsUnidad', ModelType::class, [
                    'label' => 'Unidad PMS',
                    'btn_add' => false,
                ])
                ->add('beds24RoomId', IntegerType::class, [
                    'label' => 'Beds24 Room ID',
                ])
                ->add('beds24UnitId', IntegerType::class, [
                    'required' => false,
                    'label' => 'Beds24 Unit ID',
                ])
                ->add('nota', TextType::class, [
                    'required' => false,
                ])
            ->end()
            ->with('Flags', ['class' => 'col-md-4'])
                ->add('esPrincipal', CheckboxType::class, [
                    'required' => false,
                    'label' => 'Principal',
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('pmsUnidad.establecimiento', null, ['label' => 'Establecimiento'])
            ->add('pmsUnidad')
            ->add('beds24RoomId')
            ->add('esPrincipal');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('pmsUnidad')
            ->add('pmsUnidad.establecimiento', null, ['label' => 'Establecimiento'])
            ->add('beds24RoomId')
            ->add('beds24UnitId')
            ->add('esPrincipal', null, ['editable' => true])
            ->add('nota')
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
            ->add('pmsUnidad')
            ->add('beds24RoomId')
            ->add('beds24UnitId')
            ->add('esPrincipal')
            ->add('nota');
    }
}
