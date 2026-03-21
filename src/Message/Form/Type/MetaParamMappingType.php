<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Sub-formulario para mapear las variables indexadas de Meta con las propiedades del PMS.
 * Produce arreglos del tipo: ['meta_var' => '1', 'entity_field' => 'guest_name']
 */
class MetaParamMappingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('meta_var', TextType::class, [
                'label' => 'Variable Meta (Ej: 1)',
                'required' => true,
                'attr' => [
                    'placeholder' => '1',
                    'class' => 'col-md-4'
                ],
                'help' => 'El número entre llaves {{1}} que asignó Meta.'
            ])
            ->add('entity_field', TextType::class, [
                'label' => 'Variable del Sistema',
                'required' => true,
                'attr' => [
                    'placeholder' => 'guest_name',
                    'class' => 'col-md-8'
                ],
                'help' => 'El campo interno con el que se reemplazará.'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null, // Crucial para que retorne un arreglo y se integre al JSON padre
        ]);
    }
}