<?php
// src/Pms/Form/Type/PmsReservaHuespedType.php

namespace App\Pms\Form\Type;

use App\Oweb\Entity\MaestroPais;
use App\Oweb\Entity\MaestroTipodocumento;
use App\Pms\Entity\PmsReservaHuesped;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;

class PmsReservaHuespedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // --- DATOS PERSONALES ---
            ->add('esPrincipal', CheckboxType::class, [
                'label' => '¿Es el titular?',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'row_attr' => ['class' => 'form-check form-switch mb-3'], // Estilo Bootstrap switch
            ])
            ->add('nombre', TextType::class, [
                'label' => 'Nombres',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ej: Juan Alberto']
            ])
            ->add('apellido', TextType::class, [
                'label' => 'Apellidos',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ej: Pérez López']
            ])
            ->add('fechaNacimiento', DateType::class, [
                'label' => 'F. Nacimiento',
                'widget' => 'single_text',
                'required' => false,
                'attr' => ['class' => 'form-control']
            ])
            ->add('pais', EntityType::class, [
                'label' => 'Nacionalidad',
                'class' => MaestroPais::class,
                'choice_label' => 'nombre',
                'placeholder' => 'Seleccione un país...',
                'attr' => ['class' => 'form-select']
            ])
            ->add('tipoDocumento', EntityType::class, [
                'label' => 'Tipo Doc.',
                'class' => MaestroTipodocumento::class,
                'choice_label' => 'nombre',
                'attr' => ['class' => 'form-select']
            ])
            ->add('documentoNumero', TextType::class, [
                'label' => 'Nº Documento',
                'attr' => ['class' => 'form-control']
            ])

            // --- ARCHIVOS MULTIMEDIA (VichUploader) ---

            // 1. Documento de Identidad (DNI/Pasaporte)
            ->add('documentoFile', VichImageType::class, [
                'label' => 'Documento de Identidad',
                'required' => false,
                'allow_delete' => true,
                'download_uri' => true,
                'image_uri' => true,
                'asset_helper' => true, // Usa asset() para resolver rutas
                'help' => 'Sube una foto clara del DNI o Pasaporte.'
            ])

            // 2. Tarjeta Andina (TAM)
            ->add('tamFile', VichImageType::class, [
                'label' => 'Tarjeta Andina (TAM)',
                'required' => false,
                'allow_delete' => true,
                'download_uri' => true,
                'image_uri' => true,
                'asset_helper' => true,
                'help' => 'Requerido para exoneración de IGV en extranjeros.'
            ])

            // 3. Firma Digital
            ->add('firmaFile', VichImageType::class, [
                'label' => 'Firma del Huésped',
                'required' => false,
                'allow_delete' => true,
                'download_uri' => true,
                'image_uri' => true,
                'asset_helper' => true,
                'help' => 'Firma de conformidad escaneada o digital.'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PmsReservaHuesped::class,
        ]);
    }
}