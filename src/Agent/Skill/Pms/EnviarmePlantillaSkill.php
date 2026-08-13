<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Entity\PmsReserva;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El AUTOENVÍO: el huésped se pide una plantilla a sí mismo («mándame mi guía»).
 *
 * Hermana de {@see EnviarPlantillaSkill}, con los papeles invertidos. Aquélla la usa el
 * operador del panel, puede apuntar a cualquier chat y exige confirmación humana; ésta la usa
 * el huésped, sólo puede apuntarse a sí mismo y no confirma nadie — por eso su catálogo es la
 * lista corta de `autoenvioHabilitada`, no todas las plantillas.
 *
 * ### Por qué el huésped puede escribir «hacia fuera» sin confirmación
 *
 * Es una acción propia, como borrar tu propio post: el destinatario es quien la pide, en su
 * propio chat, y el contenido está aprobado de antemano (la plantilla, traducida y con sus
 * botones). El peor caso es que reciba dos veces algo que él mismo pidió. Por eso es
 * `NivelRiesgo::Interna` —pasa el filtro de sólo-lectura del chat del huésped— y por eso el
 * flag se enciende una a una en el panel: qué plantillas son inocuas de pedir dos veces es una
 * decisión de negocio, no del código.
 *
 * ### Los tres candados
 *
 * 1. **El destino no es un parámetro.** La conversación sale del contexto del actor
 *    (`contextoId()`), igual que en `consultar_mi_reserva`: aceptar un id aquí sería dejarle
 *    mandar plantillas al chat de cualquiera escribiendo un UUID.
 * 2. **Sólo `autoenvioHabilitada`** (y con `agenteUso` escrito): la lista corta del panel.
 * 3. **La correspondencia de OTA** (`allowedSources`), la misma de la skill del operador: una
 *    plantilla de Booking no sale hacia una reserva de Airbnb.
 */
