<?php

declare(strict_types=1);

namespace App\Panel\Form\Type;

use App\Pms\Entity\PmsGuiaItemGaleria;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;
use App\Panel\Form\Type\TranslationTextType;

class PmsGuiaItemGaleriaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // 1. IMAGEN + PREVIEW (Columna 2 - Pequeña)
        $builder->add('imageFile', VichImageType::class, [
            'label' => false,
            'required' => false,
            'allow_delete' => false,
            'download_uri' => false,
            'image_uri' => true,    // ✅ Muestra la miniatura
            'asset_helper' => true,
            'attr' => ['class' => 'form-control-sm'],
            'row_attr' => ['class' => 'col-md-2 d-flex align-items-center'], // Centrado vertical
        ]);

        // 2. DESCRIPCIÓN (Columna 6 - Principal)
        $builder->add('descripcion', TranslationTextType::class, [
            'label' => 'Descripción',
            'required' => false,
            'row_attr' => ['class' => 'col-md-6'],
            'help' => 'Texto explicativo de la imagen',
        ]);

        // 3. BLOQUE TÉCNICO (Columna 4 - Agrupada)
        // Aquí metemos el Orden y los Switches para que no floten raros

        $builder->add('orden', IntegerType::class, [
            'label' => 'Orden',
            'attr' => ['min' => 0, 'class' => 'form-control-sm'],
            'row_attr' => ['class' => 'col-md-2'], // Ocupa un trocito
        ]);

        // SWITCH 1: Auto Traducir
        $builder->add('ejecutarTraduccion', CheckboxType::class, [
            'label' => 'Traducir',
            'required' => false,
            // ✨ MAGIA BOOTSTRAP: form-switch convierte el check en interruptor
            'row_attr' => ['class' => 'col-md-2 form-check form-switch pt-4 ps-5'],
            'attr' => ['class' => 'form-check-input'],
        ]);

        // SWITCH 2: Forzar
        $builder->add('sobreescribirTraduccion', CheckboxType::class, [
            'label' => 'Forzar',
            'required' => false,
            'row_attr' => ['class' => 'col-md-2 form-check form-switch pt-4 ps-5'],
            'attr' => ['class' => 'form-check-input'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PmsGuiaItemGaleria::class,
        ]);
    }
}