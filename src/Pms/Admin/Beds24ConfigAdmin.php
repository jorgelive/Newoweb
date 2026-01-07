<?php

namespace App\Pms\Admin;

use App\Pms\Entity\Beds24Config;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Sonata\Form\Type\DateTimePickerType;

class Beds24ConfigAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'id';
        $sortValues['_sort_order'] = 'ASC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('General', ['class' => 'col-md-8'])
                ->add('nombre', TextType::class, [
                    'required' => false,
                    'label' => 'Nombre interno',
                ])
                ->add('refreshToken', TextType::class, [
                    'required' => true,
                    'label' => 'Refresh Token',
                    'help' => 'Credencial principal (API v2). Con este refresh token se genera el auth token automáticamente. Debe usarse al menos 1 vez cada 30 días para mantenerlo vigente.',
                ])
                ->add('authToken', TextType::class, [
                    'required' => false,
                    'disabled' => true,
                    'label' => 'Auth Token (cache)',
                    'help' => 'Token de sesión generado automáticamente a partir del refresh token.',
                ])
                ->add('webhookToken', TextType::class, [
                    'required' => false,
                    'label' => 'Webhook Token',
                    'help' => 'Token secreto para enrutar/validar webhooks (ej: /pms/webhooks/beds24/{token}/bookings). Debe ser largo y no predecible. Si está vacío, no se podrá validar por token.',
                ])
            ->end()
            ->with('Estado', ['class' => 'col-md-4'])
                ->add('activo', CheckboxType::class, [
                    'required' => false,
                    'label' => 'Activo',
                ])
                ->add('authTokenExpiresAt', DateTimePickerType::class, [
                    'required' => false,
                    'label' => 'Auth token expira',
                    'format' => 'yyyy/MM/dd HH:mm',
                    'disabled' => true,
                    'help' => 'Se calcula automáticamente según expiresIn de Beds24. No se edita manualmente.',
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('nombre')
            ->add('webhookToken')
            ->add('activo');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('nombre')
            ->add('webhookToken')
            ->add('activo', null, ['editable' => true])
            ->add('authTokenExpiresAt', null, [
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
            ->add('nombre')
            ->add('refreshToken')
            ->add('authToken')
            ->add('webhookToken')
            ->add('authTokenExpiresAt', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('unidadMaps', null, [
                'label' => 'Unidades Beds24 (Maps)',
            ])
            ->add('activo');
    }
}
