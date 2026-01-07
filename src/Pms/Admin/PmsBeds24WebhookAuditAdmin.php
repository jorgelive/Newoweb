<?php
declare(strict_types=1);

namespace App\Pms\Admin;

use App\Pms\Entity\PmsBeds24WebhookAudit;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

final class PmsBeds24WebhookAuditAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'id';
        $sortValues['_sort_order'] = 'DESC';
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('id')
            ->add('status')
            ->add('eventType')
            ->add('remoteIp')
            ->add('receivedAt')
        ;
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('status')
            ->add('eventType')
            ->add('remoteIp')
            ->add('receivedAt', null, [
                'format' => 'Y/m/d H:i',
            ])
        ;
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('status')
            ->add('eventType')
            ->add('remoteIp')
            ->add('receivedAt', null, ['format' => 'Y/m/d H:i'])
            ->add('headers')
            ->add('payloadRaw')
            ->add('payload')
            ->add('processingMeta')
            ->add('errorMessage')
            ->add('updated', null, ['format' => 'Y/m/d H:i'])
        ;
    }

    protected function configureFormFields(FormMapper $form): void
    {
        // Normalmente solo lectura (audit), pero lo dejo editable por si necesitas “anotar”.
        $form
            ->add('status', ChoiceType::class, [
                'choices' => [
                    PmsBeds24WebhookAudit::STATUS_RECEIVED => PmsBeds24WebhookAudit::STATUS_RECEIVED,
                    PmsBeds24WebhookAudit::STATUS_PROCESSED => PmsBeds24WebhookAudit::STATUS_PROCESSED,
                    PmsBeds24WebhookAudit::STATUS_ERROR => PmsBeds24WebhookAudit::STATUS_ERROR,
                ],
                'required' => true,
            ])
            ->add('eventType', null, ['required' => false])
            ->add('remoteIp', null, ['required' => false])
            ->add('receivedAt', DateTimePickerType::class, [
                'required' => false,
                'format' => 'yyyy/MM/dd HH:mm',
            ])
            ->add('headers', TextareaType::class, [
                'required' => false,
                'help' => 'JSON headers (debug).',
            ])
            ->add('payloadRaw', TextareaType::class, [
                'required' => false,
                'help' => 'Body crudo (aunque no sea JSON válido).',
            ])
            ->add('payload', TextareaType::class, [
                'required' => false,
                'help' => 'Payload parseado (si fue JSON válido).',
            ])
            ->add('processingMeta', TextareaType::class, [
                'required' => false,
                'help' => 'Meta libre (jobId, bookingId, etc).',
            ])
            ->add('errorMessage', TextareaType::class, [
                'required' => false,
            ])
        ;
    }
}