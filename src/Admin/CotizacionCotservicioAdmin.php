<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;

use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class CotizacionCotservicioAdmin extends AbstractAdmin
{


    public function configure(): void
    {
        $this->setFormTheme([0 => 'cotizacion_cotservicio_admin/form_admin_fields.html.twig']);
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('fechahorainicio', null, [
                'label' => 'Inicio'
            ])
            ->add('servicio')
            ->add('fechahorafin', null, [
                'label' => 'Fin'
            ])
            ->add('cotizacion', null, [
                'label' => 'Cotización'
            ])
            ->add('itinerario')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('fechahorainicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('servicio')
            ->add('fechahorafin', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('cotizacion', null, [
                'label' => 'Cotización'
            ])
            ->add('itinerario')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => []
                ]
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        if ($this->getRoot()->getClass() != 'App\Entity\CotizacionFile'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCotizacion'
        ){
            $formMapper->add('cotizacion');
        }

        $formMapper
            ->add('servicio', ModelAutocompleteType::class, [
                'property' => 'nombre',
                'route' => ['name' => 'app_servicio_servicio_alldropdown', 'parameters' => []],
                'placeholder' => '',
                'minimum_input_length' => 0,
                'dropdown_auto_width' => false,
                'btn_add' => false
            ])
            ->add('itinerario', ModelAutocompleteType::class, [
                'property' => 'nombre',
                'template' => 'form/ajax_dropdown_type.html.twig',
                'route' => ['name' => 'app_servicio_itinerario_porserviciodropdown', 'parameters' => []],
                'placeholder' => '',
                'context' => '/\[itinerario\]$/g, "[servicio]"',
                'minimum_input_length' => 0,
                'dropdown_auto_width' => false,
                'btn_add' => false
            ])
            ->add('fechahorainicio', DateTimePickerType::class, [
                'label' => 'Inicio',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd HH:mm',
                'attr' => [
                    'class' => 'fechahora serviciofechainicio'
                ]
            ])
            ->add('fechahorafin', DateTimePickerType::class, [
                'label' => 'Fin',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd HH:mm',
                'attr' => [
                    'class' => 'fechahora serviciofechafin'
                ]
            ])
            ->add('cotcomponentes', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Componentes'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
        ;

        $horarioModifier = function (FormInterface $form, $duracion, $paraleloclass) {

            $form->add('fechahorainicio', DateTimePickerType::class, [
                    'label' => 'Inicio',
                    'dp_show_today' => true,
                    'format'=> 'yyyy/MM/dd HH:mm',
                    'attr' => [
                        'duracion' => $duracion,
                        'class' => 'fechahora serviciofechainicio' . $paraleloclass
                    ]
                ])
                ->add('fechahorafin', DateTimePickerType::class, [
                    'label' => 'Fin',
                    'dp_show_today' => true,
                    'format'=> 'yyyy/MM/dd HH:mm',
                    'attr' => [
                        'duracion' => $duracion,
                        'class' => 'fechahora serviciofechafin'
                    ]
                ])
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($horarioModifier) {
                $paraleloClass = '';
                if($event->getData()
                    && $event->getData()->getServicio()
                    && $event->getData()->getServicio()->getParalelo()
                ){
                    $paraleloClass = ' paralelo';
                }

                if($event->getData()
                    && $event->getData()->getItinerario()
                ){
                    $horarioModifier($event->getForm(), $event->getData()->getItinerario()->getDuracion(), $paraleloClass);
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
            ->add('fechahorainicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('servicio')
            ->add('fechahorafin', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('cotizacion')
            ->add('itinerario')
        ;
    }

}
