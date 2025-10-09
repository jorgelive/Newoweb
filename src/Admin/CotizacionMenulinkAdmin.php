<?php

namespace App\Admin;

use App\Entity\CotizacionMenu;
use App\Entity\CotizacionCotizacion;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;

final class CotizacionMenulinkAdmin extends AbstractAdmin
{
    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('id')
            ->add('menu')         // filtra por menú
            ->add('cotizacion')   // filtra por cotización
            ->add('posicion')
            ->add('modificado', 'doctrine_orm_datetime_range');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('menu', null, ['associated_property' => 'titulo'])
            ->add('cotizacion', null, ['associated_property' => 'nombre'])
            ->add('posicion', null, ['editable' => true]) // edición inline
            ->add('creado')
            ->add('modificado')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'actions' => ['show' => [], 'edit' => [], 'delete' => []],
            ]);
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Vínculo', ['class' => 'col-md-8'])
            ->add('menu', ModelAutocompleteType::class, [
                'property' => ['titulo', 'nombre'],
                'to_string_callback' => function (?CotizacionMenu $m): string {
                    return $m ? ($m->getTitulo() ?: $m->getNombre()) : '';
                },
                'minimum_input_length' => 1,
            ])
            ->add('cotizacion', ModelAutocompleteType::class, [
                'property' => ['nombre', 'id'],
                'to_string_callback' => function (?CotizacionCotizacion $c): string {
                    return $c ? ($c->getCodigo() ?: $c->getNombre() ?: (string)$c->getId()) : '';
                },
                'minimum_input_length' => 1,
            ])
            ->add('posicion')
            ->end()
            ->with('Metadatos', ['class' => 'col-md-4'])
            ->add('creado', null, ['disabled' => true, 'required' => false])
            ->add('modificado', null, ['disabled' => true, 'required' => false])
            ->end();
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('menu')
            ->add('cotizacion')
            ->add('posicion')
            ->add('creado')
            ->add('modificado');
    }
}
