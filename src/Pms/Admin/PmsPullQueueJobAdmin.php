<?php
declare(strict_types=1);

namespace App\Pms\Admin;

use App\Pms\Entity\PmsPullQueueJob;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Form\Type\ModelType;
use Sonata\Form\Type\DatePickerType;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

final class PmsPullQueueJobAdmin extends AbstractAdmin
{
    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        // Deja las rutas estándar
        parent::configureRoutes($collection);

        // Si luego quieres acciones custom tipo "retry", aquí se agregan.
        // $collection->add('retry', $this->getRouterIdParameter() . '/retry');
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'runAt';
        $sortValues['_sort_order'] = 'DESC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        /** @var PmsPullQueueJob $job */
        $job = $this->getSubject();

        $form
            ->with('Job', ['class' => 'col-md-6'])
            // Type fijo: solo lectura (no hay setter). Lo mostramos como info.
            ->add('type', null, [
                'required' => false,
                'disabled' => true,
                'help' => 'Tipo fijo (por ahora solo bookings arrival range).',
            ])
            ->add('beds24Config', ModelType::class, [
                'required' => true,
            ])
            ->add('unidades', ModelType::class, [
                'required' => false,
                'multiple' => true,
                'by_reference' => false, // para que Sonata use addUnidad()
            ])
            ->end()
            ->with('Rango / Programación', ['class' => 'col-md-6'])
            ->add('arrivalFrom', DatePickerType::class, [
                'required' => true,
                'format' => 'yyyy/MM/dd',
            ])
            ->add('arrivalTo', DatePickerType::class, [
                'required' => true,
                'format' => 'yyyy/MM/dd',
            ])
            ->add('priority', IntegerType::class, [
                'required' => false,
            ])
            ->add('maxAttempts', IntegerType::class, [
                'required' => false,
            ])
            ->add('status', ChoiceType::class, [
                'required' => false,
                'disabled' => true,
                'choices' => [
                    'pending' => PmsPullQueueJob::STATUS_PENDING,
                    'running' => PmsPullQueueJob::STATUS_RUNNING,
                    'done'    => PmsPullQueueJob::STATUS_DONE,
                    'failed'  => PmsPullQueueJob::STATUS_FAILED,
                ],
            ])
            ->end()
        ;

        // Campos técnicos solo para lectura (útiles en debug)
        $form
            ->with('Debug', ['class' => 'col-md-12'])
            ->add('attempts', IntegerType::class, [
                'required' => false,
                'disabled' => true,
            ])
            ->add('lockedBy', null, [
                'required' => false,
                'disabled' => true,
            ])
            ->add('lockedAt', DateTimePickerType::class, [
                'required' => false,
                'disabled' => true,
                'format' => 'yyyy/MM/dd HH:mm',
            ])
            ->add('lastError', TextareaType::class, [
                'required' => false,
                'disabled' => true,
                'attr' => ['rows' => 4],
            ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('beds24Config')
            ->add('type')
            ->add('status')
            ->add('runAt')
            ->add('arrivalFrom')
            ->add('arrivalTo')
            ->add('lockedAt')
        ;
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('type')
            ->add('beds24Config')
            ->add('status')
            ->add('priority')
            ->add('attempts')
            ->add('maxAttempts')
            ->add('arrivalFrom', null, ['format' => 'Y/m/d'])
            ->add('arrivalTo', null, ['format' => 'Y/m/d'])
            ->add('runAt', null, ['format' => 'Y/m/d H:i'])
            ->add('lockedAt', null, ['format' => 'Y/m/d H:i'])
            ->add('updated', null, ['format' => 'Y/m/d H:i'])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                ],
            ])
        ;
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('type')
            ->add('beds24Config')
            ->add('status')
            ->add('priority')
            ->add('attempts')
            ->add('maxAttempts')
            ->add('arrivalFrom', null, ['format' => 'Y/m/d'])
            ->add('arrivalTo', null, ['format' => 'Y/m/d'])
            ->add('runAt', null, ['format' => 'Y/m/d H:i'])
            ->add('lockedBy')
            ->add('lockedAt', null, ['format' => 'Y/m/d H:i'])
            ->add('lastError')
            ->add('responseMeta')
            ->add('created', null, ['format' => 'Y/m/d H:i'])
            ->add('updated', null, ['format' => 'Y/m/d H:i'])
        ;
    }
}