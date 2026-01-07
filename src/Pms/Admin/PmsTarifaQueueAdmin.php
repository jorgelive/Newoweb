<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsTarifaQueue;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Sonata\Form\Type\DatePickerType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsTarifaQueueAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'effectiveAt';
        $sortValues['_sort_order'] = 'DESC';
    }

    /**
     * Ajustes al query de listado.
     *
     * Nota: en Sonata 4/5, AbstractAdmin::createQuery() es final (no se puede override).
     * Para modificar el query del datagrid/list, se usa configureQuery().
     */

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
        /** @var PmsTarifaQueue|null $subject */
        $subject = $this->getSubject();

        $form
            ->with('Relaciones', ['class' => 'col-md-6'])
                ->add('tarifaRango', ModelType::class, [
                    'label' => 'Rango de tarifa',
                    'btn_add' => false,
                ])
                ->add('unidad', ModelType::class, [
                    'label' => 'Unidad',
                    'btn_add' => false,
                ])
                ->add('endpoint', ModelType::class, [
                    'label' => 'Endpoint',
                    'btn_add' => false,
                ])
                ->add('fechaInicio', DatePickerType::class, [
                    'required' => false,
                    'format' => 'yyyy/MM/dd',
                ])
                ->add('fechaFin', DatePickerType::class, [
                    'required' => false,
                    'format' => 'yyyy/MM/dd',
                ])
                ->add('effectiveAt', DateTimePickerType::class, [
                    'label' => 'Effective at',
                    'required' => false,
                    'format' => 'yyyy/MM/dd HH:mm',
                    'disabled' => true,
                    'help' => 'Calculado desde los deliveries (para ordenar el procesamiento).',
                    // Si no existe getter, evitamos romper el formulario.
                    'data' => ($subject && method_exists($subject, 'getEffectiveAt')) ? $subject->getEffectiveAt() : null,
                    'mapped' => false,
                ])
                ->add('precio', TextType::class, [
                    'required' => false,
                ])
                ->add('minStay', IntegerType::class, [
                    'required' => false,
                ])
                ->add('moneda', ModelType::class, [
                    'required' => false,
                    'btn_add' => false,
                ])
            ->end()
            ->with('Estado de sync', ['class' => 'col-md-6'])
                ->add('needsSync', CheckboxType::class, [
                    'required' => false,
                ])
                ->add('status', TextType::class, [
                    'required' => false,
                ])
                ->add('dedupeKey', TextType::class, [
                    'required' => false,
                ])
            ->end()
            ->with('Deliveries', ['class' => 'col-md-12'])
                ->add('deliveries', null, [
                    'required' => false,
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('tarifaRango')
            ->add('unidad')
            ->add('fechaInicio')
            ->add('fechaFin')
            ->add('effectiveAt')
            ->add('minStay')
            ->add('moneda')
            ->add('endpoint')
            ->add('needsSync')
            ->add('status')
            ->add('dedupeKey');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('tarifaRango')
            ->add('unidad')
            ->add('fechaInicio', null, ['format' => 'Y/m/d'])
            ->add('fechaFin', null, ['format' => 'Y/m/d'])
            ->add('effectiveAt', null, [
                'label' => 'Effective at',
                'sortable' => true,
                'format' => 'Y/m/d H:i:s',
            ])
            ->add('precio')
            ->add('minStay')
            ->add('moneda')
            ->add('endpoint')
            ->add('needsSync', null, ['editable' => true])
            ->add('status')
            ->add('dedupeKey')
            ->add('deliveries')
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
            ->add('unidad')
            ->add('fechaInicio', null, ['format' => 'Y/m/d'])
            ->add('fechaFin', null, ['format' => 'Y/m/d'])
            ->add('effectiveAt', null, [
                'format' => 'Y/m/d H:i:s',
            ])
            ->add('precio')
            ->add('minStay')
            ->add('moneda')
            ->add('endpoint')
            ->add('needsSync')
            ->add('status')
            ->add('dedupeKey')
            ->add('deliveries');
    }
}
