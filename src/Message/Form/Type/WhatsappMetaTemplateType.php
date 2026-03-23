<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulario maestro para gestionar el nodo JSON whatsappMetaTmpl.
 * Trabaja devolviendo arreglos asociativos puros, sin instanciar clases de datos.
 * * * OPTIMIZACIÓN GREENFIELD: Se ha eliminado language_mapping. El estado de
 * aprobación ahora se gestiona directamente en el 'body'. Se incorpora 'buttons_map'
 * para separar la configuración de botones físicos de la lógica de variables nominales.
 */
class WhatsappMetaTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('is_active', CheckboxType::class, [
                'label' => 'Activar envío por WhatsApp (Meta)',
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('meta_template_name', TextType::class, [
                'label' => 'Nombre Base de Plantilla Oficial',
                'required' => false,
                'attr' => ['placeholder' => 'Ej: welcome_confirmation'],
                'help' => 'El nombre exacto aprobado en Facebook Business Manager.',
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Categoría de la Plantilla',
                'required' => false,
                'choices' => [
                    'Utility (Servicio)' => 'UTILITY',
                    'Marketing' => 'MARKETING',
                    'Authentication' => 'AUTHENTICATION',
                ],
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])

            // =========================================================================
            // 1. EL CUERPO (Texto Base + Variables + Estado de Aprobación)
            // =========================================================================
            ->add('body', CollectionType::class, [
                'entry_type' => WhatsappMetaBodyType::class, // ← Usa el nuevo form type que creamos
                'label' => 'Textos Base, Variables y Estado',
                'help' => '¡IMPORTANTE! El sistema escanea este texto automáticamente para extraer variables como {{ guest_name }} y enviarlas a Meta. El estado indica si la traducción está aprobada.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => false], // Ocultamos el label individual para un diseño más limpio
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])

            // =========================================================================
            // 2. LOS BOTONES (Variables de URL + Traducción de Etiquetas para 24h)
            // =========================================================================
            ->add('buttons_map', CollectionType::class, [
                'entry_type' => WhatsappMetaButtonType::class, // ← Usa el form type de botones
                'label' => 'Botones Dinámicos de la Plantilla',
                'help' => 'Configura las variables (ej: {{url_checkin}}) de los botones de Meta. Los textos traducidos se usarán para emular el botón si el mensaje se envía como texto libre (ventana 24h).',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => 'Configuración de Botón'],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // data_class en null asegura que Symfony devuelva un arreglo asociativo y no intente mapear a un objeto
            'data_class' => null,
        ]);

        // Atributos definidos explícitamente para compatibilidad con EasyAdmin y colecciones anidadas
        $resolver->setDefined([
            'allow_add',
            'allow_delete',
            'delete_empty',
            'entry_options',
            'entry_type',
        ]);
    }
}