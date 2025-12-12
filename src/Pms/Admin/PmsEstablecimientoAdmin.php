<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsEstablecimiento;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

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
                ->add('pais', ModelType::class, [
                    'required' => false,
                    'label' => 'País',
                    'btn_add' => false,
                ])
                ->add('telefonoPrincipal', TextType::class, [
                    'required' => false,
                ])
                ->add('emailContacto', TextType::class, [
                    'required' => false,
                ])
                ->add('timezone', TextType::class, [
                    'required' => false,
                ])
            ->end()
            ->with('Beds24', ['class' => 'col-md-4'])
                ->add('beds24Config', ModelType::class, [
                    'required' => false,
                    'label' => 'Configuración Beds24',
                    'btn_add' => false,
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('nombreComercial')
            ->add('ciudad')
            ->add('pais')
            ->add('beds24Config');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('nombreComercial')
            ->add('ciudad')
            ->add('pais')
            ->add('beds24Config')
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
            ->add('horaCheckIn')
            ->add('horaCheckOut')
            ->add('timezone')
            ->add('beds24Config');
    }
}
