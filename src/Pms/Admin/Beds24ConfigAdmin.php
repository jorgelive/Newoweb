<?php

namespace App\Pms\Admin;

use App\Pms\Entity\Beds24Config;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class Beds24ConfigAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'id';
        $sortValues['_sort_order'] = 'ASC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('General', ['class' => 'col-md-8'])
                ->add('nombre', TextType::class, [
                    'required' => false,
                    'label' => 'Nombre interno',
                ])
                ->add('apiKey', TextType::class, [
                    'required' => false,
                    'label' => 'API key',
                ])
                ->add('propKey', TextType::class, [
                    'required' => false,
                    'label' => 'PropKey',
                ])
                ->add('propId', IntegerType::class, [
                    'required' => false,
                    'label' => 'PropId',
                ])
            ->end()
            ->with('Estado', ['class' => 'col-md-4'])
                ->add('activo', CheckboxType::class, [
                    'required' => false,
                    'label' => 'Activo',
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('nombre')
            ->add('propId')
            ->add('activo');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('nombre')
            ->add('propId')
            ->add('activo', null, ['editable' => true])
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
            ->add('id')
            ->add('nombre')
            ->add('apiKey')
            ->add('propKey')
            ->add('propId')
            ->add('activo');
    }
}
