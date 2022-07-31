<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Route\RouteCollection;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Symfony\Component\Form\Extension\Core\Type\FileType;

class ReservaUnitmedioAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('unit', null, [
                'label' => 'Unidad'
            ])
            ->add('unitclasemedio', null, [
                    'label' => 'Clase'
                ]
            )
            ->add('nombre')
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título'
            ])
            ->add('enlace')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('unit', null, [
                'label' => 'Unidad'
            ])
            ->add('unitclasemedio', null, [
                    'label' => 'Clase'
                ]
            )
            ->add('webThumbPath', 'string', [
                    'label' => 'Archivo',
                    'template' => 'base_sonata_admin/list_image.html.twig'
                ]
            )
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('titulo', null, [
                'label' => 'Título',
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

        $formMapper
            ->add('unit', null, [
                'label' => 'Unidad'
            ])
            ->add('unitclasemedio', null, [
                    'label' => 'Clase'
            ])
            ->add('nombre', null, [
                'attr' => ['class' => 'uploadedimage']
            ])
            ->add('titulo', null, [
                'label' => 'Título'
            ])
            ->add('enlace')
            ->add('archivo', FileType::class, [
                'required' => false
            ])
        ;
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('unit', null, [
                'label' => 'Unidad'
            ])
            ->add('webThumbPath', null, [
                    'label' => 'Archivo',
                    'template' => 'base_sonata_admin/show_image.html.twig'
                ]
            )
            ->add('enlace')
            ->add('unitclasemedio', null, [
                    'label' => 'Clase'
                ]
            )
            ->add('nombre')
            ->add('titulo', null, [
                'label' => 'Título'
            ])
        ;
    }

    public function prePersist($unitmedio): void
    {
        $this->manageFileUpload($unitmedio);
    }

    public function preUpdate($unitmedio): void
    {
        $this->manageFileUpload($unitmedio);
    }

    private function manageFileUpload($unitmedio): void
    {
        if ($unitmedio->getArchivo()) {
            $unitmedio->refreshModificado();
        }
    }

}
