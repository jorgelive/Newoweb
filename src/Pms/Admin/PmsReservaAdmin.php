<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsReserva;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsReservaAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'fechaLlegada';
        $sortValues['_sort_order'] = 'DESC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Reserva', ['class' => 'col-md-6'])
                ->add('codigoReserva', TextType::class, [
                    'required' => false,
                    'label' => 'Código reserva',
                ])
                ->add('nombreCliente', TextType::class, [
                    'required' => false,
                    'label' => 'Nombre cliente',
                ])
                ->add('telefono', TextType::class, [
                    'required' => false,
                ])
                ->add('telefono2', TextType::class, [
                    'required' => false,
                ])
                ->add('emailCliente', TextType::class, [
                    'required' => false,
                ])
                ->add('fechaLlegada', DateType::class, [
                    'widget' => 'single_text',
                    'required' => false,
                ])
                ->add('fechaSalida', DateType::class, [
                    'widget' => 'single_text',
                    'required' => false,
                ])
            ->end()
            ->with('Estado y monto', ['class' => 'col-md-6'])
                ->add('channel', null, [
                    'required' => false,
                    'label' => 'Canal',
                ])
                ->add('montoTotal', MoneyType::class, [
                    'required' => false,
                    'currency' => false,
                    'divisor' => 1,
                ])
                ->add('moneda', \Sonata\AdminBundle\Form\Type\ModelType::class, [
                    'required' => false,
                    'label' => 'Moneda',
                    'btn_add' => false,
                ])
                ->add('estado', null, [
                    'required' => false,
                    'label' => 'Estado',
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('codigoReserva')
            ->add('nombreCliente')
            ->add('channel')
            ->add('estado')
            ->add('fechaLlegada')
            ->add('fechaSalida');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('codigoReserva')
            ->add('nombreCliente')
            ->add('channel')
            ->add('fechaLlegada')
            ->add('fechaSalida')
            ->add('montoTotal')
            ->add('moneda')
            ->add('estado')
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
            ->add('codigoReserva')
            ->add('nombreCliente')
            ->add('telefono')
            ->add('telefono2')
            ->add('emailCliente')
            ->add('fechaLlegada')
            ->add('fechaSalida')
            ->add('channel')
            ->add('montoTotal')
            ->add('moneda')
            ->add('estado');
    }
}
