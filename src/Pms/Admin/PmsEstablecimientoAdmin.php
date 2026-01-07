<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsEstablecimiento;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use App\Entity\MaestroPais;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;

class PmsEstablecimientoAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'nombreComercial';
        $sortValues['_sort_order'] = 'ASC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('General', ['class' => 'col-md-8'])
                ->add('nombreComercial', TextType::class, [
                    'required' => true,
                    'label' => 'Nombre comercial',
                ])
                ->add('direccionLinea1', TextType::class, [
                    'required' => false,
                ])
                ->add('ciudad', TextType::class, [
                    'required' => false,
                ])
                ->add('pais', ModelAutocompleteType::class, [
                    'property' => 'nombre',
                    'required' => false,
                    'label' => 'País',
                ])
                ->add('telefonoPrincipal', TextType::class, [
                    'required' => false,
                ])
                ->add('emailContacto', TextType::class, [
                    'required' => false,
                ])
                ->add('horaCheckIn', null, [
                    'required' => false,
                    'label' => 'Hora Check-in',
                ])
                ->add('horaCheckOut', null, [
                    'required' => false,
                    'label' => 'Hora Check-out',
                ])
                ->add('timezone', TextType::class, [
                    'required' => false,
                    'help' => 'Ej: America/Lima',
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('nombreComercial')
            ->add('ciudad')
            ->add('pais', null, [
                'field_type' => ModelAutocompleteType::class,
                'field_options' => [
                    'property' => 'nombre',
                ],
            ])
            ->add('horaCheckIn')
            ->add('horaCheckOut')
            ->add('timezone');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('nombreComercial')
            ->add('ciudad')
            ->add('pais')
            ->add('horaCheckIn', null, [
                'label' => 'Check-in',
                'format' => 'H:i',
            ])
            ->add('horaCheckOut', null, [
                'label' => 'Check-out',
                'format' => 'H:i',
            ])
            ->add('timezone')
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
            ->add('nombreComercial')
            ->add('direccionLinea1')
            ->add('ciudad')
            ->add('pais')
            ->add('telefonoPrincipal')
            ->add('emailContacto')
            ->add('horaCheckIn', null, [
                'label' => 'Hora Check-in',
                'format' => 'H:i',
            ])
            ->add('horaCheckOut', null, [
                'label' => 'Hora Check-out',
                'format' => 'H:i',
            ])
            ->add('timezone');
    }
}
