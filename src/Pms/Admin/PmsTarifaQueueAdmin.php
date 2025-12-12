<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsTarifaQueue;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsTarifaQueueAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'id';
        $sortValues['_sort_order'] = 'DESC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Relaciones', ['class' => 'col-md-6'])
                ->add('tarifaRango', ModelType::class, [
                    'label' => 'Rango de tarifa',
                    'btn_add' => false,
                ])
                ->add('unidadBeds24', ModelType::class, [
                    'label' => 'Unidad Beds24',
                    'btn_add' => false,
                ])
                ->add('endpoint', ModelType::class, [
                    'label' => 'Endpoint',
                    'btn_add' => false,
                ])
            ->end()
            ->with('Estado de sync', ['class' => 'col-md-6'])
                ->add('needsSync', CheckboxType::class, [
                    'required' => false,
                ])
                ->add('retryCount', IntegerType::class, [
                    'required' => false,
                ])
                ->add('lastSync', DateTimeType::class, [
                    'required' => false,
                    'widget' => 'single_text',
                ])
                ->add('lastStatus', TextType::class, [
                    'required' => false,
                ])
                ->add('lastMessage', TextType::class, [
                    'required' => false,
                ])
                ->add('lastRequestJson', TextareaType::class, [
                    'required' => false,
                ])
                ->add('lastResponseJson', TextareaType::class, [
                    'required' => false,
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('tarifaRango')
            ->add('unidadBeds24')
            ->add('endpoint')
            ->add('needsSync')
            ->add('lastStatus');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('tarifaRango')
            ->add('unidadBeds24')
            ->add('endpoint')
            ->add('needsSync', null, ['editable' => true])
            ->add('retryCount')
            ->add('lastStatus')
            ->add('lastSync')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'actions' => [
                    'edit' => [],
                    'delete' => [],
                    'show' => [],
                ],
            ]);
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('tarifaRango')
            ->add('unidadBeds24')
            ->add('endpoint')
            ->add('needsSync')
            ->add('retryCount')
            ->add('lastSync')
            ->add('lastStatus')
            ->add('lastMessage')
            ->add('lastRequestJson')
            ->add('lastResponseJson');
    }
}
