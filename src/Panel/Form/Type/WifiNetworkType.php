<?php

declare(strict_types=1);

namespace App\Panel\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents; // <--- Necesario
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class WifiNetworkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // 1. UBICACIÓN (CollectionType)
        $builder->add('ubicacion', CollectionType::class, [
            'label' => 'Zona / Location (Multi-idioma)',
            'entry_type' => TranslationTextType::class,
            'entry_options' => ['label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'attr' => ['class' => 'my-3 p-3 border rounded bg-light'],
            'row_attr' => ['class' => 'col-12'],
        ]);

        // 2. SSID
        $builder->add('ssid', TextType::class, [
            'label' => 'SSID',
            'required' => true,
            'constraints' => [new NotBlank()],
            'attr' => ['class' => 'form-control font-weight-bold'],
            'row_attr' => ['class' => 'col-md-6'],
        ]);

        // 3. PASSWORD
        $builder->add('password', TextType::class, [
            'label' => 'Password',
            'required' => true,
            'constraints' => [new NotBlank()],
            'attr' => ['class' => 'form-control'],
            'row_attr' => ['class' => 'col-md-6'],
        ]);

        // 🔥 INTERCEPTOR DE DATOS LEGACY
        // Esto se ejecuta ANTES de que el formulario intente leer los datos.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $data = $event->getData();

            // Si no hay datos, salimos
            if (!$data) {
                return;
            }

            // $data es el array de una red WiFi ['ssid' => '...', 'ubicacion' => '...']
            // Verificamos si 'ubicacion' existe y si es un STRING (Legacy)
            if (isset($data['ubicacion']) && is_string($data['ubicacion'])) {

                // 🚑 CIRUGÍA EN CALIENTE: Convertimos el string en array
                $textoOriginal = $data['ubicacion'];

                $data['ubicacion'] = [
                    [
                        'language' => 'es', // Asumimos español por defecto
                        'content' => $textoOriginal
                    ]
                ];

                // Guardamos el dato corregido para que el formulario lo procese felizmente
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}