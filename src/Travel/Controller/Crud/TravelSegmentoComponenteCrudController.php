<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Helper\AdminFieldHelper;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Enum\ComponenteModoEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;

/**
 * Controlador CRUD encargado de gestionar la relación asociativa entre Segmentos de Itinerario
 * y sus componentes logísticos / tarifas asignadas.
 */
class TravelSegmentoComponenteCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelSegmentoComponente::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->showEntityActionsInlined();
    }

    /**
     * @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface>
     */
    public function configureFields(string $pageName): iterable
    {
        $apiHostUrl = rtrim($this->getParameter('api_host_url'), '/');
        $endpointUrl = $apiHostUrl . '/platform/travel/tarifas';

        /* ====================================================================
         * FILA 1: COMPONENTE LOGÍSTICO (GATILLO AJAX) Y CONTEXTO DE PLANTILLA
         * Se pasan explícitamente los parámetros para cumplir el contrato agnóstico.
         * ==================================================================== */
        yield AdminFieldHelper::controlsAjax(
            AssociationField::new('componente', 'Componente Logístico'),
            'js-tarifa-api-target',
            $endpointUrl,
            'componente_id',
            'nombreInterno'
        )
            ->setColumns('col-12 col-md-6')
            ->setFormTypeOption('choice_label', 'nombreInterno');

        yield AssociationField::new('itinerarioContexto', 'Condicionado a Plantilla')
            ->setColumns('col-12 col-md-6')
            ->setFormTypeOption('choice_label', 'nombreInterno');

        /* ====================================================================
         * FILA 2: TARIFA PREDETERMINADA (TARGET AJAX)
         * ==================================================================== */
        yield AssociationField::new('tarifaPredeterminada', 'Tarifa (Opcional)')
            ->autocomplete()
            ->setColumns('col-12')
            ->setFormTypeOptions([
                'attr' => [
                    'class' => 'js-tarifa-api-target'
                ],
            ]);

        /* ====================================================================
         * FILA 3: CONFIGURACIÓN HORARIA, MODO COMERCIAL Y FILTROS OPERATIVOS
         * ==================================================================== */
        yield TimeField::new('hora', 'Hora Inicio')
            ->setHelp(self::ayudaPlegable(
                'La hora <strong>coloca</strong> el bloque en el día del pasajero; no sólo lo describe.',
                'La guía agrupa por servicio y ordena los grupos así: '
                . '<strong>1.</strong> los que tienen hora, por su hora <em>más temprana</em>; '
                . '<strong>2.</strong> los que no tienen ninguna; '
                . '<strong>3.</strong> las estadías, siempre al final.<br>'
                . '⚠️ Un grupo <strong>sin ninguna hora va después de TODO lo que tenga hora</strong>. '
                . 'Un «Día libre en el resort» sin hora, en un día con salida nocturna a las 22:00, '
                . 'pintaba la discoteca primero y el día libre detrás. Por eso lleva 07:00 aunque '
                . 'cubra la jornada entera: aquí la hora <em>posiciona</em>.<br>'
                . '⚠️ Y el grupo es <strong>atómico</strong>: se coloca por su hora más temprana y '
                . 'todo lo suyo se pinta seguido. Un servicio con desayuno (07:00) y cena (19:00) '
                . 'se mete ENTERO delante de una excursión de las 08:00. Para intercalar algo, hay '
                . 'que <strong>partir el día en dos cotservicios</strong>.<br>'
                . '⚠️ <strong>Un ALOJAMIENTO no puede llevar hora</strong> y está validado: la guía '
                . 'deduce de «sin horas» que es una estadía, y con hora dejaría de repetirse cada '
                . 'noche —saldría sólo el primer día—. La hora de llegada al hotel va en '
                . '<strong>«Hora de recojo»</strong> de La Biblia, que es del operador y no viaja '
                . 'a la propuesta.<br>'
                . 'Ver «Cómo se ordena un día» en <code>docs/Cotizaciones.md</code> §6.u.'
            ))
            ->setFormat('HH:mm')
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5'  => false,
                'attr'   => [
                    'data-controller' => 'panel--flatpickr-time',
                    'class'           => 'form-control text-center fw-bold text-success font-monospace',
                    'style'           => 'cursor: pointer;'
                ],
            ])
            ->setColumns('col-12 col-md-2');

        yield TimeField::new('horaFin', 'Hora Fin')
            ->setFormat('HH:mm')
            ->setColumns('col-12 col-md-2')
            ->setHelp('Dejar vacío para usar la duración por defecto.')
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5'  => false,
                'attr'   => [
                    'data-controller' => 'panel--flatpickr-time',
                    'class'           => 'form-control text-center fw-bold text-danger font-monospace',
                    'style'           => 'cursor: pointer;'
                ],
            ]);

        yield IntegerField::new('dia', 'Día (Filtro)')
            ->setHelp('Día específico en la plantilla para aplicar esta logística (Ej: 2). Dejar vacío para aplicar todos los días.')
            ->setColumns('col-12 col-md-2')
            ->setFormTypeOptions([
                'attr' => ['placeholder' => 'Global']
            ]);

        yield ChoiceField::new('modo', 'Modo Comercial')
            ->setChoices(array_reduce(ComponenteModoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e], []))
            ->formatValue(static fn ($value) => $value instanceof ComponenteModoEnum ? $value->value : $value)
            ->setFormTypeOptions([
                'placeholder' => false
            ])
            ->setColumns('col-12 col-md-3');

        yield IntegerField::new('orden', 'Orden')
            ->setHelp(self::ayudaPlegable(
                'Ordena <strong>dentro</strong> del servicio. Entre servicios manda la hora.',
                'Dentro de un mismo servicio los segmentos se pintan por este número. Entre '
                . 'servicios distintos no pinta nada: ahí decide la hora, y sólo cuando NINGUNO '
                . 'de los dos grupos tiene hora se recurre al <code>orden</code> más bajo como '
                . 'desempate.<br>'
                . 'Es lo que sostiene el guion de una excursión, donde los segmentos que sólo '
                . 'cuentan —el malecón, la tarde libre— no llevan componente y por tanto no '
                . 'tienen hora. Ver <code>docs/Travel.md</code> §11.quinquies.'
            ))
            ->setColumns('col-12 col-md-3')
            ->setFormTypeOptions([
                'empty_data' => '1',
                'attr'       => [
                    'placeholder' => '1',
                    'data-default'    => '1',
                ],
            ])
            ->hideOnIndex();

        /* ====================================================================
         * FILA 4: HORA DE SERVICIO COMPLETO
         * La hora de este componente representa el horario de toda la excursión
         * (servicio/itinerario), no solo la del segmento donde se ancla. Se
         * admite uno promovido por cada día de la plantilla (itinerarioContexto,
         * día); la unicidad la garantiza al guardar el listener de Doctrine.
         * ==================================================================== */
        yield BooleanField::new('horaServicioCompleto', 'Servicio principal del día')
            ->setHelp(
                'Marca cuál es el SERVICIO PRINCIPAL de ese día. Hace dos cosas: su hora representa '
                . 'el horario de toda la excursión, y de él salen el punto de recojo y el de entrega '
                . 'que se le mandan al proveedor (los toma del primer y del último segmento del día). '
                . '<br><br><b>Para paquetes de varios días</b> —un Camino Inca de 4— se crea un componente '
                . 'por día («Segundo día Camino Inca»), aunque sea de <b>costo 0</b>, sólo para que aporte '
                . 'la hora de inicio y fin y se promueva aquí. <b>Ojo con la Categoría Operativa</b>: si la '
                . 'pones «extras» o «ticket», el componente NO tiene puntos de recojo y esto no hará nada. '
                . 'Usa la que refleje la realidad (pool, privada, transporte).'
                . '<br><br>Requiere elegir una plantilla en "Condicionado a Plantilla" (no aplica a Global). '
                . 'Se admite uno por cada día; al activarlo se desactiva cualquier otro del mismo día.'
            )
            ->setColumns('col-12')
            ->renderAsSwitch(true);
    }
}