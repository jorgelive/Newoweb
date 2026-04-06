<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Panel\Form\Type\TranslationLongTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class Beds24TemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('is_active', CheckboxType::class, [
                'label' => 'Activar envío por Beds24',
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('disable_meta_buttons', CheckboxType::class, [
                'label' => 'Ocultar botones interactivos (No emular botonera de WhatsApp al final del mensaje)',
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-3 text-warning font-weight-bold'],
            ])
            ->add('body', CollectionType::class, [
                'entry_type' => TranslationLongTextType::class,
                'label' => 'Cuerpo del Mensaje ( Soporta {{ guest_name }}, {{ localizador }}, {{ casita_url }} )',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => [
                    'label' => 'Traducción'
                ],
                'attr' => ['class' => 'pms-flat-collection'], // 🔥 Usamos tu CSS elegante
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null, // Array directo para el JSON
        ]);

        // Evitamos que EasyAdmin rompa por configuraciones de colecciones
        $resolver->setDefined([
            'allow_add',
            'allow_delete',
            'delete_empty',
            'entry_options',
            'entry_type',
        ]);
    }
}