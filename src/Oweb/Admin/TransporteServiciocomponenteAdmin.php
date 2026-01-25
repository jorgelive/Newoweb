<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class TransporteServiciocomponenteAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'OPERACIONES';
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper):void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('origen')
            ->add('destino')
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
            ->add('servicio')
            ->add('hora')
            ->add('nombre')
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('numadl', null, [
                'label' => 'Adultos'
            ])
            ->add('numchd', null, [
                'label' => 'Niños'
            ])
            ->add('origen')
            ->add('destino')
            ->add('nota')
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

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        if($this->getRoot()->getClass() != 'App\Oweb\Entity\TransporteServicio'){
            $formMapper->add('servicio');
        }
        $formMapper
            ->add('hora', null, [
                'attr' => ['class' => 'horadropdown']
            ])
            ->add('nombre')
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('numadl', null, [
                'label' => 'Adultos'
            ])
            ->add('numchd', null, [
                'label' => 'Niños'
            ])
            ->add('origen')
            ->add('destino')
            ->add('nota')
        ;

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('nombre', null, [
                    'label' => 'Nombre',
                    'attr' => [
                        'style' => 'width: 200px;'
                    ]
                ])
                ->add('codigo', null, [
                    'label' => 'Código',
                    'attr' => [
                        'style' => 'width: 120px;'
                    ]
                ])
                ->add('numadl', null, [
                    'label' => 'Adultos',
                    'attr' => [
                        'style' => 'width: 60px; text-align: right;'
                    ]
                ])
                ->add('numchd', null, [
                    'label' => 'Niños',
                    'attr' => [
                        'style' => 'width: 60px; text-align: right;'
                    ]
                ])
                ->add('origen', null, [
                    'label' => 'Origen',
                    'attr' => [
                        'style' => 'width: 120px;'
                    ]
                ])
                ->add('destino', null, [
                    'label' => 'Destino',
                    'attr' => [
                        'style' => 'width: 120px;'
                    ]
                ])
                ->add('nota', null, [
                    'label' => 'Nota',
                    'attr' => [
                        'style' => 'width: 160px;'
                    ]
                ])
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($widthModifier) {
                if($event->getData()
                    && $this->getRoot()->getClass() == 'App\Oweb\Entity\TransporteServicio'
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
            ->add('servicio')
            ->add('hora')
            ->add('nombre')
            ->add('codigo', null, [
                'label' => 'Código'
            ])
            ->add('numadl', null, [
                'label' => 'Adultos'
            ])
            ->add('numchd', null, [
                'label' => 'Niños'
            ])
            ->add('origen')
            ->add('destino')
            ->add('nota')
        ;
    }

}
