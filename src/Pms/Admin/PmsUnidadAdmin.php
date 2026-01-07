<?php
declare(strict_types=1);

namespace App\Pms\Admin;

use App\Pms\Entity\PmsUnidad;
use App\Entity\MaestroMoneda;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Sonata\Form\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsUnidadAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'nombre';
        $sortValues['_sort_order'] = 'ASC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('General', ['class' => 'col-md-8'])
                ->add('establecimiento', ModelType::class, [
                    'label' => 'Establecimiento',
                    'required' => true,
                    'btn_add' => false,
                ])
                ->add('nombre', TextType::class, [
                    'required' => true,
                ])
                ->add('codigoInterno', TextType::class, [
                    'required' => false,
                ])
                ->add('capacidad', IntegerType::class, [
                    'required' => false,
                ])
            ->end()
            ->with('Estado', ['class' => 'col-md-4'])
                ->add('activo', CheckboxType::class, [
                    'required' => false,
                ])
            ->end()
            ->with('Tarifa base', ['class' => 'col-md-12'])
                ->add('tarifaBaseActiva', CheckboxType::class, [
                    'label' => 'Tarifa base activa',
                    'required' => false,
                ])
                ->add('tarifaBasePrecio', MoneyType::class, [
                    'label' => 'Precio base',
                    'required' => true,
                    'currency' => false,
                    'scale' => 2,
                ])
                ->add('tarifaBaseMinStay', IntegerType::class, [
                    'label' => 'Min. stay base',
                    'required' => true,
                ])
                ->add('tarifaBaseMoneda', ModelType::class, [
                    'label' => 'Moneda base',
                    'required' => true,
                    'class' => MaestroMoneda::class,
                    'btn_add' => false,
                ])
            ->end()
            ->with('Beds24', ['class' => 'col-md-12'])
                ->add('beds24Maps', CollectionType::class, [
                    'label' => 'Maps Beds24',
                    'required' => false,
                    'by_reference' => false,
                ], [
                    'edit' => 'inline',
                    'inline' => 'table',
                    'sortable' => 'id',
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('establecimiento')
            ->add('nombre')
            ->add('codigoInterno')
            ->add('activo')
            ->add('tarifaBaseActiva')
            ->add('tarifaBasePrecio')
            ->add('tarifaBaseMinStay')
            ->add('tarifaBaseMoneda')
            ->add('beds24Maps.beds24Config');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('nombre')
            ->add('establecimiento')
            ->add('codigoInterno')
            ->add('capacidad')
            ->add('tarifaBaseActiva', null, ['editable' => true])
            ->add('tarifaBasePrecio')
            ->add('tarifaBaseMinStay')
            ->add('tarifaBaseMoneda')
            ->add('activo', null, ['editable' => true])
            ->add('beds24Maps', null, [
                'label' => 'Beds24 Maps'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'actions' => [
                    'edit' => [],
                    'delete' => [],
                ],
            ]);
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('establecimiento')
            ->add('nombre')
            ->add('codigoInterno')
            ->add('capacidad')
            ->add('activo')
            ->add('tarifaBaseActiva')
            ->add('tarifaBasePrecio')
            ->add('tarifaBaseMinStay')
            ->add('tarifaBaseMoneda')
            ->add('beds24Maps', null, [
                'label' => 'Beds24 Maps',
            ]);
    }
}
