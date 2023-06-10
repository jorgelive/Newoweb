<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class CotizacionFiledocumentoAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('file')
            ->add('nombre')
            ->add('tipofiledocumento', null, [
                'label' => 'Tipo de documento'
            ])
            ->add('prioridad')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('file')
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('tipofiledocumento', null, [
                'label' => 'Tipo de documento',
                'sortable' => true,
                'sort_field_mapping' => ['fieldName' => 'nombre'],
                'sort_parent_association_mappings' => [['fieldName' => 'tipofiledocumento']],
            ])
            ->add('webThumbPath', FieldDescriptionInterface::TYPE_STRING, [
                    'label' => 'Archivo',
                    'template' => 'base_sonata_admin/list_image.html.twig'
                ]
            )
            ->add('prioridad', null, [
                'editable' => true
            ])
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
            ->add('tipofiledocumento', null, [
                'label' => 'Tipo de documento'
            ])
            ->add('prioridad')
            ->add('archivo', FileType::class, [
                'required' => false
            ])
        ;

        $widthModifier = function (FormInterface $form) {

            $form
                ->add('nombre', null, [
                    'label' => 'Nombre',
                    'attr' => [
                        'style' => 'width: 150px;',
                        'class' => 'uploadedimage'
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
            ->add('id')
            ->add('file')
            ->add('nombre')
            ->add('tipofiledocumento', null, [
                'label' => 'Tipo de documento'
            ])
            ->add('prioridad')
            ->add('webThumbPath', FieldDescriptionInterface::TYPE_STRING, [
                    'label' => 'Archivo',
                    'template' => 'base_sonata_admin/show_image.html.twig'
                ]
            )
        ;
    }

    public function prePersist($cotizacionfiledocumento): void
    {
        $this->manageFileUpload($cotizacionfiledocumento);
    }

    public function preUpdate($cotizacionfiledocumento): void
    {
        $this->manageFileUpload($cotizacionfiledocumento);
    }

    private function manageFileUpload($cotizacionfiledocumento)
    {
        if($cotizacionfiledocumento->getArchivo()) {
            $cotizacionfiledocumento->refreshModificado();
        }
    }

}
