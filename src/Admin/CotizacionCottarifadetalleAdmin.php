<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class CotizacionCottarifadetalleAdmin extends AbstractAdmin
{
    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('cottatifa')
            ->add('tipotarifadetalle',  null, [
                'label' => 'Tipo'
            ])
            ->add('detalle')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('cottatifa')
            ->add('tipotarifadetalle',  null, [
                'label' => 'Tipo'
            ])
            ->add('detalle')
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                ],
            ])
        ;
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper): void
    {
        if ($this->getRoot()->getClass() != 'App\Entity\CotizacionFile'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCotizacion'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCotservicio'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCotcomponente'
            && $this->getRoot()->getClass() != 'App\Entity\CotizacionCottarifa'
        ){
            $formMapper->add('cottarifa',  null, [
                'label' => 'Tarifa'
            ]);
        }

        $formMapper
            ->add('tipotarifadetalle',  null, [
                'label' => 'Tipo'
            ])
            ->add('detalle')
        ;


    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('cottarifa',  null, [
                'label' => 'Tarifa'
            ])
            ->add('detalle')
            ->add('tipotarifadetalle',  null, [
                'label' => 'Tipo'
            ])
        ;
    }
}
