<?php

namespace App\Oweb\Admin;

use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;

class CotizacionCottarifadetalleAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'OPERACIONES';
    }
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
        if($this->getRoot()->getClass() != 'App\Oweb\Entity\CotizacionFile'
            && $this->getRoot()->getClass() != 'App\Oweb\Entity\CotizacionCotizacion'
            && $this->getRoot()->getClass() != 'App\Oweb\Entity\CotizacionCotservicio'
            && $this->getRoot()->getClass() != 'App\Oweb\Entity\CotizacionCotcomponente'
            && $this->getRoot()->getClass() != 'App\Oweb\Entity\CotizacionCottarifa'
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
