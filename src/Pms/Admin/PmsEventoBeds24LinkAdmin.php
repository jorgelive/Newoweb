<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsEventoBeds24Link;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class PmsEventoBeds24LinkAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'id';
        $sortValues['_sort_order'] = 'DESC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Principal', ['class' => 'col-md-8'])
            ->add('evento', ModelType::class, [
                'label' => 'Evento',
                'btn_add' => false,
            ])
            ->add('unidadBeds24Map', ModelType::class, [
                'label' => 'Map (Beds24)',
                'btn_add' => false,
            ])
            ->add('beds24BookId', TextType::class, [
                'required' => false,
                'label' => 'Beds24 bookId',
                'help' => 'ID técnico (único) de Beds24 para esta representación.',
            ])
            ->add('originLink', ModelType::class, [
                'required' => false,
                'label' => 'Origin Link (si es derivado)',
                'btn_add' => false,
            ])
            ->add('status', ChoiceType::class, [
                'required' => true,
                'label' => 'Estado',
                'choices' => [
                    'Active' => PmsEventoBeds24Link::STATUS_ACTIVE,
                    'Detached' => PmsEventoBeds24Link::STATUS_DETACHED,
                    'Pending Delete' => PmsEventoBeds24Link::STATUS_PENDING_DELETE,
                    'Pending Move' => PmsEventoBeds24Link::STATUS_PENDING_MOVE,
                    'Synced Deleted' => PmsEventoBeds24Link::STATUS_SYNCED_DELETED,
                ],
                'help' => 'Estado histórico del link para reconciliación/sync con Beds24.',
            ])
            ->end()
            ->with('Auditoría', ['class' => 'col-md-4'])
            ->add('lastSeenAt', DateTimePickerType::class, [
                'required' => false,
                'label' => 'Last Seen',
                'format' => 'yyyy/MM/dd HH:mm',
            ])
            ->add('deactivatedAt', DateTimePickerType::class, [
                'required' => false,
                'label' => 'Deactivated At',
                'format' => 'yyyy/MM/dd HH:mm',
            ])
            ->add('created', DateTimePickerType::class, [
                'required' => false,
                'label' => 'Creado',
                'format' => 'yyyy/MM/dd HH:mm',
                'disabled' => true,
            ])
            ->add('updated', DateTimePickerType::class, [
                'required' => false,
                'label' => 'Actualizado',
                'format' => 'yyyy/MM/dd HH:mm',
                'disabled' => true,
            ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('evento')
            ->add('unidadBeds24Map')
            ->add('beds24BookId')
            ->add('originLink')
            ->add('status')
            ->add('lastSeenAt')
            ->add('deactivatedAt');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('evento')
            ->add('unidadBeds24Map')
            ->add('beds24BookId')
            ->add('originLink')
            ->add('status')
            ->add('lastSeenAt', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('deactivatedAt', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('created', null, [
                'format' => 'Y/m/d H:i',
            ])
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
            ->add('evento')
            ->add('unidadBeds24Map')
            ->add('beds24BookId')
            ->add('originLink')
            ->add('status')
            ->add('lastSeenAt', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('deactivatedAt', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('created', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('updated', null, [
                'format' => 'Y/m/d H:i',
            ])
        ;
    }
}