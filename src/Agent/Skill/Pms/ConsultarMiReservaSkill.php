<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Reserva\PmsEspacioEstancia;
use Doctrine\ORM\EntityManagerInterface;
use App\Message\Service\MessageDataResolverRegistry;
use App\Security\Roles;

/**
 * Los datos de la reserva de quien está escribiendo.
 *
 * 🔑 Es el ejemplo del patrón acotado por contexto: **no recibe qué reserva consultar**. La
 * saca de `ActorInterface::contextoId()`, que es la conversación por la que llegó el mensaje.
 * Un huésped no puede pedir la reserva de otro porque no hay parámetro donde escribirlo — la
 * frontera está en el diseño de la skill, no en una comprobación que se pueda olvidar.
 *
 * Su hermana para el equipo es {@see BuscarReservaSkill}, y están separadas justo por eso.
 */
final readonly class ConsultarMiReservaSkill implements SkillInterface
{
    public function __construct(
        private MessageDataResolverRegistry $resolvers,
        private EntityManagerInterface $em,
        private PmsEspacioEstancia $espacio,
    ) {}

    public function nombre(): string
    {
        return 'consultar_mi_reserva';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Devuelve los datos de la reserva de la persona con la que estás '
                . 'hablando: fechas de entrada y salida, casita, noches, huéspedes, '
                . 'localizador, total, pagado y saldo pendiente. Incluye dos enlaces que '
                . 'puedes darle directamente: guide_url, su guía personal con la llegada, el '
                . 'wifi y las instrucciones, y tours_catalog_url, el catálogo de tours. Úsala '
                . 'cuando pregunten por su propia reserva, su check-in, su casita, su guía, '
                . 'qué hacer en la zona o lo que deben. '
                . 'Consultada por alguien del EQUIPO trae además cómo está la casita alrededor '
                . 'de esa estancia: «casita_ese_dia» dice si hay otro huésped saliendo el día que '
                . 'llega —y hasta qué hora— o si está libre, y «casita_tras_su_salida» si entra '
                . 'alguien el día que se va. Sirve para responder «¿puede entrar antes?» sin '
                . 'abrir el calendario. Son datos INTERNOS de ocupación: no se le repiten a un '
                . 'huésped, y hablando con uno ni siquiera vienen. '
                . 'No necesita parámetros: siempre '
                . 'consulta la reserva de esta conversación.',
        );
    }

    /** El huésped la tiene por serlo; el equipo, por poder ver reservas. */
    public function rolesRequeridos(): array
    {
        return [Roles::HUESPED, Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        if ($actor->contextoTipo() === null || $actor->contextoId() === null) {
            return SkillResult::error('Esta conversación no está asociada a ninguna reserva.');
        }

        $resolver = $this->resolvers->getResolver($actor->contextoTipo());
        if ($resolver === null) {
            return SkillResult::error('No hay datos de reserva para este tipo de conversación.');
        }

        $variables = $resolver->getMessageVariables($actor->contextoId());

        // Sólo escalares: las variables del resolver alimentan plantillas, y colar aquí
        // estructuras internas sería filtrar más de lo que se pidió.
        $datos = array_filter(
            $variables,
            static fn ($valor) => is_scalar($valor) && (string) $valor !== ''
        );

        // 🔗 El id es lo que permite ENCADENAR: sin él, el modelo no puede pasar esta
        // reserva a la siguiente skill y cada consulta muere en sí misma. `getMessageVariables()`
        // no lo trae porque nació para rellenar plantillas, donde un UUID no pinta nada.
        $datos['reserva_id'] = $actor->contextoId();

        // 🚪 Qué hay ANTES y DESPUÉS en su casita, con la reserva ya localizada.
        //
        // En el chat del huésped esto también viaja en el contexto de la conversación
        // ({@see \App\Agent\Service\AiConversationProcessor::espacioAlrededor()}), pero ahí
        // sólo se calcula en los bordes de la estancia y el panel no tiene contexto ninguno.
        // Ubicar la reserva es el momento natural: quien pregunta «¿puede entrar antes?» desde
        // el panel no debería tener que encadenar otra consulta para saber si la casita está
        // libre.
        //
        // Son HECHOS. Cuánta flexibilidad cabe con ellos es política y vive en el campo de IA
        // del ítem de horarios — ver docs/Mensajeria.md §17.4.
        // 🔒 Sólo para el equipo. Al huésped no se le enseña quién ocupa su casita antes o
        // después: en su chat esto viaja por el contexto y marcado como interno, para decidir.
        // Ver docs/Mensajeria.md §17.3.
        if ($actor->esDelEquipo()) {
            $datos += $this->espacioDeLaCasita($actor->contextoId());
        }

        return SkillResult::ok($datos);
    }

    /**
     * Los vecinos de la estancia, aplanados a claves que el modelo pueda leer.
     *
     * Se enumeran a mano y no se vuelca el array del servicio: es la regla de §11 —lo que no se
     * anuncia no existe— y aquí además evita que un campo nuevo del servicio acabe en la
     * respuesta sin que nadie lo haya decidido.
     *
     * @return array<string, string>
     */
    private function espacioDeLaCasita(string $reservaId): array
    {
        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);

        if ($reserva === null) {
            return [];
        }

        $espacio = $this->espacio->alrededorDe($reserva);

        if ($espacio === null) {
            return [];
        }

        $datos = [];

        if ($espacio['sale_alguien_el_dia_que_llega'] !== null) {
            $datos['casita_ese_dia'] = sprintf(
                'Ocupada por otro huésped hasta las %s el día que llega: no hay margen para entrar antes.',
                $espacio['sale_alguien_el_dia_que_llega']
            );
        } elseif ($espacio['libre_la_vispera']) {
            $datos['casita_ese_dia'] = $espacio['desde_cuando_libre'] !== null
                ? sprintf('Libre desde el %s; nadie sale el día que llega.', $espacio['desde_cuando_libre'])
                : 'Libre; nadie sale el día que llega.';
        }

        if ($espacio['entra_alguien_el_dia_que_se_va'] !== null) {
            $datos['casita_tras_su_salida'] = sprintf(
                'Entra otro huésped a las %s el día que se va: la salida es a su hora.',
                $espacio['entra_alguien_el_dia_que_se_va']
            );
        }

        return $datos;
    }
}
