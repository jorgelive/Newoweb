<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Panel\Form\Type\TranslationHtmlType;
use App\Panel\Form\Type\TranslationTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmailTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('is_active', CheckboxType::class, [
                'label' => 'Activar envío por Correo Electrónico (Email)',
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])
            ->add('subject', CollectionType::class, [
                // 🔥 Usamos tu componente de texto simple
                'entry_type' => TranslationTextType::class,
                'label' => 'Asuntos del Correo',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => 'Asunto'],
                'attr' => ['class' => 'pms-flat-collection'], // Tu disfraz CSS
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])
            ->add('body', CollectionType::class, [
                // 🔥 Usamos tu componente con TinyMCE
                'entry_type' => TranslationHtmlType::class,
                'label' => 'Cuerpo del Correo (Diseño HTML)',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => 'Plantilla HTML'],
                'attr' => ['class' => 'pms-flat-collection'], // Tu disfraz CSS
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);

        $resolver->setDefined([
            'allow_add', 'allow_delete', 'delete_empty', 'entry_options', 'entry_type',
        ]);
    }
}