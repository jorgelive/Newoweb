<?php

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

final class PmsEventAssignmentEmbeddedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('activity', EntityType::class, [
                'class' => PmsEventAssignmentActivity::class,
                'choice_label' => 'nombre',

                // CAMBIO AQUÍ:
                // 'placeholder' => false elimina la opción vacía.
                // HTML seleccionará automáticamente el primer item de la lista.
                'placeholder' => false,

                'required' => true,

                // 1. EL PADRE: Inyectamos el dato origen (Rol Requerido)
                'choice_attr' => fn (?PmsEventAssignmentActivity $a) =>
                $a ? ['data-role-required' => $a->getRol()] : [],

                // 2. EL CEREBRO: Configuramos la relación
                'attr' => AdminFieldHelper::getAttributes(
                    childSelector: '.js-pms-user-target',
                    childAttr: 'user-roles',
                    operator: AdminFieldHelper::OP_JSON,
                    parentSource: 'data-role-required'
                ),
            ])
            ->add('usuario', EntityType::class, [
                'class' => User::class,
                'choice_label' => fn (User $u) => (string) $u->getNombre(),
                'placeholder' => 'Personal',
                'required' => false,

                'query_builder' => fn (EntityRepository $er) =>
                $er->createQueryBuilder('u')->orderBy('u.id', 'DESC'),

                // 3. EL HIJO: Marcamos el objetivo
                'attr' => [
                    'class' => 'js-pms-user-target',
                ],

                // 4. LOS DATOS: Inyectamos los roles del usuario en JSON
                'choice_attr' => fn (?User $u) =>
                $u ? ['data-user-roles' => json_encode($u->getRoles())] : [],
            ])
            ->add('nota', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Nota'],
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