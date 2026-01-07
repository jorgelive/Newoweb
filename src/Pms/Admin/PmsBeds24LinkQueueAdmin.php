<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsBeds24LinkQueue;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsBeds24LinkQueueAdmin extends AbstractAdmin
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
                ->add('link', ModelType::class, [
                    'label' => 'Beds24 Link',
                    'btn_add' => false,
                    'required' => false,
                ])
                ->add('linkIdOriginal', IntegerType::class, [
                    'label' => 'Link ID (audit)',
                    'required' => false,
                    'disabled' => true,
                ])
                ->add('beds24BookIdOriginal', TextType::class, [
                    'label' => 'Beds24 Book ID (audit)',
                    'required' => false,
                    'disabled' => true,
                ])
                ->add('endpoint', ModelType::class, [
                    'label' => 'Endpoint',
                    'btn_add' => false,
                ])
                ->add('beds24Config', ModelType::class, [
                    'label' => 'Beds24 Config',
                    'btn_add' => false,
                    'required' => false,
                ])
            ->end()
            ->with('Estado de sync', ['class' => 'col-md-6'])
                ->add('needsSync', CheckboxType::class, [
                    'required' => false,
                ])
                ->add('status', TextType::class, [
                    'required' => false,
                    'disabled' => true,
                ])
                ->add('failedReason', TextType::class, [
                    'label' => 'Failed Reason',
                    'required' => false,
                    'disabled' => true,
                ])
                ->add('retryCount', IntegerType::class, [
                    'required' => false,
                    'disabled' => true,
                ])
                ->add('nextRetryAt', DateTimePickerType::class, [
                    'required' => false,
                    'disabled' => true,
                    'format' => 'yyyy/MM/dd HH:mm',
                ])
                ->add('lockedAt', DateTimePickerType::class, [
                    'required' => false,
                    'disabled' => true,
                    'format' => 'yyyy/MM/dd HH:mm',
                ])
                ->add('processingStartedAt', DateTimePickerType::class, [
                    'label' => 'Processing started at',
                    'required' => false,
                    'disabled' => true,
                    'format' => 'yyyy/MM/dd HH:mm',
                ])
                ->add('lockedBy', TextType::class, [
                    'required' => false,
                    'disabled' => true,
                ])
                ->add('lastHttpCode', IntegerType::class, [
                    'required' => false,
                ])
                ->add('dedupeKey', TextType::class, [
                    'required' => false,
                    'disabled' => true,
                ])
                ->add('payloadHash', TextType::class, [
                    'required' => false,
                    'disabled' => true,
                ])
                ->add('lastSync', DateTimePickerType::class, [
                    'required' => false,
                    'format' => 'yyyy/MM/dd HH:mm',
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
                ->add('created', DateTimePickerType::class, [
                    'required' => false,
                    'disabled' => true,
                    'format' => 'yyyy/MM/dd HH:mm',
                ])
                ->add('updated', DateTimePickerType::class, [
                    'required' => false,
                    'disabled' => true,
                    'format' => 'yyyy/MM/dd HH:mm',
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('link')
            ->add('linkIdOriginal')
            ->add('beds24BookIdOriginal')
            ->add('endpoint')
            ->add('beds24Config')
            ->add('status')
            ->add('failedReason')
            ->add('needsSync')
            ->add('dedupeKey')
            ->add('payloadHash')
            ->add('retryCount')
            ->add('nextRetryAt')
            ->add('lockedAt')
            ->add('processingStartedAt')
            ->add('lockedBy')
            ->add('lastHttpCode');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('link')
            ->add('linkIdOriginal')
            ->add('beds24BookIdOriginal')
            ->add('endpoint')
            ->add('beds24Config')
            ->add('status')
            ->add('failedReason')
            ->add('needsSync', null, ['editable' => true])
            ->add('retryCount')
            ->add('nextRetryAt', null, ['format' => 'Y/m/d H:i'])
            ->add('lockedAt', null, ['format' => 'Y/m/d H:i'])
            ->add('processingStartedAt', null, ['format' => 'Y/m/d H:i'])
            ->add('lockedBy')
            ->add('lastHttpCode')
            ->add('lastSync', null, ['format' => 'Y/m/d H:i'])
            ->add('dedupeKey')
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
            ->add('link')
            ->add('linkIdOriginal')
            ->add('beds24BookIdOriginal')
            ->add('endpoint')
            ->add('beds24Config')
            ->add('needsSync')
            ->add('status')
            ->add('failedReason')
            ->add('retryCount')
            ->add('nextRetryAt', null, ['format' => 'Y/m/d H:i'])
            ->add('lockedAt', null, ['format' => 'Y/m/d H:i'])
            ->add('processingStartedAt', null, ['format' => 'Y/m/d H:i'])
            ->add('lockedBy')
            ->add('lastHttpCode')
            ->add('dedupeKey')
            ->add('payloadHash')
            ->add('lastSync', null, ['format' => 'Y/m/d H:i'])
            ->add('lastMessage')
            ->add('lastRequestJson')
            ->add('lastResponseJson')
            ->add('created', null, ['format' => 'Y/m/d H:i'])
            ->add('updated', null, ['format' => 'Y/m/d H:i']);
    }
}
