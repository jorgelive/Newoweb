<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Panel\Form\Type\TranslationTextType; // Tu clase genérica para textos simples
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
 * * * OPTIMIZACIÓN GREENFIELD: Arquitectura dividida en Header (Estricto), Body (Estricto con Estado),
 * Footer (Genérico) y Buttons Map (Dinámico) para maximizar compatibilidad con Meta y proteger variables del PMS.
 */
class WhatsappMetaTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('is_active', CheckboxType::class, [
                'label' => 'Activar canal WhatsApp (Meta)',
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('is_official_meta', CheckboxType::class, [
                'label' => 'Es plantilla oficial de Meta',
                'required' => false,
                'help' => 'Desmárcalo si es un "Quick Reply" interno del PMS. Las plantillas no oficiales solo pueden enviarse dentro de la ventana de 24 horas de atención al cliente.',
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('meta_template_name', TextType::class, [
                'label' => 'Nombre Base de Plantilla Oficial',
                'required' => false,
                'attr' => ['placeholder' => 'Ej: welcome_confirmation'],
                'help' => 'El nombre exacto aprobado en Facebook Business Manager. (Ignorar si es un Quick Reply).',
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
            // 1. EL ENCABEZADO (Header) - Usa Formulario Específico
            // =========================================================================
            ->add('header', CollectionType::class, [
                'entry_type' => WhatsappMetaHeaderType::class,
                'label' => 'Encabezados (Header)',
                'help' => 'El formato es protegido por la sincronización de Meta. Si es texto, soporta variables como {{guest_name}}.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => false],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])

            // =========================================================================
            // 2. EL CUERPO (Body) - Usa Formulario Específico
            // =========================================================================
            ->add('body', CollectionType::class, [
                'entry_type' => WhatsappMetaBodyType::class,
                'label' => 'Textos Base, Variables y Estado',
                'help' => 'El estado (Aprobada/Pendiente) se sincroniza desde Meta. Las variables se extraen automáticamente al enviar.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => false],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])

            // =========================================================================
            // 3. EL PIE DE PÁGINA (Footer) - Usa Formulario Genérico
            // =========================================================================
            ->add('footer', CollectionType::class, [
                'entry_type' => TranslationTextType::class,
                'label' => 'Pies de Página (Footer)',
                'help' => 'Aparecerá en letra pequeña gris al final del mensaje. Meta NO permite el uso de variables aquí.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => false],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-4'],
            ])

            // =========================================================================
            // 4. LOS BOTONES (Buttons Map) - Usa Formulario Específico
            // =========================================================================
            ->add('buttons_map', CollectionType::class, [
                'entry_type' => WhatsappMetaButtonType::class,
                'label' => 'Botones Dinámicos de la Plantilla',
                'help' => 'Configura las variables de enlace. El "Valor Nativo (Meta)" es intocable, debes definir la "Variable del Sistema" (resolver_key) para inyectar URLs del PMS.',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'entry_options' => ['label' => false],
                'attr' => ['class' => 'pms-flat-collection'],
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // CRÍTICO: data_class en null asegura que Symfony devuelva un arreglo asociativo
            // y no intente mapear a un objeto estándar, manteniendo la compatibilidad JSON.
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