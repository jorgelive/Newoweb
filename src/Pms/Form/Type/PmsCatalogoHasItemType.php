<?php

declare(strict_types=1);

namespace App\Pms\Form\Type;

use App\Pms\Entity\PmsCatalogoHasItem;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Enum\PmsCatalogoBloque;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Una fila del escaparate: qué contenido, en qué bloque y en qué posición.
 *
 * Espejo de PmsGuiaHasSeccionType, con dos diferencias deliberadas:
 *
 *  · **Incluye `activo`.** Los Types de la guía lo omiten, y por eso ese campo
 *    es hoy prácticamente ineditable: el formulario embebido usa el Type, no el
 *    CRUD del join, así que para ocultar una sección hay que entrar al CRUD
 *    suelto. Aquí se puede sacar algo del escaparate sin perder su bloque ni su
 *    orden, que es justo lo que se querrá hacer con una promoción caducada.
 *  · **El selector de ítems ordena por nombre interno** y muestra el tipo, para
 *    que se distingan de un vistazo en una lista larga.
 */
class PmsCatalogoHasItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('item', EntityType::class, [
                'class' => PmsGuiaItem::class,
                'query_builder' => static fn (EntityRepository $er) => $er
                    ->createQueryBuilder('i')
                    ->orderBy('i.nombreInterno', 'ASC'),
                'choice_label' => static fn (PmsGuiaItem $item): string => sprintf(
                    '%s %s',
                    match ($item->getTipo()) {
                        PmsGuiaItem::TIPO_ALBUM => '🖼️',
                        PmsGuiaItem::TIPO_AVISO => '⚠️',
                        default                 => '📄',
                    },
                    $item->getNombreInterno(),
                ),
                'attr' => ['class' => 'form-select-sm'],
                'row_attr' => ['class' => 'col-md-6'],
                'label' => 'Contenido',
                'help' => 'Puede ser un ítem de la guía (se reutiliza) o uno creado solo para el escaparate.',
            ])
            ->add('bloque', EnumType::class, [
                'class' => PmsCatalogoBloque::class,
                'choice_label' => static fn (PmsCatalogoBloque $b): string => $b->getLabel(),
                'attr' => ['class' => 'form-select-sm'],
                'row_attr' => ['class' => 'col-md-3'],
                'label' => 'Bloque',
            ])
            ->add('orden', IntegerType::class, [
                'label' => 'Orden',
                'row_attr' => ['class' => 'col-md-2'],
                'help' => 'Dentro del bloque.',
            ])
            ->add('activo', CheckboxType::class, [
                'label' => 'Visible',
                'required' => false,
                'row_attr' => ['class' => 'col-md-1'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PmsCatalogoHasItem::class,
        ]);
    }
}
