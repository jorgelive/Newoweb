<?php
namespace App\Admin;

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
        parent::configureFormFields($formMapper);

        $formMapper
            ->tab('Varios')
                ->with('Organización')
                    ->add('dependencia', null, [
                        'required' => false,
                        'label' => 'Dependencia'
                    ])
                    ->add('area', null, [
                        'required' => false,
                        'label' => 'Area'
                    ])
                ->end()
            ->end()
        ;
    }
}