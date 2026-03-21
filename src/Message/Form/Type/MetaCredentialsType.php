<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MetaCredentialsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('apiKey', PasswordType::class, [
                'label' => 'System User Access Token (API Key)',
                'help' => 'El Token permanente generado desde el Business Manager.',
                'attr' => ['placeholder' => 'EAAl7O2...'],
                'always_empty' => false,
            ])
            ->add('wabaId', TextType::class, [
                'label' => 'WhatsApp Business Account ID (WABA ID)',
                'help' => 'ID único de la cuenta comercial.',
                'attr' => ['placeholder' => '1278660194188157'],
            ])
            ->add('phoneId', TextType::class, [ // <-- NUEVO CAMPO
                'label' => 'Phone Number ID',
                'help' => 'Identificador del número de teléfono desde el cual se envían los mensajes.',
                'attr' => ['placeholder' => '1064111656781449'],
            ])
            ->add('verifyToken', TextType::class, [
                'label' => 'Webhook Verify Token',
                'help' => 'String aleatorio para validar el handshake del Webhook.',
                'attr' => ['placeholder' => 'Cusco_Secure_2026'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
        $resolver->setDefined(['allow_add', 'allow_delete', 'delete_empty', 'entry_options', 'entry_type']);
    }
}