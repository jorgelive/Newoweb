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
use Sonata\Form\Type\CollectionType;

class CotizacionCottarifaAdmin extends AbstractAdmin
{
    public function configure(): void
    {
        $this->classnameLabel = "Tarifa";
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('cantidad')
            ->add('cotcomponente',  null, [
                'label' => 'Componente'
            ])
            ->add('tarifa')
            ->add('moneda')
            ->add('monto')
            ->add('tipotarifa',  null, [
                'label' => 'Tipo'
            ])
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
            ->add('cotcomponente',  null, [
                'label' => 'Componente'
            ])
            ->add('tarifa')
            ->add('moneda')
            ->add('monto')
            ->add('tipotarifa',  null, [
                'label' => 'Tipo'
            ])
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
        if ($this->getRoot()->getClass() != 'App\Entity\CotizacionFile'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCotizacion'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCotservicio'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCotcomponente'
        ){
            $formMapper->add('cotcomponente',  null, [
                'label' => 'Componente'
            ]);
        }

        $formMapper
            ->add('tarifa', ModelAutocompleteType::class, [
                'property' => 'nombre',
                'template' => '/form/type/ajax_dropdown_type_cotizacion_cottarifa.html.twig',
                'route' => ['name' => 'app_servicio_tarifa_porcomponentedropdown', 'parameters' => []],
                'placeholder' => '',
                'context' => '/\[cottarifas\]\[\d\]\[tarifa\]$/g, "[componente]"',
                'minimum_input_length' => 0,
                'dropdown_auto_width' => false,
                'btn_add' => false
            ])
            ->add('cantidad')
            ->add('moneda')
            ->add('monto')
            ->add('tipotarifa',  null, [
                'label' => 'Tipo'
            ])
            ->add('cottarifadetalles', CollectionType::class , [
                'by_reference' => false,
                'label' => 'Detalles'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
        ;

        $cantidadModifier = function (FormInterface $form, $clases) {

            $form->add(
                'cantidad',
                null,
                [
                    'label' => 'Cantidad',
                    'attr' => ['class' => $clases]
                ]
            );
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($cantidadModifier) {

                if($event->getData()
                    && $event->getData()->getTarifa()
                    && $event->getData()->getTarifa()->isProrrateado() === true
                ){
                    if($event->getData()->getTarifa()->getCapacidadmax() == 1){
                        $clases = 'prorrateado inputwarning';
                    }else{
                        $clases = 'prorrateado readonly';
                    }

                    //var_dump($event->getData()->getComponente()->getTipocomponente()->isDependeduracion());
                    $cantidadModifier($event->getForm(), $clases);
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
            ->add('cotcomponente',  null, [
                'label' => 'Componente'
            ])
            ->add('tarifa')
            ->add('cantidad')
            ->add('moneda')
            ->add('monto')
            ->add('tipotarifa',  null, [
                'label' => 'Tipo'
            ])
        ;
    }
}
