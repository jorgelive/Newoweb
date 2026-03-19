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

class WhatsappMetaTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('is_active', CheckboxType::class, [
                'label' => 'Activar envío por WhatsApp (Meta)',
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('body', CollectionType::class, [
                'entry_type' => TranslationLongTextType::class,
                'label' => '1. Texto Libre (Para ventana de 24h abierta - GRATIS)',
                'help' => 'Este texto se enviará si el huésped nos ha escrito en las últimas 24 horas. Soporta variables nativas: {{ guest_name }}.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => 'Traducción'],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])
            ->add('whatsapp_meta_template_name', TextType::class, [
                'label' => '2. Nombre Base de Plantilla Oficial (Para ventana cerrada - PAGO)',
                'required' => false,
                'attr' => ['placeholder' => 'Ej: welcome_confirmation'],
                'help' => 'Si la ventana de 24h está cerrada, el sistema usará esta configuración oficial de Meta.',
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Categoría (Solo Oficial)',
                'required' => false,
                'choices' => [
                    'Utility (Servicio)' => 'UTILITY',
                    'Marketing' => 'MARKETING',
                    'Authentication' => 'AUTHENTICATION',
                ],
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('params_map', CollectionType::class, [
                'entry_type' => TextType::class,
                'label' => 'Variables Oficiales (Orden: {{1}}, {{2}}...)',
                'allow_add' => true,
                'allow_delete' => true,
                'entry_options' => [
                    'label' => 'Variable',
                    'attr' => ['placeholder' => 'Ej: guest_name']
                ],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])
            ->add('language_mapping', CollectionType::class, [
                'entry_type' => MetaLanguageConfigType::class,
                'label' => 'IDs Oficiales en Meta por Idioma',
                'help' => 'Mapeo estricto de los IDs aprobados en Business Manager.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => 'Configuración de Idioma'],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
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