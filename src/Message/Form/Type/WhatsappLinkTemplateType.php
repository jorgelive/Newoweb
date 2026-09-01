<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Panel\Form\Type\TranslationLongTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WhatsappLinkTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ⚠️ Gemela de la de Beds24, y hace falta por el mismo motivo: desde el 01/09/2026
            // este cuerpo es el que sale por WhatsApp dentro de la ventana, así que también
            // puede acabar con sus enlaces duplicados si además se emula la botonera.
            //
            // Viene MARCADA por defecto: estos textos nacieron para el enlace `wa.me`, escritos
            // para leerse solos y con sus enlaces dentro.
            ->add('disable_meta_buttons', CheckboxType::class, [
                'label' => 'Ocultar botones interactivos (No emular botonera de WhatsApp al final del mensaje)',
                'help' => '<b>Viene marcada, y casi siempre es lo correcto:</b> estos textos se escriben con sus enlaces dentro. '
                    . 'Desmárcala sólo si escribiste el cuerpo <b>sin</b> enlaces y quieres que el sistema los añada al final.',
                'help_html' => true,
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-3 text-warning font-weight-bold'],
            ])
            ->add('body', CollectionType::class, [
                'entry_type' => TranslationLongTextType::class,
                'label' => 'Textos para WhatsApp (envío dentro de la ventana y enlace manual wa.me)',
                'help' => '💡 <b>Ojo:</b> desde el 01/09/2026 este cuerpo NO es sólo para el enlace manual. '
                    . 'Cuando el huésped escribió hace menos de 24 h, <b>es el que se le envía</b> — en vez del de Meta, '
                    . 'que es más corto porque tiene que caber en una plantilla aprobada.<br><br>'
                    . '<b>Es el sitio para el texto bueno:</b> sin tope de caracteres y sin aprobación de nadie. '
                    . 'Admite variables de varias líneas como <code>{{bloque_pago}}</code> o <code>{{estancias}}</code>, '
                    . 'que en Meta no caben.',
                'help_html' => true,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => [
                    'label' => 'Traducción'
                ],
                'attr' => ['class' => 'pms-flat-collection'], // Mantenemos tu CSS premium
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null, // Array directo (JSON)
        ]);

        $resolver->setDefined([
            'allow_add',
            'allow_delete',
            'delete_empty',
            'entry_options',
            'entry_type',
        ]);
    }
}