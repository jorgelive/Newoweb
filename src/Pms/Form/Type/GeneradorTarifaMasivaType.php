<?php

declare(strict_types=1);

namespace App\Pms\Form\Type;

use App\Pms\Service\Tarifa\Dto\GeneradorTarifaMasivaDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GeneradorTarifaMasivaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fechaInicio', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Fecha Inicio',
                'attr' => ['class' => 'form-control']
            ])
            ->add('fechaFin', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Fecha Fin',
                'attr' => ['class' => 'form-control']
            ])
            ->add('porcentaje', NumberType::class, [
                'label' => 'Ajuste (%)',
                'help' => 'Ej: 10 para aumentar 10%, -15 para descontar 15%, 0 para precio base exacto.',
                'scale' => 2,
                'attr' => ['class' => 'form-control']
            ])
            ->add('minStay', IntegerType::class, [
                'label' => 'Estancia Mínima',
                'data' => 2,
                'attr' => ['class' => 'form-control']
            ])
            ->add('prioridad', IntegerType::class, [
                'label' => 'Prioridad',
                'data' => 1,
                'attr' => ['class' => 'form-control']
            ])
            ->add('importante', CheckboxType::class, [
                'label' => 'Marcar como Tarifa Prioritaria (Importante)',
                'required' => false,
                'attr' => ['class' => 'form-check-input']
            ])
            ->add('generar', SubmitType::class, [
                'label' => 'Generar Tarifas Masivas',
                'attr' => ['class' => 'btn btn-primary w-100 mt-3']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GeneradorTarifaMasivaDto::class,
        ]);
    }
}