<?php

namespace App\Oweb\Admin;

use App\Oweb\Entity\ReservaUnit;
use App\Oweb\Entity\ReservaUnitcaracteristica;
use App\Oweb\Entity\ReservaUnitCaracteristicaLink;
use Doctrine\ORM\EntityManagerInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\Form\Validator\ErrorElement;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;

class ReservaUnitCaracteristicaLinkAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'RESERVAS';
    }

    public function configure(): void
    {
        $this->classnameLabel = 'Vínculo Característica';
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'prioridad';
        $sortValues['_sort_order'] = 'ASC';
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('id')
            ->add('unit', null, ['label' => 'Unidad'])
            ->add('caracteristica', null, ['label' => 'Característica'])
            ->add('prioridad', null, ['label' => 'Prioridad']);
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id', null, ['label' => 'ID'])
            ->add('unit', null, [
                'label' => 'Unidad',
                'associated_property' => function (?ReservaUnit $u) {
                    return $u ? sprintf('%s — %s', $u->getNombre(), $u->getEstablecimiento()?->getNombre()) : '—';
                },
            ])
            ->add('caracteristica', null, [
                'label' => 'Característica',
                'associated_property' => function (?ReservaUnitcaracteristica $c) {
                    return $c ? $c->getNombre() : '—';
                },
            ])
            ->add('prioridad', null, ['label' => 'Prioridad', 'editable' => true])
            ->add('creado', null, ['label' => 'Creado'])
            ->add('modificado', null, ['label' => 'Modificado'])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show'   => [],
                    'edit'   => [],
                    'delete' => [],
                ],
            ]);
    }

    protected function configureFormFields(FormMapper $form): void
    {
        if($this->getRoot()->getClass() != 'App\Oweb\Entity\ReservaUnit'){
            $form
                ->add('unit', EntityType::class, [
                    'class' => ReservaUnit::class,
                    'label' => 'Unidad',
                    'choice_label' => function (ReservaUnit $u) {
                        return sprintf('%s — %s', $u->getNombre(), $u->getEstablecimiento()?->getNombre());
                    },
                    'placeholder' => '— seleccionar unidad —',
                    'required' => true,
                ]);
        }

        if($this->getRoot()->getClass() != 'App\Oweb\Entity\ReservaUnitcaracteristica') {

            $form
                ->add('caracteristica', EntityType::class, [
                    'class' => ReservaUnitcaracteristica::class,
                    'label' => 'Característica',
                    'choice_label' => 'nombre',
                    'placeholder' => '— seleccionar característica —',
                    'required' => true,
                ])->add('prioridad', IntegerType::class, [
                    'label' => 'Prioridad',
                    'required' => false,
                    'empty_data' => '0',
                    'attr' => ['min' => 0, 'step' => 1],
                ]);
        }
        
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id', null, ['label' => 'ID'])
            ->add('unit', null, ['label' => 'Unidad'])
            ->add('caracteristica', null, ['label' => 'Característica'])
            ->add('prioridad', null, ['label' => 'Prioridad'])
            ->add('creado', null, ['label' => 'Creado'])
            ->add('modificado', null, ['label' => 'Modificado']);
    }

    /**
     * ✔️ Nueva forma (válida en Oweb 4): define los campos a exportar.
     * Puedes usar 'asociacion.propiedad' o getters (p.ej. 'getFoo').
     */
    protected function configureExportFields(): array
    {
        return [
            'ID'              => 'id',
            'Unidad'          => 'unit.nombre',
            'Establecimiento' => 'unit.establecimiento.nombre',
            'Característica'  => 'caracteristica.nombre',
            'Prioridad'       => 'prioridad',
            'Creado'          => 'creado',
            'Modificado'      => 'modificado',
        ];
    }

    /**
     * (Opcional) Limita formatos disponibles.
     */
    public function getExportFormats(): array
    {
        return ['csv', 'xls', 'json']; // añade/remueve a gusto
    }

    public function validate(ErrorElement $errorElement, $object): void
    {
        if (!$object instanceof ReservaUnitCaracteristicaLink) {
            return;
        }

        $unit = $object->getUnit();
        $car  = $object->getCaracteristica();
        if (!$unit || !$car) {
            return;
        }

        /** @var EntityManagerInterface $em */
        $em = $this->getModelManager()->getEntityManager(ReservaUnitCaracteristicaLink::class);

        $qb = $em->getRepository(ReservaUnitCaracteristicaLink::class)->createQueryBuilder('l');
        $qb->select('COUNT(l.id)')
            ->where('l.unit = :u')
            ->andWhere('l.caracteristica = :c')
            ->setParameter('u', $unit)
            ->setParameter('c', $car);

        if ($object->getId()) {
            $qb->andWhere('l.id != :id')->setParameter('id', $object->getId());
        }

        $count = (int) $qb->getQuery()->getSingleScalarResult();
        if ($count > 0) {
            $errorElement
                ->with('caracteristica')
                ->addViolation('Ya existe un vínculo con esta Unidad y Característica.')
                ->end();
        }

        if ($object->getPrioridad() === null) {
            $object->setPrioridad(0);
        }
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        // Deja rutas por defecto (list, create, edit, show, delete, export)
        // $collection->clearExcept(['list','create','edit','show','delete','export']);
    }
}
