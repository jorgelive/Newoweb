<?php

namespace App\Pms\Form\Type;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsUnidad;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PmsEventoCalendarioEmbeddedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('descripcion', TextType::class, [
                'required' => false,
                'label' => 'Descripción',
                'attr' => ['placeholder' => 'Notas internas'],
            ])
            ->add('pmsUnidad', EntityType::class, [
                'class' => PmsUnidad::class,
                'choice_label' => 'nombre',
                'label' => 'Unidad',
                'placeholder' => 'Seleccione Unidad',
            ])
            ->add('inicio', DateTimeType::class, [
                'widget' => 'single_text',
                'html5' => true,
                'label' => 'Inicio',
                'required' => true,
                'attr' => [
                    'step' => 60,
                    'data-controller' => 'panel--pms-reserva--form-evento-fechas',
                    'data-action' => 'change->panel--pms-reserva--form-evento-fechas#updateEnd',
                ],
            ])
            ->add('fin', DateTimeType::class, [
                'widget' => 'single_text',
                'html5' => true,
                'label' => 'Fin',
                'required' => true,
                'attr' => ['step' => 60],
            ])
            ->add('estado', EntityType::class, [
                'class' => PmsEventoEstado::class,
                'choice_label' => 'nombre',
                'label' => 'Estado',
            ])
            ->add('estadoPago', EntityType::class, [
                'class' => PmsEventoEstadoPago::class,
                'choice_label' => 'nombre',
                'label' => 'Pago',
            ])
            ->add('cantidadAdultos', IntegerType::class, [
                'required' => false,
                'label' => 'ADL',
                'attr' => ['min' => 0],
            ])
            ->add('cantidadNinos', IntegerType::class, [
                'required' => false,
                'label' => 'CHD',
                'attr' => ['min' => 0],
            ])
            ->add('monto', MoneyType::class, [
                'required' => false,
                'currency' => 'USD',
                'divisor' => 1,
                'scale' => 2,
                'html5' => true,
                'label' => 'Monto',
            ])
            ->add('comision', MoneyType::class, [
                'required' => false,
                'currency' => 'USD',
                'divisor' => 1,
                'scale' => 2,
                'html5' => true,
                'label' => 'Comisión',
            ])

            // ✅ Colección: limpieza / mantenimiento / etc.
            ->add('assignments', CollectionType::class, [
                'entry_type' => PmsEventAssignmentEmbeddedType::class,
                'entry_options' => [],
                'by_reference' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'label' => 'Personal / Actividades',
                'attr' => [
                    'data-controller' => 'panel--pms-event-assignment--collection',
                ],
            ])
        ;

        // Bloqueo dinámico de campos para OTAs
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $evento = $event->getData();
            $form = $event->getForm();

            if ($evento instanceof PmsEventoCalendario && $evento->isOta()) {
                $camposBloqueados = [
                    'pmsUnidad', 'inicio', 'fin',
                    'cantidadAdultos', 'cantidadNinos',
                    'monto', 'comision',
                    'assignments',
                ];

                foreach ($camposBloqueados as $nombre) {
                    if (!$form->has($nombre)) {
                        continue;
                    }
                    $config = $form->get($nombre)->getConfig();
                    $options = $config->getOptions();
                    $options['disabled'] = true;
                    $form->add($nombre, \get_class($config->getType()->getInnerType()), $options);
                }
            }
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $evento = $form->getData();

        if ($evento instanceof PmsEventoCalendario && $evento->isOta()) {
            if (!isset($view->vars['attr'])) {
                $view->vars['attr'] = [];
            }

            $view->vars['attr']['class'] = trim(($view->vars['attr']['class'] ?? '') . ' pms-ota-locked-widget');
            $view->vars['attr']['data-controller'] = 'panel--pms-reserva--ota-row-lock';
            $view->vars['attr']['title'] = 'Reserva externa (OTA): Protegida contra borrado y edición.';
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PmsEventoCalendario::class]);
    }
}