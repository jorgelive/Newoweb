<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsReserva;
use App\Pms\Form\Type\PmsEventoCalendarioEmbeddedType;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Sonata\Form\Type\DatePickerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Sonata\AdminBundle\Form\Type\ModelType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Sonata\Form\Type\DateTimePickerType;

class PmsReservaAdmin extends AbstractAdmin
{
    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_by'] = 'fechaLlegada';
        $sortValues['_sort_order'] = 'DESC';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            // =====================
            // Datos personales
            // =====================
            ->with('Datos personales', ['class' => 'col-md-4'])
                // Canal es crítico: arriba de todo, siempre visible, no editable
                ->add('channel', ModelType::class, [
                    'required' => true,
                    'disabled' => true,
                    'btn_add' => false,
                    'label' => 'Canal',
                ])
                ->add('nombreCliente', TextType::class, [
                    'required' => false,
                    'label' => 'Nombre',
                ])
                ->add('apellidoCliente', TextType::class, [
                    'required' => false,
                    'label' => 'Apellido',
                ])
                ->add('emailCliente', TextType::class, [
                    'required' => false,
                    'label' => 'Email',
                ])
                ->add('telefono', TextType::class, [
                    'required' => false,
                    'label' => 'Teléfono',
                ])
                ->add('telefono2', TextType::class, [
                    'required' => false,
                    'label' => 'Teléfono 2',
                ])
                ->add('pais', ModelType::class, [
                    'required' => false,
                    'label' => 'País',
                    'btn_add' => false,
                ])
                ->add('idioma', ModelType::class, [
                    'required' => true,
                    'label' => 'Idioma',
                    'btn_add' => false,
                ])
                ->add('datosLocked', CheckboxType::class, [
                    'required' => false,
                    'label' => 'Datos bloqueados',
                    'help' => 'Si está activo, en re-sincronizaciones no se sobrescriben los datos personales. Si lo desmarcas, la próxima sincronización actualizará nombre/email/teléfono.',
                ])
            ->end()

            // =====================
            // Eventos de calendario
            // =====================
            ->with('Eventos', ['class' => 'col-md-4'])
                ->add('eventosCalendario', CollectionType::class, [
                    'entry_type' => PmsEventoCalendarioEmbeddedType::class,
                    'entry_options' => ['label' => false],
                    'allow_add' => true,
                    'allow_delete' => true,
                    'by_reference' => false,
                    'prototype' => true,
                    'label' => false,
                ])
            ->end()

            // =====================
            // Fechas y ocupación
            // =====================
            ->with('Fechas y ocupación', [
                    'class' => 'col-md-4',
                    'box_class' => 'box box-info js-accordion js-accordion--collapsed',
                ]
            )
                ->add('fechaLlegada', DatePickerType::class, [
                    'required' => false,
                    'disabled' => true,
                    'format' => 'yyyy/MM/dd',
                    'help' => 'Campo calculado automáticamente desde los eventos de calendario (check-in). Para cambiar fechas, edita el evento correspondiente.',
                ])
                ->add('fechaSalida', DatePickerType::class, [
                    'required' => false,
                    'disabled' => true,
                    'format' => 'yyyy/MM/dd',
                    'help' => 'Campo calculado automáticamente desde los eventos de calendario (check-out). Para cambiar fechas, edita el evento correspondiente.',
                ])
                ->add('horaLlegadaCanal', TextType::class, [
                    'required' => false,
                    'disabled' => true,
                    'label' => 'Hora llegada (Beds24)',
                    'help' => 'Solo lectura. Valor crudo arrivalTime desde Beds24.',
                ])
                ->add('fechaReservaCanal', DateTimePickerType::class, [
                    'required' => false,
                    'disabled' => true,
                    'label' => 'Fecha reserva (canal)',
                    'format' => 'yyyy/MM/dd HH:mm',
                ])
                ->add('fechaModificacionCanal', DateTimePickerType::class, [
                    'required' => false,
                    'disabled' => true,
                    'label' => 'Modificación (canal)',
                    'format' => 'yyyy/MM/dd HH:mm',
                ])
                ->add('cantidadAdultos', IntegerType::class, [
                    'required' => false,
                    'disabled' => true,
                    'label' => 'Adultos (total)',
                    'help' => 'Campo calculado automáticamente a partir de los eventos de calendario.',
                ])
                ->add('cantidadNinos', IntegerType::class, [
                    'required' => false,
                    'disabled' => true,
                    'label' => 'Niños (total)',
                    'help' => 'Campo calculado automáticamente a partir de los eventos de calendario.',
                ])
            ->end()

