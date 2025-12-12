<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsEventoCalendario;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsEventoCalendarioAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'inicio';
        $sortValues['_sort_order'] = 'DESC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Principal', ['class' => 'col-md-8'])
                ->add('pmsUnidad', ModelType::class, [
                    'label' => 'Unidad',
                    'btn_add' => false,
                ])
                ->add('reserva', ModelType::class, [
                    'required' => false,
                    'label' => 'Reserva PMS',
                    'btn_add' => false,
                ])
                ->add('tipo', ModelType::class, [
                    'required' => false,
                    'label' => 'Tipo',
                    'btn_add' => false,
                ])
                ->add('inicio', DateTimeType::class, [
                    'widget' => 'single_text',
                ])
                ->add('fin', DateTimeType::class, [
                    'widget' => 'single_text',
                ])
            ->end()
            ->with('Cache', ['class' => 'col-md-4'])
                ->add('tituloCache', TextType::class, [
                    'required' => false,
                    'label' => 'Título (cache)',
                ])
                ->add('origenCache', TextType::class, [
                    'required' => false,
                    'label' => 'Origen (cache)',
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('pmsUnidad')
            ->add('reserva')
            ->add('tipo')
            ->add('inicio')
            ->add('fin');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('pmsUnidad')
            ->add('reserva')
            ->add('tipo')
            ->add('inicio')
            ->add('fin')
            ->add('tituloCache')
            ->add('origenCache')
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
            ->add('pmsUnidad')
            ->add('reserva')
            ->add('tipo')
            ->add('inicio')
            ->add('fin')
            ->add('tituloCache')
            ->add('origenCache');
    }
}
