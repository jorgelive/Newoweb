<?php

namespace App\Admin;

use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Type\CollectionType;
use Sonata\Form\Type\DateTimePickerType;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;

class ReservaReservaAdmin extends AbstractAdmin
{

    public function alterNewInstance($object): void
    {
        $entityManager = $this->getModelManager()->getEntityManager('App:ReservaReserva');

        $inicio = new \DateTime('today');
        $inicio = $inicio->add(\DateInterval::createFromDateString('12 hours'));
        $fin = new \DateTime( 'tomorrow + 1day');
        $fin = $fin->add(\DateInterval::createFromDateString('9 hours'));

        $estadoReference = $entityManager->getReference('App:ReservaEstado', 2);
        $object->setEstado($estadoReference);
        $object->setFechahorainicio($inicio);
        $object->setFechahorafin($fin);
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper): void
    {
        $datagridMapper
            ->add('id')
            ->add('unit', null, [
                'label' => 'Alojamiento'
            ])
            ->add('estado')
            ->add('nombre')
            ->add('fechahorainicio', null, [
                'label' => 'Inicio',
            ])
            ->add('fechahorafin', null, [
                'label' => 'Fin'
            ])
            ->add('descripcion')
        ;
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->add('id')
            ->add('unit', null, [
                'label' => 'Alojamiento'
            ])
            ->add('estado')
            ->add('uid')
            ->add('nombre')
            ->add('fechahorainicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('fechahorafin', null, [
                'label' => 'Fin',
                'format' => 'Y/m/d H:i'
            ])
            ->add('enlace')
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('numeroadultos', null, [
                'label' => 'Adultos'
            ])
            ->add('numeroninos', null, [
                'label' => 'Niños'
            ])
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
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


        $formMapper
            ->add('unit', null, [
                'label' => 'Alojamiento'
            ])
            ->add('estado')
            ->add('nombre')
            ->add('fechahorainicio', DateTimePickerType::class, [
                'label' => 'Inicio',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd HH:mm'
            ])
            ->add('fechahorafin', DateTimePickerType::class, [
                'label' => 'Fin',
                'dp_show_today' => true,
                'format'=> 'yyyy/MM/dd HH:mm'
            ])
            ->add('enlace')
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('numeroadultos', null, [
                'label' => 'Adultos'
            ])
            ->add('numeroninos', null, [
                'label' => 'Niños'
            ])
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
            ->add('importes', CollectionType::class, [
                'by_reference' => false,
                'label' => ''
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
            ->add('pagos', CollectionType::class, [
                'by_reference' => false,
                'label' => 'Cobranzas'
            ], [
                'edit' => 'inline',
                'inline' => 'table'
            ])
        ;
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper): void
    {
        $showMapper
            ->add('id')
            ->add('unit', null, [
                'label' => 'Alojamiento'
            ])
            ->add('uid')
            ->add('estado')
            ->add('nombre')
            ->add('fechahorainicio', null, [
                'label' => 'Inicio',
                'format' => 'Y/m/d H:i'
            ])
            ->add('fechahorafin', null, [
                'label' => 'Fin',
                'format' => 'Y/m/d H:i'
            ])
            ->add('enlace')
            ->add('descripcion', null, [
                'label' => 'Descripción'
            ])
            ->add('numeroadultos', null, [
                'label' => 'Adultos'
            ])
            ->add('numeroninos', null, [
                'label' => 'Niños'
            ])
            ->add('chanel', null, [
                'label' => 'Canal'
            ])
        ;
    }
}
