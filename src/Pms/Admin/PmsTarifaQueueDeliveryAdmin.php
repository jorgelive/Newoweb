<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsTarifaQueueDelivery;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\AdminBundle\Form\Type\ModelType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class PmsTarifaQueueDeliveryAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'effectiveAt';
        $sortValues['_sort_order'] = 'DESC';
    }


    public function configureQuery(ProxyQueryInterface $query): ProxyQueryInterface
    {
        $query = parent::configureQuery($query);

        $alias = (string) current($query->getRootAliases());

        // MySQL 8: DISTINCT + ORDER BY requiere que el ORDER BY esté en el SELECT
        $dqlParts = $query->getDQLPart('select');
        $alreadySelected = false;

        if (is_array($dqlParts)) {
            foreach ($dqlParts as $part) {
                $dql = method_exists($part, '__toString') ? (string) $part : '';
                if ($dql !== '' && str_contains($dql, $alias . '.effectiveAt')) {
                    $alreadySelected = true;
                    break;
                }
            }
        }

        if (!$alreadySelected) {
            $query->addSelect(sprintf('%s.effectiveAt AS HIDDEN effectiveAtSort', $alias));
        }

        return $query;
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Relaciones', ['class' => 'col-md-6'])
            ->add('queue', ModelType::class, [
                'label' => 'Queue',
                'btn_add' => false,
                'required' => false,
            ])
            ->add('unidadBeds24Map', ModelType::class, [
                'label' => 'Map Beds24',
                'btn_add' => false,
                'required' => false,
            ])
            ->add('beds24Config', ModelType::class, [
                'label' => 'Beds24 Config',
                'btn_add' => false,
                'required' => false,
            ])
            ->end()
            ->with('Estado', ['class' => 'col-md-6'])
            ->add('needsSync', CheckboxType::class, [
                'required' => false,
            ])
            ->add('status', TextType::class, [
                'required' => false,
            ])
            ->add('failedReason', TextType::class, [
                'required' => false,
            ])
            ->add('retryCount', IntegerType::class, [
                'required' => false,
            ])
            ->add('lastHttpCode', IntegerType::class, [
                'required' => false,
            ])
            ->add('nextRetryAt', DateTimePickerType::class, [
                'required' => false,
                'format' => 'yyyy/MM/dd HH:mm:ss',
            ])
            ->add('lockedAt', DateTimePickerType::class, [
                'required' => false,
                'format' => 'yyyy/MM/dd HH:mm:ss',
            ])
            ->add('processingStartedAt', DateTimePickerType::class, [
                'required' => false,
                'format' => 'yyyy/MM/dd HH:mm:ss',
            ])
            ->add('lockedBy', TextType::class, [
                'required' => false,
            ])
            ->add('lastSync', DateTimePickerType::class, [
                'required' => false,
                'format' => 'yyyy/MM/dd HH:mm:ss',
            ])
            ->add('effectiveAt', DateTimePickerType::class, [
                'required' => false,
                'format' => 'yyyy/MM/dd HH:mm:ss',
                'disabled' => true,
            ])
            ->end()
            ->with('Dedupe', ['class' => 'col-md-6'])
            ->add('dedupeKey', TextType::class, [
                'required' => false,
            ])
            ->add('payloadHash', TextType::class, [
                'required' => false,
            ])
            ->end()
            ->with('Logs', ['class' => 'col-md-12'])
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
            ->add('queue')
            ->add('unidadBeds24Map')
            ->add('beds24Config')
            ->add('needsSync')
            ->add('status')
            ->add('failedReason')
            ->add('dedupeKey')
            ->add('payloadHash')
            ->add('retryCount')
            ->add('lastHttpCode')
            ->add('lockedBy')
            ->add('lockedAt')
            ->add('processingStartedAt')
            ->add('nextRetryAt')
            ->add('lastSync')
            ->add('effectiveAt')
        ;
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('effectiveAt', null, ['format' => 'Y/m/d H:i:s'])
            ->add('queue')
            ->add('unidadBeds24Map')
            ->add('beds24Config')
            ->add('needsSync', null, ['editable' => true])
            ->add('status')
            ->add('failedReason')
            ->add('retryCount')
            ->add('lastHttpCode')
            ->add('nextRetryAt', null, ['format' => 'Y/m/d H:i:s'])
            ->add('lockedAt', null, ['format' => 'Y/m/d H:i:s'])
            ->add('processingStartedAt', null, ['format' => 'Y/m/d H:i:s'])
            ->add('lockedBy')
            ->add('lastSync', null, ['format' => 'Y/m/d H:i:s'])
            ->add('dedupeKey')
            ->add('payloadHash')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'actions' => [
                    'edit' => [],
                    'delete' => [],
                    'show' => [],
                ],
            ])
        ;
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('effectiveAt', null, ['format' => 'Y/m/d H:i:s'])
            ->add('queue')
            ->add('unidadBeds24Map')
            ->add('beds24Config')
            ->add('needsSync')
            ->add('status')
            ->add('failedReason')
            ->add('retryCount')
            ->add('lastHttpCode')
            ->add('nextRetryAt', null, ['format' => 'Y/m/d H:i:s'])
            ->add('lockedAt', null, ['format' => 'Y/m/d H:i:s'])
            ->add('processingStartedAt', null, ['format' => 'Y/m/d H:i:s'])
            ->add('lockedBy')
            ->add('lastSync', null, ['format' => 'Y/m/d H:i:s'])
            ->add('dedupeKey')
            ->add('payloadHash')
            ->add('lastMessage')
            ->add('lastRequestJson')
            ->add('lastResponseJson')
            ->add('created', null, ['format' => 'Y/m/d H:i:s'])
            ->add('updated', null, ['format' => 'Y/m/d H:i:s'])
        ;
    }
}