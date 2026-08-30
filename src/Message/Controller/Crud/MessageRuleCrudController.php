<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Contract\ConversationMilestoneInterface;
use App\Message\Entity\MessageRule;
use App\Message\Service\MessageSegmentationAggregator;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends BaseCrudController<MessageRule>
 */
class MessageRuleCrudController extends BaseCrudController
{
    // 🔥 Inyectamos el AGREGADOR maestro que leerá del PMS, Tours, etc.
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly MessageSegmentationAggregator $segmentationAggregator
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MessageRule::class;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->avisarResincronizacion($entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $this->avisarResincronizacion($entityInstance);
    }

    /**
     * Guardar una regla NO reprograma las conversaciones existentes.
     *
     * Es deliberado: el motor evalúa conversación a conversación y un barrido de todas las
     * abiertas dentro de un request de admin puede tardar minutos y dejar la petición colgada.
     * En vez de hacerlo a medias, se le dice al operador el comando exacto — que es la misma
     * herramienta que usa el cron. Ver docs/Mensajeria.md §6.
     */
    private function avisarResincronizacion(mixed $entityInstance): void
    {
        if (!$entityInstance instanceof MessageRule) {
            return;
        }

        $this->addFlash('warning', sprintf(
            'La regla "%s" queda guardada, pero las reservas ya existentes no se reprograman solas: '
            . 'sólo se re-evalúan cuando la reserva cambia o cuando corre el barrido. '
            . 'Para aplicarla ahora: php bin/console app:message:sync-rules --all',
            $entityInstance->getName()
        ));
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DETAIL, Roles::MENSAJES_SHOW)
            ->setPermission(Action::NEW, Roles::MENSAJES_WRITE)
            ->setPermission(Action::EDIT, Roles::MENSAJES_WRITE)
            ->setPermission(Action::DELETE, Roles::MENSAJES_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Regla de Envío')
            ->setEntityLabelInPlural('Reglas de Envío')
            ->setSearchFields(['name', 'template.name', 'targetCommunicationChannels.name'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->setMaxLength(40)
            ->onlyOnDetail();

        // =========================================================================
        // 1. DEFINICIÓN DE LA REGLA
        // =========================================================================
        yield FormField::addPanel('Definición de la Regla')->setIcon('fa fa-robot')->collapsible();

        yield TextField::new('name', 'Nombre de la Regla')
            ->setColumns(6);

        yield ChoiceField::new('contextType', 'Contexto de Aplicación')
            ->setChoices([
                'Reserva (PMS)' => 'pms_reserva',
                // 'Tours / Actividades' => 'tour_booking', // Preparado para el futuro
            ])
            ->setRequired(true)
            ->setHelp('Define a qué tipo de entidad aplica esta regla.')
            ->setColumns(4);

        yield BooleanField::new('isActive', '¿Activa?')
            ->renderAsSwitch(true)
            ->setColumns(2);

        yield AssociationField::new('template', 'Plantilla a Enviar')
            ->setRequired(true)
            ->setColumns(6);

        // Selección Múltiple (ManyToMany) para los canales de salida
        yield AssociationField::new('targetCommunicationChannels', 'Canales de Destino (Medios)')
            ->setRequired(true)
            ->setColumns(6)
            ->setFormTypeOptions([
                'by_reference' => false, // Vital para guardar colecciones ManyToMany en Doctrine
            ])
            ->setHelp('Puedes seleccionar varios. Ej: Enviar por WhatsApp Y por la API de Booking al mismo tiempo.');


        // =========================================================================
        // 2. FILTROS DE SEGMENTACIÓN (Agnósticos)
        // =========================================================================
        yield FormField::addPanel('Filtros de Segmentación (B2B / OTAs)')
            ->setIcon('fa fa-filter')
            ->collapsible()
            ->setHelp('Si dejas estos campos vacíos, la regla se aplicará a <b>TODAS</b> las reservas. Usa esto para personalizar mensajes por agencia o portal.');

        yield ChoiceField::new('allowedSources', 'Solo para estas Fuentes / OTAs')
            ->setChoices($this->segmentationAggregator->getSourceChoices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->setColumns(6)
            ->setHelp('Aplica solo a reservas que provengan de estos orígenes.');

        yield ChoiceField::new('allowedAgencies', 'Solo para estas Agencias (B2B)')
            ->setChoices($this->segmentationAggregator->getAgencyChoices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->setColumns(6)
            ->setHelp('Aplica solo a reservas hechas por estas agencias mayoristas.');


        // =========================================================================
        // 3. PROGRAMACIÓN EN EL TIEMPO (Scheduler)
        // =========================================================================
        yield FormField::addPanel('Programación en el Tiempo (Scheduler)')->setIcon('fa fa-clock')->collapsible();

        // ⚠️ Los hitos INTERMEDIOS —salida temporal, reingreso, cambio de casita, servicio de un
        // tour— existen y se guardan, pero NO se ofrecen aquí a propósito: el motor programa
        // leyendo el mapa plano del asunto, y esos cuatro viven sólo en la lista de hitos. Una
        // regla apuntando a ellos no dispararía NUNCA, y sin un error que lo delatara — que es
        // peor que no poder crearla. Se añadirán el día que el motor sepa programar una
        // ocurrencia por hito (docs/Mensajeria.md §20.7).
        //
        // `partial_cancellation` sí está: se proyecta al mapa plano, así que funciona hoy.
        yield ChoiceField::new('milestone', 'Hito de Referencia')
            ->setChoices([
                'Creación (Reserva / Registro)' => ConversationMilestoneInterface::CREATED,
                'Llegada Esperada informada (ETA)' => ConversationMilestoneInterface::EXPECTED_ARRIVAL,
                'Inicio del Servicio (Check-in / Recojo)' => ConversationMilestoneInterface::START,
                'Fin del Servicio (Check-out / Retorno)' => ConversationMilestoneInterface::END,
                'Cancelación del Servicio' => ConversationMilestoneInterface::CANCELLED,
                'Pierde una parte (una casita de dos, un tramo)' => ConversationMilestoneInterface::PARTIAL_CANCELLATION,
            ])
            ->setRequired(true)
            ->setColumns(6)
            ->formatValue(function ($value, $entity) {
                return match ($value) {
                    ConversationMilestoneInterface::CREATED          => '📅 Registro Inicial',
                    ConversationMilestoneInterface::EXPECTED_ARRIVAL => '⏱️ Llegada Esperada',
                    ConversationMilestoneInterface::START            => '🟢 Inicio (Check-in / Tour)',
                    ConversationMilestoneInterface::END              => '🔴 Fin (Check-out / Retorno)',
                    ConversationMilestoneInterface::CANCELLED        => '❌ Cancelación',
                    ConversationMilestoneInterface::PARTIAL_CANCELLATION => '➖ Pierde una parte',
                    default                                          => $value,
                };
            });

        // ⚠️ El mínimo de las reglas de CREACIÓN lo impone la entidad
        // (`MessageRule::validarDesfaseDeCreacion()`), no este formulario: vale igual para un
        // import o un comando. Aquí sólo se ENSEÑA, para que nadie descubra la regla al fallar.
        yield IntegerField::new('offsetMinutes', 'Minutos de Desfase')
            ->setHelp(sprintf(
                'Ej: -1440 = 1 día antes. 0 = Mismo momento. 120 = 2 horas después.'
                . '<br><b>⚠️ Con el hito «Creación» el mínimo es %d minuto.</b> Con 0, el mensaje '
                . 'se programa en el mismo guardado en que entra la reserva y puede adelantarse '
                . 'a los arreglos de sus propios datos: el saludo saldría con el nombre del '
                . 'huésped todavía en mayúsculas, o por su apellido.',
                MessageRule::OFFSET_MINIMO_EN_CREACION
            ))
            ->setColumns(6);


        // =========================================================================
        // 4. AUDITORÍA
        // =========================================================================
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}