            // =====================
            // Montos
            // =====================
            ->with('Montos', [
                    'class' => 'col-md-4',
                    'box_class' => 'box box-info js-accordion js-accordion--collapsed',
                ]
            )
                ->add('montoTotal', MoneyType::class, [
                    'required' => false,
                    'currency' => false,
                    'disabled' => true,
                    'label' => 'Monto total (USD)',
                    'help' => 'Campo calculado automáticamente como suma de los montos de los eventos de calendario. Para cambiar el monto, edita los eventos (o sincroniza desde el canal).',
                ])
                ->add('comisionTotal', MoneyType::class, [
                    'required' => false,
                    'currency' => false,
                    'disabled' => true,
                    'label' => 'Comisión total (USD)',
                    'help' => 'Campo calculado automáticamente como suma de las comisiones de los eventos de calendario. Para cambiar la comisión, edita los eventos (o sincroniza desde el canal).',
                ])
            ->end()

            // =====================
            // Identificadores (solo lectura)
            // =====================
            ->with('Identificadores', [
                    'class' => 'col-md-4',
                    'box_class' => 'box box-info js-accordion js-accordion--collapsed',
                ]
            )
                ->add('referenciaCanal', TextType::class, [
                    'required' => false,
                    'label' => 'Referencia canal / apiReference',
                    'disabled' => true,
                    'help' => 'Beds24: apiReference cuando es OTA. Booking/Airbnb: referencia del canal. Campo de trazabilidad, no se edita.',
                ])
                ->add('beds24MasterId', null, [
                    'required' => false,
                    'label' => 'Beds24 Master ID',
                    'disabled' => true,
                    'help' => 'Identificador de grupo en Beds24 (si Beds24 agrupa múltiples habitaciones). Se completa automáticamente.',
                ])
                ->add('beds24BookIdPrincipal', null, [
                    'required' => false,
                    'label' => 'Beds24 Book ID (fallback)',
                    'disabled' => true,
                    'help' => 'Se usa cuando masterId es null (reservas no agrupadas). Corresponde al campo "id" del payload /api/v2/bookings.',
                ])
            ->end()



            // =====================
            // Notas
            // =====================
            ->with('Notas', ['class' => 'col-md-12'])
                ->add('nota', TextareaType::class, [
                    'required' => false,
                    'attr' => ['rows' => 4],
                ])
                ->add('comentariosHuesped', TextareaType::class, [
                    'required' => false,
                    'disabled' => true,
                    'label' => 'Comentarios del huésped (Beds24)',
                    'help' => 'Solo lectura. Sincronizado desde Beds24 (comments).',
                    'attr' => ['rows' => 4],
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('referenciaCanal')
            ->add('beds24MasterId')
            ->add('beds24BookIdPrincipal')
            ->add('nombreCliente')
            ->add('apellidoCliente')
            ->add('emailCliente')
            ->add('channel')
            ->add('pais')
            ->add('idioma')
            ->add('fechaLlegada')
            ->add('fechaSalida');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->add('beds24MasterId')
            ->add('beds24BookIdPrincipal')
            ->add('nombreCliente')
            ->add('apellidoCliente')
            ->add('cantidadAdultos')
            ->add('cantidadNinos')
            ->add('channel')
            ->add('idioma')
            ->add('fechaLlegada', null, [
                'format' => 'Y/m/d',
            ])
            ->add('fechaSalida', null, [
                'format' => 'Y/m/d',
            ])
            ->add('montoTotal', null, [
                'label' => 'Monto (USD)',
            ])
            ->add('comisionTotal', null, [
                'label' => 'Comisión (USD)',
            ])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'actions' => [
                    'edit' => [],
                    'delete' => [],
                    'show' => [],
                ],
            ]);
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('referenciaCanal')
            ->add('beds24MasterId')
            ->add('beds24BookIdPrincipal')
            ->add('nombreCliente')
            ->add('apellidoCliente')
            ->add('emailCliente')
            ->add('telefono')
            ->add('telefono2')
            ->add('datosLocked')
            ->add('fechaLlegada', null, [
                'format' => 'Y/m/d',
            ])
            ->add('fechaSalida', null, [
                'format' => 'Y/m/d',
            ])
            ->add('horaLlegadaCanal')
            ->add('fechaReservaCanal', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('fechaModificacionCanal', null, [
                'format' => 'Y/m/d H:i',
            ])
            ->add('cantidadAdultos')
            ->add('cantidadNinos')
            ->add('channel')
            ->add('pais')
            ->add('idioma')
            ->add('montoTotal', null, [
                'label' => 'Monto total (USD)',
            ])
            ->add('comisionTotal', null, [
                'label' => 'Comision total (USD)',
            ])
            ->add('nota')
            ->add('comentariosHuesped')
            ->add('created')
            ->add('updated');
    }
}