final readonly class EnviarmePlantillaSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function nombre(): string
    {
        return 'enviarme_plantilla';
    }

    public function definicion(): SkillDefinition
    {
        $catalogo = $this->disponibles();

        $lista = $catalogo === []
            ? 'AHORA MISMO NO HAY NINGUNA DISPONIBLE: dilo y no la uses.'
            : implode(' ', array_map(
                static fn (MessageTemplate $t) => sprintf('«%s»: %s', $t->getCode(), $t->getAgenteUso()),
                $catalogo
            ));

        return new SkillDefinition(
            descripcion: 'Le envía al huésped, en su propio chat, un material ya preparado que '
                . 'él mismo pide: su guía, el menú de tours. Va traducido a su idioma y con sus '
                . 'botones; úsala en vez de redactar cuando lo que pide encaja con una de éstas. '
                . 'Disponibles — ' . $lista
                . ' Si no encaja con ninguna, no fuerces una: responde tú con tus otras '
                . 'herramientas. No necesita confirmación: se lo está pidiendo él.',
            parametros: [
                SkillParameter::texto('plantilla', 'Código exacto de la plantilla, de la lista '
                    . 'de arriba. No inventes códigos.'),
            ],
        );
    }

    /**
     * Del negocio de ALOJAMIENTO: habla de reservas, estancias, casitas o su dinero.
     *
     * Recorta el catálogo, no los permisos — ver {@see SkillDominioInterface}.
     *
     * @return list<string>
     */
    public function dominios(): array
    {
        return [PmsFrentes::NEGOCIO];
    }

    public function rolesRequeridos(): array
    {
        return [Roles::HUESPED];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        // Escribe hacia el propio huésped que lo pide, con contenido pre-aprobado: acción
        // propia. `Escritura` lo sacaría del chat del huésped, que es justo donde vive.
        return NivelRiesgo::Interna;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $codigo = trim((string) ($entrada['plantilla'] ?? ''));

        // 🚧 Cuarto candado: el canal.
        //
        // Los otros tres miran QUÉ plantilla y HACIA DÓNDE; ninguno mira si la reserva está
        // confirmada. Y las plantillas de autoenvío llevan dentro `guide_url` —la guía, con la
        // dirección, el wifi y los teléfonos—, así que por una OTA sin confirmar esta skill
        // entrega de una vez todo lo que la resta por categorías acaba de tapar.
        //
        // Se bloquea entera en vez de filtrar sus variables: una plantilla es texto aprobado
        // de antemano, y ese texto se aprobó pensando en un huésped confirmado. Vaciarle las
        // URLs dejaría un mensaje cojo que nadie ha revisado; no mandarlo no rompe nada.
        if ($actor->restriccion()->restringe()) {
            return SkillResult::error(
                'Por este canal no se pueden enviar plantillas: la reserva aún no está '
                . 'confirmada y llevan enlaces a nuestra web, que la plataforma no permite. '
                . 'Responde tú con la información que sí puedas dar.'
            );
        }

        $conversacion = $this->conversacionDelActor($actor);
        if ($conversacion === null) {
            return SkillResult::error('Esta conversación no está asociada a ninguna reserva.');
        }

        if ($conversacion->getStatus() !== MessageConversation::STATUS_OPEN) {
            return SkillResult::error('Este chat no está abierto: no se puede enviar nada.');
        }

        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $codigo]);

        // Mismo mensaje exista o no exista fuera de la lista corta: al modelo no le incumbe
        // distinguir «no existe» de «existe pero no es de autoenvío».
        if ($plantilla === null || !$plantilla->disponibleParaAutoenvio()) {
            return SkillResult::error(sprintf(
                'No hay ninguna plantilla de autoenvío con el código «%s». Usa uno de: %s.',
                $codigo,
                implode(', ', array_map(
                    static fn (MessageTemplate $t) => (string) $t->getCode(),
                    $this->disponibles()
                )) ?: '(ninguna disponible)'
            ));
        }

        // La correspondencia de OTA, igual que en la skill del operador: si la plantilla está
        // acotada a un origen, la reserva tiene que venir de él.
        $origen = $this->reserva($conversacion)?->getChannel()?->getId();
        $permitidos = $plantilla->getAllowedSources() ?? [];

        if ($permitidos !== [] && $origen !== null && !in_array($origen, $permitidos, true)) {
            return SkillResult::error(sprintf(
                'La plantilla «%s» es sólo para reservas de %s, y esta reserva vino de «%s».',
                $codigo,
                implode(' o ', $permitidos),
                $origen
            ));
        }

        $idioma = $conversacion->getIdioma()?->getId() ?? 'es';

        $mensaje = new Message();
        $mensaje->setConversation($conversacion);
        $mensaje->setDirection(Message::DIRECTION_OUTGOING);
        // SENDER_SYSTEM, no SENDER_HOST: aquí no hay ninguna persona detrás, y HOST callaría
        // al autoresponder 30 minutos (guardia «humano al mando») justo después de que el bot
        // atendiera bien — el huésped preguntaría lo siguiente y nadie contestaría.
        $mensaje->setSenderType(Message::SENDER_SYSTEM);
        $mensaje->setStatus(Message::STATUS_PENDING);
        // Los canales no se pasan: con plantilla, resolveChannels() toma los suyos activos.
        $mensaje->setTemplate($plantilla);
        $mensaje->setLanguageCode($idioma);
        $mensaje->addMetadata('generado_por', 'ia');
        $mensaje->addMetadata('autoenvio', $actor->etiqueta());

        $conversacion->addMessage($mensaje);
        $this->em->persist($mensaje);
        $this->em->flush();

        return SkillResult::ok([
            'enviado' => true,
            'plantilla' => $codigo,
            'idioma' => $idioma,
            'mensaje_id' => (string) $mensaje->getId(),
            'colas_creadas' => count($mensaje->getAllQueues()),
            'nota' => 'Ya está en camino por su chat. Díselo en una frase; no repitas el contenido.',
        ]);
    }

    /**
     * La lista corta del panel: autoenvío habilitado Y uso escrito.
     *
     * @return list<MessageTemplate>
     */
    private function disponibles(): array
    {
        return array_values(array_filter(
            $this->em->getRepository(MessageTemplate::class)->findBy(['autoenvioHabilitada' => true]),
            static fn (MessageTemplate $t) => $t->disponibleParaAutoenvio()
        ));
    }

    /** La conversación del propio actor. El contexto manda; no hay parámetro que lo sustituya. */
    private function conversacionDelActor(ActorInterface $actor): ?MessageConversation
    {
        if ($actor->contextoId() === null || $actor->contextoTipo() === null) {
            return null;
        }

        return $this->em->getRepository(MessageConversation::class)->findOneBy([
            'contextType' => $actor->contextoTipo(),
            'contextId' => $actor->contextoId(),
        ]);
    }

    private function reserva(MessageConversation $conversacion): ?PmsReserva
    {
        if ($conversacion->getContextType() !== 'pms_reserva' || $conversacion->getContextId() === null) {
            return null;
        }

        return $this->em->getRepository(PmsReserva::class)->find($conversacion->getContextId());
    }
}
