<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Panel\Form\Type\TranslationLongTextType;
use App\Panel\Helper\AyudaPlegable;
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
                'help' => 'Sin esto marcado, esta plantilla <b>no se ofrece</b> para el chat de la OTA — ni a mano ni al agente.',
                'help_html' => true,
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('disable_meta_buttons', CheckboxType::class, [
                'label' => 'Ocultar botones interactivos (No emular botonera de WhatsApp al final del mensaje)',
                'help' => 'El chat de la OTA no tiene botones, así que el sistema los añade como una lista de enlaces al final. '
                    . '<b>Márcalo si ya escribiste los enlaces dentro del texto</b>, o el huésped los verá dos veces.',
                'help_html' => true,
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-3 text-warning font-weight-bold'],
            ])
            ->add('body', CollectionType::class, [
                'entry_type' => TranslationLongTextType::class,
                'label' => 'Cuerpo del mensaje',
                'help' => AyudaPlegable::html(
                    'Va al chat de Booking o Airbnb. <b>Aquí no hay ventana de 24 h</b>: se escribe siempre y '
                    . 'con el largo que haga falta.',
                    'Variables: <code>{{guest_name}}</code>, <code>{{estancias}}</code>, '
                    . '<code>{{bloque_pago}}</code>, <code>{{account_url}}</code>, <code>{{guide_url}}</code>, '
                    . '<code>{{whatsapp_numero}}</code>…<br><br>'
                    . '⚠️ En el chat de Booking <b>el huésped no puede adjuntar imágenes</b> (nosotros sí): '
                    . 'si le pides una captura, dile que la mande por WhatsApp al <code>{{whatsapp_numero}}</code>.'
                ),
                'help_html' => true,
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