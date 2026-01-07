<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsTarifaRango;
use App\Repository\MaestroMonedaRepository;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Sonata\Form\Type\DatePickerType;

class PmsTarifaRangoAdmin extends AbstractAdmin
{
    public function __construct(
        string $code,
        string $class,
        string $baseControllerName,
        private MaestroMonedaRepository $maestroMonedaRepository
    ) {
        parent::__construct($code, $class, $baseControllerName);
    }

    protected function createNewInstance(): object
    {
        $entity = new PmsTarifaRango();

        $usd = $this->maestroMonedaRepository->findUsd();
        if ($usd !== null) {
            $entity->setMoneda($usd);
        }

        return $entity;
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'fechaInicio';
        $sortValues['_sort_order'] = 'ASC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Rango', ['class' => 'col-md-6'])
                ->add('unidad', ModelType::class, [
                    'label' => 'Unidad',
                    'btn_add' => false,
                ])
                ->add('fechaInicio', DatePickerType::class, [
                    'format' => 'yyyy/MM/dd',
                ])
                ->add('fechaFin', DatePickerType::class, [
                    'format' => 'yyyy/MM/dd',
                ])
            ->end()
            ->with('Precio', ['class' => 'col-md-6'])
                ->add('moneda', ModelType::class, [
                    'label' => 'Moneda',
                    'btn_add' => false,
                ])
                ->add('precio', MoneyType::class, [
                    'currency' => false,
                    'divisor' => 1,
                ])
                ->add('minStay', IntegerType::class, [
                    'label' => 'Min. stay',
                    'required' => false,
                ])
                ->add('importante', CheckboxType::class, [
                    'required' => false,
                ])
                ->add('peso', IntegerType::class, [
                    'required' => false,
                ])
                ->add('activo', CheckboxType::class, [
                    'required' => false,
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('unidad.establecimiento', null, ['label' => 'Establecimiento'])
            ->add('unidad')
            ->add('moneda')
            ->add('fechaInicio')
            ->add('fechaFin')
            ->add('minStay')
            ->add('importante')
            ->add('activo')
            ->add('queues', null, ['label' => 'Queue']);
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('unidad')
            ->add('moneda')
            ->add('fechaInicio', null, ['format' => 'Y/m/d'])
            ->add('fechaFin', null, ['format' => 'Y/m/d'])
            ->add('minStay')
            ->add('precio')
            ->add('importante', null, ['editable' => true])
            ->add('peso')
            ->add('activo', null, ['editable' => true])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                ],
            ]);
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('unidad')
            ->add('moneda')
            ->add('fechaInicio', null, ['format' => 'Y/m/d'])
            ->add('fechaFin', null, ['format' => 'Y/m/d'])
            ->add('minStay')
            ->add('precio')
            ->add('importante')
            ->add('peso')
            ->add('activo')
            ->add('queues', null, ['label' => 'Queue']);
    }
}
