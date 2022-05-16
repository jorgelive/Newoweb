<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\DatePickerType;

use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class FitAlimentoAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('nombre')
            ->add('grasa')
            ->add('carbohidrato')
            ->add('proteina')

            ->add('proteinaaltovalor', null, [
                'label' => 'Proteina de alto valor'
            ])
            ->add('cantidad')
            ->add('medidaalimento', null, [
                'label' => 'Medida de alimento'
            ])
            ->add('tipoalimento', null, [
                'label' => 'Típo de alimento'
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
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('grasa', 'decimal', [
                'editable' => true,
                'row_align' => 'right'
            ])
            ->add('carbohidrato', 'decimal', [
                'editable' => true,
                'row_align' => 'right'
            ])
            ->add('proteina', 'decimal', [
                'editable' => true,
                'row_align' => 'right'
            ])
            ->add('proteinaaltovalor', null, [
                'editable' => true,
                'row_align' => 'center',
                'label' => 'Proteina de alto valor'
            ])
            ->add('cantidad', 'decimal', [
                'editable' => true,
                'row_align' => 'right'
            ])
            ->add('medidaalimento', null, [
                'label' => 'Medida de alimento'
            ])
            ->add('tipoalimento', null, [
                'label' => 'Típo de alimento'
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

        $formMapper
            ->add('nombre')
            ->add('grasa')
            ->add('carbohidrato')
            ->add('proteina')
            ->add('proteinaaltovalor', null, [
                'label' => 'Proteina de alto valor'
            ])
            ->add('cantidad')
            ->add('medidaalimento', null, [
                'label' => 'Medida de alimento'
            ])
            ->add('tipoalimento', null, [
                'label' => 'Típo de alimento'
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
            ->add('grasa')
            ->add('carbohidrato')
            ->add('proteina')
            ->add('proteinaaltovalor', null, [
                'label' => 'Proteina de alto valor'
            ])
            ->add('cantidad')
            ->add('medidaalimento', null, [
                'label' => 'Medida de alimento'
            ])
            ->add('tipoalimento', null, [
                'label' => 'Típo de alimento'
            ])
        ;
    }
}
