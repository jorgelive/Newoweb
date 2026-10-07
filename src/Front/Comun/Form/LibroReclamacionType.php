<?php

declare(strict_types=1);

namespace App\Front\Comun\Form;

use App\Front\Comun\Entity\LibroReclamacion;
use App\Front\Comun\Enum\LibroReclamacionBienEnum;
use App\Front\Comun\Enum\LibroReclamacionTipoEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * La hoja de reclamación pública. Las etiquetas son claves de `translations/front.*.yaml` (dominio común).
 */
final class LibroReclamacionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('consumidorNombre', TextType::class, ['label' => 'libro.campo.nombre'])
            ->add('consumidorDocumentoTipo', ChoiceType::class, [
                'label' => 'libro.campo.doc_tipo',
                'choices' => ['DNI' => 'DNI', 'libro.doc.ce' => 'CE', 'libro.doc.pasaporte' => 'PASAPORTE', 'RUC' => 'RUC'],
            ])
            ->add('consumidorDocumentoNumero', TextType::class, ['label' => 'libro.campo.doc_numero'])
            ->add('consumidorDomicilio', TextType::class, ['label' => 'libro.campo.domicilio'])
            ->add('consumidorTelefono', TextType::class, ['label' => 'libro.campo.telefono', 'required' => false])
            ->add('consumidorEmail', EmailType::class, ['label' => 'libro.campo.email'])
            ->add('consumidorEsMenor', CheckboxType::class, ['label' => 'libro.campo.menor', 'required' => false])
            ->add('apoderadoNombre', TextType::class, ['label' => 'libro.campo.apoderado', 'required' => false])
            ->add('bienTipo', EnumType::class, [
                'label' => 'libro.campo.bien_tipo',
                'class' => LibroReclamacionBienEnum::class,
                'expanded' => true,
                'choice_label' => static fn (LibroReclamacionBienEnum $b): string => 'libro.bien.' . $b->value,
            ])
            ->add('bienDescripcion', TextareaType::class, ['label' => 'libro.campo.bien_descripcion'])
            ->add('bienMonto', TextType::class, ['label' => 'libro.campo.monto', 'required' => false])
            ->add('tipo', EnumType::class, [
                'label' => 'libro.campo.tipo',
                'class' => LibroReclamacionTipoEnum::class,
                'expanded' => true,
                'choice_label' => static fn (LibroReclamacionTipoEnum $t): string => 'libro.tipo.' . $t->value,
            ])
            ->add('detalle', TextareaType::class, ['label' => 'libro.campo.detalle'])
            ->add('pedido', TextareaType::class, ['label' => 'libro.campo.pedido'])
            ->add('acepto', CheckboxType::class, [
                'label' => 'libro.campo.acepto',
                'mapped' => false,
                'constraints' => [new IsTrue(message: 'libro.error.acepto')],
            ])
            // Trampa para robots: invisible para una persona. Si llega con texto, el controlador
            // finge éxito y no guarda nada (decirle al robot que falló sólo le enseña a evitarlo).
            ->add('sitioWeb', TextType::class, ['mapped' => false, 'required' => false, 'label' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LibroReclamacion::class,
            'translation_domain' => 'front',
            // Debe estar en `framework.csrf_protection.stateless_token_ids`: sin sesión.
            'csrf_token_id' => 'front_libro_reclamacion',
        ]);
    }
}
