<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class ComprobanteComprobanteitemAdmin extends AbstractAdmin
{

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('productoservicio',  null, [
                'label' => 'Producto / Servicio'
            ])
            ->add('unitario')
            ->add('comprobante')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('productoservicio',  null, [
                'label' => 'Producto / Servicio'
            ])
            ->add('cantidad')
            ->add('unitario')
            ->add('comprobante')
            ->add(ListMapper::NAME_ACTIONS, 'actions', [
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => []
                ],
                'label' => 'Acciones'
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        if ($this->getRoot()->getClass() != 'App\Entity\ComprobanteComprobante'){
            $formMapper->add('comprobante');
        }
        $formMapper
            ->add('productoservicio',  null, [
                'label' => 'Producto / Servicio'
            ])
            ->add('cantidad')
            ->add('unitario')
        ;

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('unitario', null, [
                    'label' => 'Unitario',
                    'attr' => [
                        'style' => 'width: 80px; text-align: right;'
                    ]
                ])
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($widthModifier) {
                if($event->getData()
                    && $this->getRoot()->getClass() == 'App\Entity\ComprobanteComprobante'
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
            ->add('productoservicio',  null, [
                'label' => 'Producto / Servicio'
            ])
            ->add('cantidad')
            ->add('unitario')
            ->add('comprobante')
        ;
    }


}
