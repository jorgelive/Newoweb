<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Panel\Form\Type\TranslationLongTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WhatsappLinkTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('body', CollectionType::class, [
                'entry_type' => TranslationLongTextType::class,
                'label' => 'Textos para el Enlace Manual (wa.me)',
                'help' => '💡 <b>Nota:</b> Esta plantilla no se envía automáticamente. Solo se usa para generar botones que el personal presionará para abrir su WhatsApp Web.',
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