<?php

declare(strict_types=1);

namespace App\Panel\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VideoItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, [
                'label' => 'Título del Video',
                'required' => false,
                'attr' => ['placeholder' => 'Ej: Tour Virtual 360']
            ])
            ->add('url', UrlType::class, [
                'label' => 'Link de YouTube/Vimeo',
                'required' => true,
                'default_protocol' => 'https',
                'attr' => ['placeholder' => 'https://www.youtube.com/watch?v=...']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Importante: No ponemos 'data_class' porque guardaremos esto como Array asociativo en el JSON
    }
}