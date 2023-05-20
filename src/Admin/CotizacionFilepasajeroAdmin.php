<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Route\RouteCollection;
use Sonata\Form\Type\DatePickerType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class CotizacionFilepasajeroAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('file')
            ->add('nombre')
            ->add('apellido')
            ->add('sexo')
            ->add('pais', null, [
                'label' => 'País'
            ])
            ->add('tipodocumento', null, [
                'label' => 'Tipo de documento'
            ])
            ->add('numerodocumento', null, [
                'label' => 'Número de Documento'
            ])
            ->add('fechanacimiento', null, [
                'label' => 'Fecha de nacimiento'
            ])
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('file', null, [
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'file']]
            ])
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('apellido', null, [
                'editable' => true
            ])
            ->add('sexo')
            ->add('pais', null, [
                'label' => 'País'
            ])
            ->add('tipodocumento', null, [
                'label' => 'Tipo de documento'
            ])
            ->add('numerodocumento', null, [
                'label' => 'Número de Documento',
                'row_align' => 'right',
                'editable' => true
            ])
            ->add('fechanacimiento', 'date', [
                'label' => 'Fecha de nacimiento',
                'editable' => true,
                'row_align' => 'right',
                'format' => 'Y/m/d'
            ])
            ->add('edad')
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

        if($this->getRoot()->getClass() != 'App\Entity\CotizacionFile'){
            $formMapper->add('file');
        }
        $formMapper
            ->add('nombre')
            ->add('apellido')
            ->add('sexo')
            ->add('pais', null, [
                'label' => 'País'
            ])
            ->add('tipodocumento', null, [
                'label' => 'Tipo de documento'
            ])
            ->add('numerodocumento', null, [
                'label' => 'Número de documento'
            ])
            ->add('fechanacimiento', DatePickerType::class, [
                'label' => 'Fecha de nacimiento',
                'dp_use_current' => true,
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd'
            ])
        ;

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('nombre', null, [
                    'label' => 'Nombre',
                    'attr' => [
                        'style' => 'width: 150px;'
                    ]
                ])
                ->add('apellido', null, [
                    'label' => 'Apellido',
                    'attr' => [
                        'style' => 'width: 150px;'
                    ]
                ])
                ->add('numerodocumento', null, [
                    'label' => 'Número de documento',
                    'attr' => [
                        'style' => 'width: 100px;'
                    ]
                ])
                ->add('fechanacimiento', DatePickerType::class, [
                    'label' => 'Fecha de nacimiento',
                    'dp_use_current' => true,
                    'dp_show_today' => true,
                    'format'=> 'yyyy/MM/dd',
                    'attr' => [
                        'style' => 'width: 100px;'
                    ]
                ])
            ;
        };

        $formBuilder = $formMapper->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($widthModifier) {
                if($event->getData()
                    && $this->getRoot()->getClass() == 'App\Entity\CotizacionFile'
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
            ->add('file')
            ->add('nombre')
            ->add('apellido')
            ->add('sexo')
            ->add('pais', null, [
                'label' => 'País'
            ])
            ->add('tipodocumento', null, [
                'label' => 'Tipo de documento'
            ])
            ->add('numerodocumento', null, [
                'label' => 'Número de Documento'
            ])
            ->add('fechanacimiento', null, [
                'label' => 'Fecha de nacimiento',
            ])
            ->add('edad')
        ;
    }

/*
    public function getDataSourceIterator()
        {
            $datasourceit = parent::getDataSourceIterator();
            $datasourceit->setDateTimeFormat('Y/m/d');
            return $datasourceit;
        }
*/



    public function configureExportFields():array
    {
        $fields = [
            'Nombre File' => 'file',
            'Nombre' => 'nombre',
            'Apellido' => 'apellido',
            'T D' => 'tipodocumento',
            'N Documento' => 'numerodocumento',
            'Sexo' => 'sexo',
            'Pais' => 'pais',
            'Edad' => 'edad',
            'F Nacimiento' => 'fechanacimiento'
        ];

        return $fields;
    }


    public function getExportFormats():array
    {
        return ['xlsx', 'xls', 'csv', 'json', 'xml'];
    }
}
