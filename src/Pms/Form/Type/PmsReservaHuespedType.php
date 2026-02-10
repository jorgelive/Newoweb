<?php

declare(strict_types=1);

namespace App\Pms\Form\Type;

use App\Entity\Maestro\MaestroPais;
use App\Entity\Maestro\MaestroDocumentoTipo;
use App\Pms\Entity\PmsReservaHuesped;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Formulario para la gestión de Huéspedes.
 * Incluye carga de archivos (DNI, TAM, Firma) y selectores ordenados.
 */
class PmsReservaHuespedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // --- DATOS PERSONALES ---
            ->add('esPrincipal', CheckboxType::class, [
                'label'    => '¿Es el titular de la reserva?',
                'required' => false,
                'attr'     => ['class' => 'form-check-input'],
                'row_attr' => ['class' => 'form-check form-switch mb-3'], // Switch de Bootstrap
                'help'     => 'Marcar si es la persona responsable del pago/contrato.',
            ])
            ->add('nombre', TextType::class, [
                'label' => 'Nombres',
                'attr'  => ['class' => 'form-control', 'placeholder' => 'Ej: Juan Alberto', 'autocomplete' => 'given-name']
            ])
            ->add('apellido', TextType::class, [
                'label' => 'Apellidos',
                'attr'  => ['class' => 'form-control', 'placeholder' => 'Ej: Pérez López', 'autocomplete' => 'family-name']
            ])
            ->add('fechaNacimiento', DateType::class, [
                'label'    => 'F. Nacimiento',
                'widget'   => 'single_text',
                'required' => false,
                'attr'     => ['class' => 'form-control'],
                'help'     => 'Necesario para estadísticas de turismo.'
            ])
            ->add('pais', EntityType::class, [
                'label'         => 'Nacionalidad',
                'class'         => MaestroPais::class,
                'choice_label'  => 'nombre',
                'placeholder'   => 'Seleccione un país...',
                'attr'          => ['class' => 'form-select'],
                'query_builder' => function (EntityRepository $er) {
                    // UX: Ordenar alfabéticamente para facilitar la búsqueda
                    return $er->createQueryBuilder('p')
                        ->orderBy('p.nombre', 'ASC');
                },
            ])
            ->add('tipoDocumento', EntityType::class, [
                'label'         => 'Tipo Doc.',
                'class'         => MaestroDocumentoTipo::class,
                'choice_label'  => 'nombre',
                'placeholder'   => 'Seleccione...',
                'attr'          => ['class' => 'form-select'],
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('td')
                        ->orderBy('td.nombre', 'ASC');
                },
            ])
            ->add('documentoNumero', TextType::class, [
                'label' => 'Nº Documento',
                'attr'  => ['class' => 'form-control', 'placeholder' => 'DNI / Pasaporte']
            ])

            // --- ARCHIVOS MULTIMEDIA (VichUploader) ---

            // 1. Documento de Identidad
            ->add('documentoFile', VichImageType::class, [
                'label'        => 'Documento de Identidad',
                'required'     => false, // Importante: false para no obligar a resubir al editar
                'allow_delete' => true,
                'delete_label' => 'Eliminar imagen actual',
                'download_uri' => true,
                'download_label' => 'Descargar',
                'image_uri'    => true,
                'asset_helper' => true, // Usa los assets de Symfony
                'help'         => 'Sube una foto clara del DNI o Pasaporte (JPG/PNG/WEBP).',
                'attr'         => ['accept' => 'image/*'], // Restricción en navegador
            ])

            // 2. Tarjeta Andina (TAM)
            ->add('tamFile', VichImageType::class, [
                'label'        => 'Tarjeta Andina (TAM)',
                'required'     => false,
                'allow_delete' => true,
                'delete_label' => 'Eliminar',
                'download_uri' => true,
                'download_label' => 'Descargar',
                'image_uri'    => true,
                'asset_helper' => true,
                'help'         => 'Requerido obligatoriamente para la exoneración del IGV en extranjeros.',
                'attr'         => ['accept' => 'image/*,application/pdf'], // A veces la TAM es PDF
            ])

            // 3. Firma Digital
            ->add('firmaFile', VichImageType::class, [
                'label'        => 'Firma del Huésped',
                'required'     => false,
                'allow_delete' => true,
                'delete_label' => 'Borrar firma',
                'download_uri' => true,
                'download_label' => 'Ver original',
                'image_uri'    => true,
                'asset_helper' => true,
                'help'         => 'Firma de conformidad escaneada o capturada digitalmente.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PmsReservaHuesped::class,
            // Habilitar protección CSRF siempre
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => 'pms_huesped_item',
        ]);
    }
}