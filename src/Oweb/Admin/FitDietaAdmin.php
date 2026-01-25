<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\Form\Type\DatePickerType;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;

class FitDietaAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'MAESTROS';
    }

    public array $vars = [];

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'DESC';
        $sortValues[DatagridInterface::SORT_BY] = 'modificado';
    }

    public function configure(): void
    {
        $this->setFormTheme([0 => 'oweb/admin/fit_dieta/form_admin_fields.html.twig']);
    }


    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('user', null, [
                'label' => 'Usuario'
            ])
            ->add('tipodieta', null, [
                'label' => 'Tipo de dieta'
            ])
            ->add('nombre')
            ->add('peso', null, [
                'label' => 'Peso'
            ])
            ->add('indicedegrasa', null, [
                'label' => 'Indice de grasa'
            ])
            ->add('fecha', null, [
                'label' => 'Fecha'
            ])

        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('user', null, [
                'label' => 'Usuario'
            ])
            ->add('tipodieta', null, [
                'label' => 'Tipo de dieta'
            ])
            ->add('nombre', null, [
                'editable' => true
            ])
            ->add('totalCalorias', null, [
                'label' => 'Tot cal',
                'row_align' => 'right'
            ])
            ->add('proteinaTotalPorKilogramo', null, [
                'label' => 'Prot por kg',
                'row_align' => 'right'
            ])
            ->add('peso', 'decimal', [
                'editable' => true,
                'label' => 'Peso',
                'row_align' => 'right'
            ])
            ->add('indicedegrasa', 'decimal', [
                'editable' => true,
                'label' => 'Indice de grasa',
                'row_align' => 'right'
            ])
            ->add('fecha', 'date', [
                'label' => 'Fecha',
                'editable' => true,
                'row_align' => 'right',
                'format' => 'Y/m/d'
            ])
            ->add('modificado', null, [
                'label' => 'Modificación',
                'format' => 'Y/m/d'
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'resumen' => [
                        'template' => 'oweb/admin/fit_dieta/list__action_resumen.html.twig'
                    ],
                    'clonar' => [
                        'template' => 'oweb/admin/fit_dieta/list__action_clonar.html.twig'
                    ]
                ]
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper
            ->add('user', null, [
                'label' => 'Usuario'
            ])
            ->add('tipodieta', null, [
                'label' => 'Tipo de dieta'
            ])
            ->add('nombre')
            ->add('peso', null, [
                'label' => 'Peso',
                'attr' => ['class' =>'campo-peso']
            ])
            ->add('indicedegrasa', null, [
                'label' => 'Indice de grasa',
                'attr' => ['class' =>'campo-indicedegrasa']
            ])
            ->add('fecha', DatePickerType::class, [
                'label' => 'Fecha',
                'dp_use_current' => true,
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd'
            ])
            ->add('dietacomidas', CollectionType::class , [
                'by_reference' => false,
                'label' => 'Comidas'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
        ;

        $this->vars['dietaalimentos']['alimentopath'] = 'api_oweb_fit_alimento_ajaxinfo';
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('user', null, [
                'label' => 'Usuario'
            ])
            ->add('tipodieta', null, [
                'label' => 'Tipo de dieta'
            ])
            ->add('nombre')
            ->add('peso', null, [
                'label' => 'Peso'
            ])
            ->add('indicedegrasa', null, [
                'label' => 'Indice de grasa'
            ])
            ->add('fecha', null, [
                'label' => 'Fecha'
            ])


        ;
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('resumen', $this->getRouterIdParameter() . '/resumen/{token}');
        $collection->add('clonar', $this->getRouterIdParameter() . '/clonar');
    }

    protected function generateBaseRoutePattern(bool $isChildAdmin = false): string
    {
        return 'app/fitdieta';
    }
}
