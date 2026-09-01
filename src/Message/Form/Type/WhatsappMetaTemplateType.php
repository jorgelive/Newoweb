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
                'help' => 'Decide si el canal de WhatsApp <b>se ofrece siquiera</b> para esta plantilla — a mano y al agente. '
                    . '⚠️ <b>No confundir con la casilla de abajo:</b> una plantilla pensada sólo para texto libre va '
                    . '<b>activada</b> aquí y <b>no oficial</b> abajo. Apagando esto no sale por WhatsApp ni dentro de la ventana.',
                'help_html' => true,
                'required' => false,
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('is_official_meta', CheckboxType::class, [
                'label' => 'Es plantilla oficial de Meta',
                'required' => false,
                'help' => 'Decide si puede salir <b>fuera</b> de la ventana de 24 h. Desmárcalo si no está aprobada en Meta '
                    . '(un «Quick Reply» interno, o una plantilla que sólo existe como texto libre). '
                    . 'Sin esto, fuera de la ventana el envío se rechaza con un aviso — que es lo correcto: ahí hay que mandar una aprobada.',
                'help_html' => true,
                'row_attr' => ['class' => 'col-md-12 mb-3'],
            ])
            ->add('meta_template_name', TextType::class, [
                'label' => 'Nombre Base de Plantilla Oficial',
                'required' => false,
                'attr' => ['placeholder' => 'Ej: welcome_confirmation'],
                'help' => 'El nombre exacto aprobado en Facebook Business Manager. Sin él, la plantilla es <b>invisible en las dos direcciones</b>: ni se sube ni se reconoce al sincronizar.<br>'
                    . '⚠️ <b>Ponle sufijo desde el primer día</b> (<code>pago_v1</code>). Meta no deja editar una plantilla aprobada: reescribirla es crear otra y borrar la vieja, '
                    . 'y <b>borrar bloquea ese nombre 30 días</b>. Con sufijo, la siguiente es <code>_v2</code> y no te topas con el bloqueo.',
                'help_html' => true,
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
                'help' => '<b>Este cuerpo sólo se usa FUERA de la ventana de 24 h.</b> Dentro sale el de «Enlace WhatsApp», que es más largo y libre.<br><br>'
                    . '<b>Límites de Meta:</b> 1024 caracteres; el texto no puede empezar ni acabar con una variable, ni llevar dos seguidas; '
                    . 'y <b>ningún parámetro puede tener saltos de línea</b>. Por eso <code>{{bloque_pago}}</code> no cabe aquí —son varias líneas— '
                    . 'y sí cabe <code>{{importe_a_pagar}}</code>, que es una.<br><br>'
                    . 'El estado (Aprobada/Pendiente) lo sincroniza Meta. Las variables se convierten al formato posicional al enviarlas.',
                'help_html' => true,
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