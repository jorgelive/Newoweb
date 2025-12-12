<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsEventoCalendarioTipo;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsEventoCalendarioTipoAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('codigo', TextType::class)
            ->add('nombre', TextType::class)
            ->add('color', TextType::class, ['required' => false])
            ->add('orden', IntegerType::class, ['required' => false]);
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('codigo')
            ->add('nombre');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('codigo')
            ->add('nombre')
            ->add('color')
            ->add('orden');
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('codigo')
            ->add('nombre')
            ->add('color')
            ->add('orden')
            ->add('created')
            ->add('updated');
    }
}
