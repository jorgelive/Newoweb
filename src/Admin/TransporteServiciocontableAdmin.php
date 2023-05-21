<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Route\RouteCollection;
use Sonata\Form\Type\DatePickerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Sonata\DoctrineORMAdminBundle\Filter\CallbackFilter;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Sonata\AdminBundle\Datagrid\DatagridInterface;

class TransporteServiciocontableAdmin extends AbstractAdmin
{

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'ASC';
        $sortValues[DatagridInterface::SORT_BY] = 'servicio.fechahorainicio';
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('servicio.dependencia', null, [
                'label' => 'Cliente',
            ])
            ->add('servicio.nombre', null, [
                'label' => 'Servicio'
            ])
            ->add('servicio.fechahorainicio', CallbackFilter::class,[
                'label' => 'Fecha de servicio',
                'callback' => function($queryBuilder, $alias, $field, $value) {

                    if(!$value['value'] || !($value['value'] instanceof \DateTime)) {
                        return;
                    }
                    $fechaMasUno = clone ($value['value']);
                    $fechaMasUno->add(new \DateInterval('P1D'));

                    if(empty($value['type'])){
                        $queryBuilder->andWhere("DATE($alias.$field) >= :fechahora");
                        $queryBuilder->andWhere("DATE($alias.$field) < :fechahoraMasUno");
                        $queryBuilder->setParameter('fechahora', $value['value']);
                        $queryBuilder->setParameter('fechahoraMasUno', $fechaMasUno);
                        return true;
                    } elseif($value['type'] == 1){
                        $queryBuilder->andWhere("DATE($alias.$field) >= :fechahora");
                        $queryBuilder->setParameter('fechahora', $value['value']);
                        return true;
                    } elseif($value['type'] == 2){
                        $queryBuilder->andWhere("DATE($alias.$field) < :fechahoraMasUno");
                        $queryBuilder->setParameter('fechahoraMasUno', $fechaMasUno);
                        return true;
                    } elseif($value['type'] == 3){
                        $queryBuilder->andWhere("DATE($alias.$field) >= :fechahoraMasUno");
                        $queryBuilder->setParameter('fechahoraMasUno', $fechaMasUno);
                        return true;
                    } elseif($value['type'] == 4){
                        $queryBuilder->andWhere("DATE($alias.$field) < :fechahora");
                        $queryBuilder->setParameter('fechahora', $value['value']);
                        return true;
                    }
                    return;
                },
                'field_type' => DatePickerType::class,
                'field_options' => [
                    'dp_use_current' => true,
                    'dp_show_today' => true,
                    'format'=> 'yyyy/MM/dd'
                ],
                'operator_type' => ChoiceType::class,
                'operator_options' => array(
                    'choices' => array(
                        'Igual a' => 0,
                        'Mayor o igual a' => 1,
                        'Menor o igual a' => 2,
                        'Mayor a' => 3,
                        'Menor a' => 4
                    )
                )
            ])
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('total')
            ->add('comprobante')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('servicio')
            ->add('servicio.dependencia', null, [
                'label' => 'Cliente',
            ])
            ->add('servicio.fechahorainicio', null, [
                'label' => 'Fecha servicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('total')
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
        if($this->getRoot()->getClass() != 'App\Entity\TransporteServicio'){
            $formMapper->add('servicio');
        }
        $formMapper
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('total')
        ;

        if($this->getRoot()->getClass() != 'App\Entity\TransporteComprobante'){
            $formMapper->add('comprobante');
        }

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('descripcion', null, [
                    'label' => 'Descripción',
                    'attr' => [
                        'style' => 'width: 200px;'
                    ]
                ])
                ->add('total', null, [
                    'label' => 'Total',
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
                    && $this->getRoot()->getClass() == 'App\Entity\TransporteServicio'
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
            ->add('servicio.dependencia', null, [
                'label' => 'Cliente',
            ])
            ->add('servicio')
            ->add('servicio.fechahorainicio', null, [
                'label' => 'Fecha servicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('total')
            ->add('comprobante')
        ;
    }


}
