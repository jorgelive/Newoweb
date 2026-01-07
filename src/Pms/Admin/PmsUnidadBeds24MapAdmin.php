<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsUnidadBeds24Map;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PmsUnidadBeds24MapAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'pmsUnidad';
        $sortValues['_sort_order'] = 'ASC';
    }



    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Relación', ['class' => 'col-md-8'])
                ->add('beds24Config', ModelType::class, [
                    'label' => 'Beds24 Config',
                    'btn_add' => false,
                ])
                ->add('pmsUnidad', ModelType::class, [
                    'label' => 'Unidad PMS',
                    'btn_add' => false,
                ])
                ->add('beds24PropertyId', IntegerType::class, [
                    'required' => false,
                    'label' => 'Beds24 Property ID',
                    'help' => 'PropertyId real de Beds24 asociado a este room/unit.',
                ])
                ->add('beds24RoomId', IntegerType::class, [
                    'label' => 'Beds24 Room ID',
                ])
                ->add('beds24UnitId', IntegerType::class, [
                    'required' => false,
                    'label' => 'Beds24 Unit ID (opcional)',
                    'help' => 'Opcional. Solo se utiliza si Beds24 está configurado para trabajar con Units (unidades físicas). En modo cantidad por habitación (Room-based), dejar vacío.',
                ])
                ->add('nota', TextType::class, [
                    'required' => false,
                ])
            ->end()
            ->with('Flags', ['class' => 'col-md-4'])
                ->add('activo', CheckboxType::class, [
                    'required' => false,
                    'label' => 'Activo',
                ])
                ->add('esPrincipal', CheckboxType::class, [
                    'required' => false,
                    'label' => 'Principal',
                    'help' => 'Solo puede existir una asignación PRINCIPAL por unidad. Si intentas marcar una segunda, el cambio será rechazado.',
                ])
            ->end()
        ;
    }


    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('beds24Config', null, ['label' => 'Beds24 Config'])
            ->add('beds24PropertyId', null, ['label' => 'Beds24 Property ID'])
            ->add('pmsUnidad.establecimiento', null, ['label' => 'Establecimiento'])
            ->add('pmsUnidad')
            ->add('beds24RoomId')
            ->add('activo')
            ->add('esPrincipal');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('pmsUnidad')
            ->add('pmsUnidad.establecimiento', null, ['label' => 'Establecimiento'])
            ->add('esPrincipal', null, [
                'label' => 'Principal',
                'sortable' => false,
            ])
            ->add('beds24Config')
            ->add('beds24PropertyId')
            ->add('beds24RoomId')
            ->add('beds24UnitId')
            ->add('activo')
            ->add('nota')
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
            ->add('pmsUnidad')
            ->add('pmsUnidad.establecimiento')
            ->add('esPrincipal', null, [
                'label' => 'Principal',
            ])
            ->add('beds24Config')
            ->add('beds24PropertyId')
            ->add('beds24RoomId')
            ->add('beds24UnitId')
            ->add('activo')
            ->add('nota');
    }
}
