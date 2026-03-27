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
 * * * OPTIMIZACIÓN: Se ha bloqueado la edición de campos nativos de Meta (index, type, content)
 * que son gestionados por el sincronizador, y se expone 'resolver_key' para la lógica interna.
 */
class WhatsappMetaButtonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('index', IntegerType::class, [
                'label' => 'Índice (Posición)',
                'attr' => ['readonly' => true], // Bloqueado: Lo controla Meta
                'help' => '0 = Primer botón, 1 = Segundo...',
                'row_attr' => ['class' => 'col-md-2 mb-3'],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Tipo de Botón',
                'choices' => [
                    'Enlace (URL)' => 'url',
                    'Respuesta Rápida' => 'quick_reply',
                    'Llamar' => 'phone_number',
                ],
                'attr' => ['readonly' => true], // Bloqueado: Lo controla Meta
                'row_attr' => ['class' => 'col-md-2 mb-3'],
            ])
            ->add('content', TextType::class, [
                'label' => 'Valor Nativo (Meta)',
                'required' => false,
                'attr' => ['readonly' => true], // Bloqueado: Aquí escribe el Sync Service
                'help' => 'URL oficial aprobada (ej: https://.../{{1}}).',
                'row_attr' => ['class' => 'col-md-5 mb-3'],
            ])
            // NUEVO CAMPO: Aquí es donde tu equipo ingresará 'url_guide_nd'
            ->add('resolver_key', TextType::class, [
                'label' => 'Variable del PMS',
                'required' => false,
                'attr' => ['placeholder' => 'Ej: guide_path'],
                'help' => 'Dato real a inyectar Ej: "guide_path", "tours_catalog_path".',
                'row_attr' => ['class' => 'col-md-3 mb-3'],
            ])
            ->add('button_text', CollectionType::class, [
                'entry_type' => TranslationTextType::class, // Mantenemos tu clase personalizada
                'label' => 'Traducciones de la Etiqueta (Visible en texto libre)',
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

            // CONEXIÓN TWIG: Este prefijo conecta la clase con el bloque {% block whatsapp_meta_button_entry_widget %}
            'block_prefix' => 'whatsapp_meta_button_entry',

            // Oculta la etiqueta genérica (ej: "0", "1") que Symfony pone por defecto en las colecciones
            'label' => false,
        ]);
    }
}