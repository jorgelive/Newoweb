<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Panel\Form\Type\TranslationLongTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WhatsappGupshupTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('is_active', CheckboxType::class, [
                'label' => 'Activar envío por WhatsApp (Meta/Gupshup)',
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])
            ->add('meta_template_name', TextType::class, [
                'label' => 'Nombre Base en Meta',
                'attr' => ['placeholder' => 'Ej: welcome_confirmation'],
                'row_attr' => ['class' => 'col-md-8 mb-3'],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Categoría',
                'choices' => [
                    'Utility (Servicio)' => 'UTILITY',
                    'Marketing' => 'MARKETING',
                    'Authentication' => 'AUTHENTICATION',
                ],
                'row_attr' => ['class' => 'col-md-4 mb-3'],
            ])
            ->add('params_map', CollectionType::class, [
                'entry_type' => TextType::class,
                'label' => 'Variables de la Plantilla (Orden: {{1}}, {{2}}...)',
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => 'Variable',
                    'attr' => ['placeholder' => 'Ej: guest_name, locator, checkin_date...']
                ],
                // 🔥 Inyectamos la clase para que nuestro CSS la encuentre
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])
            ->add('text_reference', CollectionType::class, [
                'entry_type' => TranslationLongTextType::class,
                'label' => 'Textos de Referencia (Previsualización Interna)',
                'help' => 'Agrega las traducciones. Esto NO se envía a Meta, es para la vista del Panel.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => [
                    'label' => 'Traducción'
                ],
                // 🔥 Inyectamos la clase
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])
            ->add('language_mapping', CollectionType::class, [
                'entry_type' => MetaLanguageConfigType::class,
                'label' => 'Configuración Técnica en Meta por Idioma',
                'help' => 'Mapeo estricto de los IDs y estados aprobados en Business Manager.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => [
                    'label' => 'Configuración de Idioma'
                ],
                // 🔥 Inyectamos la clase
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null, // Crucial: maneja un Array (JSON) directamente
        ]);

        // EL TRUCO MAESTRO PARA DOMAR A EASYADMIN
        $resolver->setDefined([
            'allow_add',
            'allow_delete',
            'delete_empty',
            'entry_options',
            'entry_type',
        ]);
    }
}