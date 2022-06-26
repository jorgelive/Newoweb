<?php

namespace App\Admin;


use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;
use Sonata\AdminBundle\Show\ShowMapper;

use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class FitDietaalimentoAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('dietacomida',  null, [
                'label' => 'Comida'
            ])
            ->add('cantidad')
            ->add('alimento')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('cantidad')
            ->add('dietacomida',  null, [
                'label' => 'Comida'
            ])
            ->add('alimento')
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
        if ($this->getRoot()->getClass() != 'App\Entity\FitDieta'
            && $this->getRoot()->getClass() != 'App\Entity\FitDietacomida'
        ){
            $formMapper->add('dietacomida',  null, [
                'label' => 'Comida'
            ]);
        }

        $formMapper
            ->add('cantidad', null, [

            ])
            ->add('alimento', ModelAutocompleteType::class, [
                'property' => 'nombre',
                'placeholder' => '',
                'minimum_input_length' => 0,
                'dropdown_auto_width' => false
            ])
        ;

        $cantidadModifier = function (FormInterface $form, $attr) {

            $form->add(
                'cantidad',
                null,
                [
                    'label' => 'Cantidad',
                    'attr' => [
                        'data-cantidadalimento' => $attr['cantidadalimento'],
                        'data-medidaalimento' => $attr['medidaalimento'],
                        'data-tipoalimento' => $attr['tipoalimento'],
                        'data-proteinaaltovalor' => $attr['proteinaaltovalor']

                    ]
                ]
            );
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($cantidadModifier) {

                if($event->getData()
                    && $event->getData()->getAlimento()
                ){

                    $attr['cantidadalimento'] = $event->getData()->getAlimento()->getCantidad();
                    $attr['medidaalimento'] = $event->getData()->getAlimento()->getMedidaalimento()->getNombre();
                    $attr['tipoalimento'] = $event->getData()->getAlimento()->getTipoalimento()->getNombre();
                    $attr['proteinaaltovalor'] = $event->getData()->getAlimento()->isProteinaaltovalor() ? 'true' : 'false';

                    //var_dump($event->getData()->getComponente()->getTipocomponente()->isDependeduracion());
                    $cantidadModifier($event->getForm(), $attr);
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
            ->add('dietacomida',  null, [
                'label' => 'Comida'
            ])
            ->add('cantidad')
            ->add('alimento')
        ;
    }
}
