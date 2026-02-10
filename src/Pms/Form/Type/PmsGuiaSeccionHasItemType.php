<?php

declare(strict_types=1);

namespace App\Pms\Form\Type;

use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaSeccionHasItem;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PmsGuiaSeccionHasItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('item', EntityType::class, [
                'class' => PmsGuiaItem::class,
                'choice_label' => function (PmsGuiaItem $item) {
                    // 1. Asignamos un icono visual según el tipo
                    $icono = match ($item->getTipo()) {
                        PmsGuiaItem::TIPO_WIFI     => '📶', // WiFi
                        PmsGuiaItem::TIPO_VIDEO    => '🎥', // Video
                        PmsGuiaItem::TIPO_MAPA     => '🗺️', // Mapa
                        PmsGuiaItem::TIPO_ALBUM    => '📸', // Álbum
                        PmsGuiaItem::TIPO_CONTACTO => '📞', // Contacto
                        default                    => '📄', // Tarjeta/Texto
                    };

                    // 2. Priorizamos el Nombre Interno (que hicimos obligatorio)
                    // Formato: "📶 Wifi Invitados (Cusco)"
                    return sprintf('%s %s', $icono, $item->getNombreInterno());
                },
                'placeholder' => 'Seleccione un ítem de la biblioteca...',
                'attr' => ['class' => 'form-select-sm'],
                'row_attr' => ['class' => 'col-md-9'],
                'label' => 'Ítem de Contenido'
            ])
            ->add('orden', IntegerType::class, [
                'label' => 'Orden',
                'data' => 0,
                'row_attr' => ['class' => 'col-md-3'],
                'attr' => ['min' => 0]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PmsGuiaSeccionHasItem::class,
        ]);
    }
}