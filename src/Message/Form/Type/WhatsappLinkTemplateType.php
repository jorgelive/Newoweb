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
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-3 text-warning font-weight-bold'],
            ])
            ->add('body', CollectionType::class, [
                'entry_type' => TranslationLongTextType::class,
                'label' => 'Textos para WhatsApp (envío dentro de la ventana y enlace manual wa.me)',
                'help' => '💡 <b>Ojo:</b> desde el 01/09/2026 este cuerpo NO es sólo para el enlace manual: es el que se envía por WhatsApp cuando la ventana de 24 h está abierta, en vez del cuerpo de Meta — que es más corto porque tiene que caber en una plantilla aprobada. Lo que escribas aquí es lo que va a leer el huésped.',
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