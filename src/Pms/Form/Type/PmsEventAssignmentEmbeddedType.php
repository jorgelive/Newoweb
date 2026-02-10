<?php

declare(strict_types=1);

namespace App\Pms\Form\Type;

use App\Entity\User;
use App\Panel\Helper\AdminFieldHelper;
use App\Pms\Entity\PmsEventAssignment;
use App\Pms\Entity\PmsEventAssignmentActivity;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulario embebido para asignaciones de eventos.
 * Adaptado para IDs naturales en actividades y ordenación por prioridad.
 */
final class PmsEventAssignmentEmbeddedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('activity', EntityType::class, [
                'class' => PmsEventAssignmentActivity::class,
                'choice_label' => 'nombre',
                'placeholder' => false,
                'required' => true,

                // ✅ Ordenamos por el nuevo campo 'orden' para que el primer item sea el más relevante
                'query_builder' => fn (EntityRepository $er) =>
                $er->createQueryBuilder('a')
                    ->orderBy('a.orden', 'ASC')
                    ->addOrderBy('a.nombre', 'ASC'),

                // 1. EL PADRE: Inyectamos el Rol (necesario para el JS de filtrado)
                'choice_attr' => fn (?PmsEventAssignmentActivity $a) =>
                $a ? ['data-role-required' => $a->getRol()] : [],

                // 2. EL CEREBRO: Mantenemos la lógica de AdminFieldHelper
                'attr' => AdminFieldHelper::getAttributes(
                    childSelector: '.js-pms-user-target',
                    childAttr: 'user-roles',
                    operator: AdminFieldHelper::OP_JSON,
                    parentSource: 'data-role-required'
                ),
            ])
            ->add('usuario', EntityType::class, [
                'class' => User::class,
                // ✅ Usamos getter explícito para el nombre
                'choice_label' => fn (User $u) => (string) ($u->getNombre() ?? $u->getEmail()),
                'placeholder' => 'Seleccionar Personal',
                'required' => false,

                // ✅ Los usuarios ahora usan UUID v7.
                // Al ser ordenables por tiempo, DESC nos muestra los más recientes.
                'query_builder' => fn (EntityRepository $er) =>
                $er->createQueryBuilder('u')->orderBy('u.id', 'DESC'),

                'attr' => [
                    'class' => 'js-pms-user-target',
                ],

                // 4. LOS DATOS: Roles para el match con la actividad
                'choice_attr' => fn (?User $u) =>
                $u ? ['data-user-roles' => json_encode($u->getRoles())] : [],
            ])
            ->add('nota', TextareaType::class, [
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => 'Instrucciones específicas para esta tarea...'
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PmsEventAssignment::class,
        ]);
    }
}