<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class ReservaImporteAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'RESERVAS';
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('moneda')
            ->add('monto')
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
            ->add('tipoimporte', null, [
                'label' => 'Tipo de importe'
            ])
            ->add('moneda')
            ->add('monto')
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
        $ahora = new \DateTime('now');
        $formMapper
            ->add('tipoimporte', null, [
                'label' => 'Tipo de importe'
            ])
            ->add('moneda')
            ->add('monto')
            ->add('nota')
        ;

        $widthModifier = function (FormInterface $form) {
            $ahora = new \DateTime('now');

            $form
                ->add('monto', null, [
                    'label' => 'Monto',
                    'attr' => [
                        'style' => 'min-width: 100px;'
                    ]
                ])
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
                    && $this->getRoot()->getClass() == 'App\Oweb\Entity\ReservaReserva'
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
            ->add('tipoimporte', null, [
                'label' => 'Tipo de importe'
            ])
            ->add('moneda')
            ->add('monto')
            ->add('nota')
        ;
    }
}
