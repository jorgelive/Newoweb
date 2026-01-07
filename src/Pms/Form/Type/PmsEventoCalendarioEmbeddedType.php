<?php

namespace App\Pms\Form\Type;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsUnidad;
use Sonata\Form\Type\DateTimePickerType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PmsEventoCalendarioEmbeddedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('pmsUnidad', EntityType::class, [
                'class' => PmsUnidad::class,
                'choice_label' => 'nombre',
                'label' => 'Unidad',
            ])
            ->add('estado', EntityType::class, [
                'class' => PmsEventoEstado::class,
                'choice_label' => 'nombre',
                'label' => 'Estado',
            ])
            ->add('estadoPago', EntityType::class, [
                'class' => PmsEventoEstadoPago::class,
                'choice_label' => 'nombre',
                'label' => 'Pago',
            ])
            ->add('cantidadAdultos', IntegerType::class, [
                'required' => false,
                'label' => 'ADL',
            ])
            ->add('cantidadNinos', IntegerType::class, [
                'required' => false,
                'label' => 'CHD',
            ])
            ->add('inicio', DateTimePickerType::class, [
                'format' => 'yyyy/MM/dd HH:mm',
            ])
            ->add('fin', DateTimePickerType::class, [
                'format' => 'yyyy/MM/dd HH:mm',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PmsEventoCalendario::class,
        ]);
    }
}