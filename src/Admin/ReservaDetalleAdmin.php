<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\DatePickerType;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class ReservaDetalleAdmin extends AbstractAdmin
{

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('tipodetalle', null, [
                'label' => 'Tipo'
            ])
            ->add('user',  null, [
                'label' => 'Personal'
            ])
            ->add('nota')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('tipodetalle', null, [
                'label' => 'Tipo'
            ])
            ->add('user',  null, [
                'label' => 'Personal'
            ])
            ->add('nota')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                ],
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper
            ->add('tipodetalle', null, [
                'label' => 'Tipo'
            ])
            ->add('user',  null, [
                'label' => 'Personal'
            ])
            ->add('nota')
        ;

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('nota', null, [
                    'label' => 'Nota',
                    'attr' => [
                        'style' => 'min-width: 200px;'
                    ]
                ])

            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($widthModifier) {
                if($event->getData()
                    && $this->getRoot()->getClass() == 'App\Entity\ReservaReserva'
                ){
                    $widthModifier($event->getForm());
                }
            }
        );
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('tipodetalle', null, [
                'label' => 'Tipo'
            ])
            ->add('user',  null, [
                'label' => 'Personal'
            ])
            ->add('nota')
        ;
    }
}
