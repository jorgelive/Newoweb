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

/**
 * Formulario maestro para gestionar el nodo JSON whatsappMetaTmpl.
 * Trabaja devolviendo arreglos asociativos puros, sin instanciar clases de datos.
 */
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
            ->add('meta_template_name', TextType::class, [
                'label' => 'Nombre Base de Plantilla Oficial (Para ventana cerrada - PAGO)',
                'required' => false,
                'attr' => ['placeholder' => 'Ej: welcome_confirmation'],
                'help' => 'El nombre exacto aprobado en Facebook Business Manager.',
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Categoría de la Plantilla',
                'required' => false,
                'choices' => [
                    'Utility (Servicio)' => 'UTILITY',
                    'Marketing' => 'MARKETING',
                    'Authentication' => 'AUTHENTICATION',
                ],
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('params_map', CollectionType::class, [
                'entry_type' => MetaParamMappingType::class,
                'label' => 'Mapeo de Variables Oficiales (Orden estricto de Meta)',
                'help' => 'Asocia las variables posicionales de Meta (1, 2, 3) con los campos de tu sistema (guest_name, checkout_time).',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => [
                    'label' => false, // Ocultamos el label por defecto del índice para que el sub-formulario decida
                ],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])
            ->add('language_mapping', CollectionType::class, [
                'entry_type' => MetaLanguageConfigType::class,
                'label' => 'IDs Oficiales en Meta por Idioma',
                'help' => 'Mapeo estricto de los IDs aprobados en Business Manager por cada idioma traducido.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => 'Configuración de Idioma'],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])
            ->add('body', CollectionType::class, [
                'entry_type' => TranslationLongTextType::class,
                'label' => 'Texto Decodificado (Vista previa en ventana abierta - GRATIS)',
                'help' => 'Este texto se previsualizará en el panel si el huésped nos ha escrito en las últimas 24 horas. Soporta variables nativas: {{ guest_name }}.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => 'Traducción'],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // data_class en null asegura que Symfony devuelva un arreglo asociativo y no intente mapear a un objeto
            'data_class' => null,
        ]);

        // Atributos definidos explícitamente para compatibilidad con EasyAdmin y colecciones anidadas
        $resolver->setDefined([
            'allow_add',
            'allow_delete',
            'delete_empty',
            'entry_options',
            'entry_type',
        ]);
    }
}