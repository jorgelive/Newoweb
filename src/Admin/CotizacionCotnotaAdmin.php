<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

class CotizacionCotnotaAdmin extends AbstractAdmin
{

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('titulo', TranslationFieldFilter::class)
            ->add('contenido', TranslationFieldFilter::class)
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('nombre')
            ->add('titulo')
            ->add('contenido', null, [
                'template' => 'base_sonata_admin/list_html.html.twig'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => [
                        'template' => 'cotizacion_cotnota_admin/list__action_traducir.html.twig'
                    ]
                ],
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper
            ->add('nombre')
            ->add('titulo')
            ->add('contenido', null, [
                'required' => false,
                'attr' => ['class' => 'ckeditor']
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
            ->add('nombre')
            ->add('titulo')
            ->add('contenido', null, [
                'safe' => true
            ])
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
    }
}
