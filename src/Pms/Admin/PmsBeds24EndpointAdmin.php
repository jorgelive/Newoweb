<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsBeds24Endpoint;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsBeds24EndpointAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'accion';
        $sortValues['_sort_order'] = 'ASC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Definición', ['class' => 'col-md-8'])
                ->add('accion', TextType::class, [
                    'label' => 'Acción lógica (ej: BOOKING_CREATE)',
                ])
                ->add('endpoint', TextType::class, [
                    'label' => 'Endpoint (path o URL)',
                ])
                ->add('metodo', ChoiceType::class, [
                    'label' => 'Método HTTP',
                    'choices' => [
                        'POST' => 'POST',
                        'GET' => 'GET',
                    ],
                ])
                ->add('descripcion', TextareaType::class, [
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
            ->add('accion')
            ->add('metodo')
            ->add('activo');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('accion')
            ->add('endpoint')
            ->add('metodo')
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
            ->add('accion')
            ->add('endpoint')
            ->add('metodo')
            ->add('descripcion')
            ->add('activo');
    }
}
