<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulario interno para el mapeo del campo JSON 'credentials' en MetaConfig.
 * * Este Type permite gestionar de forma individual las llaves necesarias
 * para la Graph API de Meta sin exponer la estructura JSON cruda al usuario.
 */
class MetaCredentialsType extends AbstractType
{
    /**
     * Construye los campos específicos para las credenciales de Meta.
     *
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('apiKey', PasswordType::class, [
                'label' => 'System User Access Token (API Key)',
                'help' => 'El Token permanente generado desde el Business Manager (System Users).',
                'attr' => ['placeholder' => 'EAAl7O2...'],
                'always_empty' => false,
            ])
            ->add('wabaId', TextType::class, [
                'label' => 'WhatsApp Business Account ID (WABA ID)',
                'help' => 'ID único de la cuenta comercial que es dueña de las plantillas.',
                'attr' => ['placeholder' => '9845712365410'],
            ])
            ->add('verifyToken', TextType::class, [
                'label' => 'Webhook Verify Token',
                'help' => 'String aleatorio para validar el handshake del Webhook en Meta Developers.',
                'attr' => ['placeholder' => 'Cusco_Secure_2026'],
            ]);
    }

    /**
     * Configura las opciones del formulario.
     * Se define data_class como null porque Symfony mapeará esto a un array asociativo.
     *
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}