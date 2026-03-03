<?php

declare(strict_types=1);

namespace App\Pms\Form\Type;

use App\Pms\Entity\PmsGuiaHasSeccion;
use App\Pms\Entity\PmsGuiaSeccion;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PmsGuiaHasSeccionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('seccion', EntityType::class, [
                'class' => PmsGuiaSeccion::class,
                'choice_label' => function (PmsGuiaSeccion $seccion) {
                    return "📂 " . $seccion->getNombreInterno();
                },
                'attr' => ['class' => 'form-select-sm'],
                'row_attr' => ['class' => 'col-md-9'],
                'label' => 'Seleccionar Sección'
            ])
            ->add('orden', IntegerType::class, [
                'label' => 'Orden',
                'row_attr' => ['class' => 'col-md-3'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PmsGuiaHasSeccion::class,
        ]);
    }
}