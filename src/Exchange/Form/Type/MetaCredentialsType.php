<?php
declare(strict_types=1);

namespace App\Exchange\Form\Type;

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
            ->add('apiKey', TextType::class, [
                'label' => 'System User Access Token (API Key)',
                'label_attr' => ['style' => 'display: block !important;'],
                'help' => 'El Token permanente generado desde el Business Manager.',
                'attr' => ['placeholder' => 'EAAl7O2...']
            ])
            ->add('wabaId', TextType::class, [
                'label' => 'WhatsApp Business Account ID (WABA ID)',
                'label_attr' => ['style' => 'display: block !important;'],
                'help' => 'ID único de la cuenta comercial',
                'attr' => ['placeholder' => '1278660194188157'],
            ])
            ->add('phoneId', TextType::class, [ // <-- NUEVO CAMPO
                'label' => 'Phone Number ID',
                'label_attr' => ['style' => 'display: block !important;'],
                'help' => 'Phone Number ID Identificador del número de teléfono desde el cual se envían los mensajes.',
                'attr' => ['placeholder' => '979596945245997'],
            ])
            ->add('verifyToken', TextType::class, [
                'label' => 'Webhook Verify Token',
                'label_attr' => ['style' => 'display: block !important;'],
                'help' => 'String aleatorio que TE INVENTAS TÚ. Sólo se usa una vez, en el '
                    . 'handshake al dar de alta el webhook en Meta: tiene que coincidir con el '
                    . 'que escribiste allí. No protege los mensajes.',
                'attr' => ['placeholder' => 'Cusco_Secure_2026'],
            ])
            ->add('appSecret', TextType::class, [
                'label' => 'App Secret (firma de los webhooks)',
                'label_attr' => ['style' => 'display: block !important;'],
                // ⚠️ Se confunde con el Verify Token y NO tienen nada que ver. Aquél te lo
                // inventas y sólo vale para el alta; éste lo genera Meta y es el que firma
                // CADA mensaje entrante. Sin él, cualquiera que conozca la URL del webhook
                // puede hacerse pasar por el número de un operador.
                'help' => 'NO te lo inventas: lo genera Meta. developers.facebook.com → tu app '
                    . '→ Configuración → Básica → «Clave secreta de la aplicación» → Mostrar. '
                    . 'Son 32 caracteres hexadecimales. Con él se comprueba la firma de cada '
                    . 'webhook entrante; <strong>mientras esté vacío se acepta cualquier '
                    . 'payload</strong> y el log lo avisa en cada petición.',
                'attr' => ['placeholder' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6'],
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
        $resolver->setDefined(['allow_add', 'allow_delete', 'delete_empty', 'entry_options', 'entry_type']);
    }
}