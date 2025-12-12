<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsReservaEstado;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;

class PmsReservaEstadoAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('codigo', TextType::class, [
                'label' => 'Código interno',
            ])
            ->add('nombre', TextType::class, [
                'label' => 'Nombre visible',
            ])
            ->add('color', TextType::class, [
                'required' => false,
                'label' => 'Color (HEX)',
            ])
            ->add('codigoBeds24', TextType::class, [
                'required' => false,
                'label' => 'Código Beds24',
                'help' => 'Ej: confirmed, cancelled, pending, noshow',
            ])
            ->add('esFinal', CheckboxType::class, [
                'required' => false,
                'label' => 'Estado final',
            ])
            ->add('orden', IntegerType::class, [
                'required' => false,
                'label' => 'Orden',
            ]);
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('codigo')
            ->add('nombre')
            ->add('codigoBeds24');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('codigo')
            ->add('nombre')
            ->add('codigoBeds24')
            ->add('esFinal')
            ->add('orden')
            ->add('color');
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('codigo')
            ->add('nombre')
            ->add('color')
            ->add('codigoBeds24')
            ->add('esFinal')
            ->add('orden')
            ->add('created')
            ->add('updated');
    }
}
