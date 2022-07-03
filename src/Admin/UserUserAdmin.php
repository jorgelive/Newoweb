<?php
namespace App\Admin;

use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\Form\Type\CollectionType;
use Sonata\UserBundle\Admin\Model\UserAdmin as SonataUserAdmin;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class UserUserAdmin extends SonataUserAdmin
{
    /**
     * {@inheritdoc}
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {

        $formMapper

            ->with('organizacion' , ['class' => 'col-md-6'])
                ->add('dependencia', null, [
                    'required' => false,
                    'label' => 'Dependencia'
                ])
                ->add('area', null, [
                    'required' => false,
                    'label' => 'Area'
                ])
            ->end()
            ->with('Varios' , ['class' => 'col-md-6'])
                ->add('firstname', null, [
                    'label' => 'Nombres'
                ])
                ->add('lastname', null, [
                    'label' => 'Apellidos'
                ])
            ->end()

        ;
        parent::configureFormFields($formMapper);
    }

    protected function configureListFields(ListMapper $list): void
    {
        parent::configureListFields($list);

        $list
            ->add('firstname')
            ->add('lastname')
            ->add(ListMapper::NAME_ACTIONS, 'actions', [
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                ],
                'label' => 'Acciones'
            ])
        ;

    }
}