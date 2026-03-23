<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Panel\Form\Type\TranslationTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulario para los ítems del array 'buttons_map' de WhatsApp Meta.
 * Configura la variable de la URL y reutiliza TranslationTextType para los textos.
 */
class WhatsappMetaButtonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('index', IntegerType::class, [
                'label' => 'Índice (Posición)',
                'help' => '0 = Primer botón, 1 = Segundo...',
                'row_attr' => ['class' => 'col-md-2 mb-3'],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Tipo de Botón',
                'choices' => [
                    'Enlace (URL)' => 'url',
                    'Respuesta Rápida' => 'quick_reply',
                ],
                'row_attr' => ['class' => 'col-md-3 mb-3'],
            ])
            ->add('content', TextType::class, [
                'label' => 'Variable Dinámica / URL',
                'attr' => ['placeholder' => 'Ej: {{url_checkin}}'],
                'help' => 'Usa variables {{var}} para URLs dinámicas o escribe el link fijo.',
                'row_attr' => ['class' => 'col-md-7 mb-3'],
            ])
            ->add('button_text', CollectionType::class, [
                'entry_type' => TranslationTextType::class,
                'label' => 'Traducciones de la Etiqueta del Botón (Visible para el huésped)',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-0'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // CRÍTICO: null para devolver un array asociativo limpio al JSON general
            'data_class' => null,

            // CONEXIÓN TWIG: Este prefijo conecta la clase con el bloque {% block whatsapp_meta_button_widget %}
            'block_prefix' => 'whatsapp_meta_button_entry',

            // Oculta la etiqueta genérica (ej: "0", "1") que Symfony pone por defecto en las colecciones
            'label' => false,
        ]);
    }
}