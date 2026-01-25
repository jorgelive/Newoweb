<?php

namespace App\Oweb\Admin;

use App\Form\ReservaUnitCaracteristicaLinkType;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\Attribute\Required;

class ReservaUnitAdmin extends AbstractSecureAdmin
{
    /**
     * Servicio de seguridad inyectado por setter.
     */
    private Security $security;

    /**
     * Inyección segura usando atributos de Symfony 7.
     */
    #[Required]
    public function setSecurity(Security $security): void
    {
        $this->security = $security;
    }

    public function getModulePrefix(): string
    {
        return 'RESERVAS';
    }

    public function configure(): void
    {
        $this->classnameLabel = "Unidad";
    }

    /**
     * Configura los botones superiores.
     * Muestra el botón de login si es anónimo, o las acciones del negocio si está logueado.
     */
    protected function configureActionButtons(array $buttonList, string $action, ?object $object = null): array
    {
        $user = $this->security->getUser();

        if (!$user) {
            // Caso Anónimo: Limpiamos todo y mostramos botón de Login
            $buttonList = [];
            $buttonList['login_action'] = ['template' => 'oweb/admin/reserva_unit/adminview_button.html.twig'];
        } else {
            if ($action === 'resumen') {
                $buttonList['edit'] = ['template' => '@SonataAdmin/Button/edit_button.html.twig'];
                $buttonList['list'] = ['template' => '@SonataAdmin/Button/list_button.html.twig'];
                $buttonList['show'] = ['template' => '@SonataAdmin/Button/show_button.html.twig'];
            }elseif ($object && $object->getId()) {
                $buttonList['resumen'] = ['template' => 'oweb/admin/reserva_unit/resumen_button.html.twig'];
            }

        }

        return $buttonList;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('id')
            ->add('establecimiento')
            ->add('nombre')
            ->add('descripcion', TranslationFieldFilter::class, ['label' => 'Descripción'])
            ->add('referencia', TranslationFieldFilter::class, ['label' => 'Referencia de ubicación'])
        ;
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->add('id')
            ->add('establecimiento')
            ->add('nombre')
            ->add('descripcion', null, ['label' => 'Descripción', 'editable' => true])
            ->add('referencia', null, ['label' => 'Referencia de ubicación', 'editable' => true])
            ->add('unitCaracteristicaLinks', null, [
                'label' => 'Características (vínculos)',
                'associated_property' => function ($link) {
                    return sprintf('%s (p:%s)',
                        (string) $link->getCaracteristica(),
                        $link->getPrioridad() ?? '-'
                    );
                },
            ])
            ->add('unitnexos', null, ['label' => 'Nexos'])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'resumen' => ['template' => 'oweb/admin/reserva_unit/list__action_resumen.html.twig'],
                    'inventario' => ['template' => 'oweb/admin/reserva_unit/list__action_inventario.html.twig'],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => ['template' => 'oweb/admin/reserva_unit/list__action_traducir.html.twig'],
                ],
            ])
        ;
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('establecimiento')
            ->add('nombre')
            ->add('descripcion', null, ['label' => 'Descripción'])
            ->add('referencia', null, ['label' => 'Referencia de ubicación'])

            ->add('unitCaracteristicaLinks', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Características',
                'required' => false,
                'btn_add' => 'Agregar vínculo',
            ], [
                'edit' => 'inline',
                'inline' => 'table',
                'sortable' => 'prioridad',
            ])

            ->add('unitnexos', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Nexos',
                'required' => false,
                'btn_add' => 'Agregar nexo',
                'type_options' => ['delete' => true],
                'modifiable' => true,
            ], [
                'edit' => 'inline',
                'inline' => 'table',
                'sortable' => 'prioridad',
            ])
        ;
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('establecimiento')
            ->add('establecimiento.direccion', null, [
                'label' => 'Dirección',
                'template' => 'oweb/admin/base_sonata/show_map.html.twig',
                'zoom' => 17,
            ])
            ->add('nombre')
            ->add('descripcion', null, ['label' => 'Descripción'])
            ->add('referencia', null, ['label' => 'Referencia de ubicación'])
            ->add('unitCaracteristicaLinks', null, ['label' => 'Características'])
            ->add('unitnexos', null, ['label' => 'Nexos'])
        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('icalics', $this->getRouterIdParameter() . '/ical.ics');
        $collection->add('ical', $this->getRouterIdParameter() . '/ical');
        $collection->add('resumen', $this->getRouterIdParameter() . '/resumen');
        $collection->add('inventario', $this->getRouterIdParameter() . '/inventario');
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
    }

    protected function generateBaseRoutePattern(bool $isChildAdmin = false): string
    {
        return 'app/reservaunit';
    }
}