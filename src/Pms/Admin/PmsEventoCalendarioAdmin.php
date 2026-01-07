<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsEventoCalendario;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

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
                ->add('estado', ModelType::class, [
                    'required' => true,
                    'label' => 'Estado',
                    'btn_add' => false,
                ])
                ->add('estadoPago', ModelType::class, [
                    'required' => true,
                    'label' => 'Estado de pago',
                    'btn_add' => false,
                ])
                ->add('cantidadAdultos', IntegerType::class, [
                    'required' => false,
                    'label' => 'Adultos (evento)',
                ])
                ->add('cantidadNinos', IntegerType::class, [
                    'required' => false,
                    'label' => 'Niños (evento)',
                ])
                ->add('reserva', ModelType::class, [
                    'required' => false,
                    'label' => 'Reserva PMS',
                    'btn_add' => false,
                ])
                ->add('inicio', DateTimePickerType::class, [
                    'format' => 'yyyy/MM/dd HH:mm',
                ])
                ->add('fin', DateTimePickerType::class, [
                    'format' => 'yyyy/MM/dd HH:mm',
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
            ->with('Beds24 (solo lectura)', ['class' => 'col-md-12'])
                ->add('beds24Links', ModelType::class, [
                    'required' => false,
                    'label' => 'Beds24 Links (bookIds)',
                    'btn_add' => false,
                    'multiple' => true,
                    'disabled' => true,
                ])
                ->add('estadoBeds24', TextType::class, [
                    'required' => false,
                    'label' => 'Beds24 Status',
                    'disabled' => true,
                ])
                ->add('subestadoBeds24', TextType::class, [
                    'required' => false,
                    'label' => 'Beds24 Substatus',
                    'disabled' => true,
                ])
                ->add('monto', TextType::class, [
                    'required' => false,
                    'label' => 'Monto (unidad) USD',
                    'disabled' => true,
                ])
                ->add('comision', TextType::class, [
                    'required' => false,
                    'label' => 'Comisión',
                    'disabled' => true,
                ])
                ->add('rateDescription', TextareaType::class, [
                    'required' => false,
                    'label' => 'Rate Description',
                    'disabled' => true,
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('pmsUnidad')
            ->add('estado')
            ->add('reserva')
            ->add('inicio')
            ->add('fin')
            ->add('cantidadAdultos')
            ->add('cantidadNinos');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            // Maestros: solo lectura en list (sin links)
            ->add('pmsUnidad', 'string')
            ->add('estado', 'string')
            ->add('estadoPago', 'string')
            ->add('cantidadAdultos')
            ->add('cantidadNinos')
            ->add('reserva')
            ->add('inicio', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('fin', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('tituloCache')
            ->add('origenCache')
            ->add('beds24Links')
            ->add('estadoBeds24')
            ->add('subestadoBeds24')
            ->add('monto')
            ->add('comision')
            ->add('rateDescription')
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
            // Maestros: solo lectura en show (sin links)
            ->add('pmsUnidad', 'string')
            ->add('estado', 'string')
            ->add('estadoPago', 'string')
            ->add('cantidadAdultos')
            ->add('cantidadNinos')
            ->add('reserva')
            ->add('inicio', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('fin', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('tituloCache')
            ->add('origenCache')
            ->add('beds24Links')
            ->add('estadoBeds24')
            ->add('subestadoBeds24')
            ->add('monto')
            ->add('comision')
            ->add('rateDescription')
            ;
    }
}
