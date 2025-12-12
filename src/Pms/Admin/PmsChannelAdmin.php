<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsChannel;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsChannelAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('codigo', TextType::class, ['label' => 'Código (Beds24)'])
            ->add('nombre', TextType::class)
            ->add('beds24ChannelId', TextType::class, [
                'label' => 'ID real Beds24',
                'required' => false,
            ])
            ->add('esExterno', CheckboxType::class, ['required' => false])
            ->add('esDirecto', CheckboxType::class, ['required' => false])
            ->add('color', TextType::class, ['required' => false]);
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('codigo')
            ->add('nombre')
            ->add('esExterno')
            ->add('esDirecto');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('codigo')
            ->add('nombre')
            ->add('beds24ChannelId')
            ->add('esExterno')
            ->add('esDirecto')
            ->add('color');
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('codigo')
            ->add('nombre')
            ->add('beds24ChannelId')
            ->add('esExterno')
            ->add('esDirecto')
            ->add('color')
            ->add('created')
            ->add('updated');
    }
}
