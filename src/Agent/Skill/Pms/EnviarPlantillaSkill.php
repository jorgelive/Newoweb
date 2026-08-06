<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use App\Pms\Entity\PmsReserva;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Manda una plantilla ya escrita al huésped. **Escribe fuera.**
 *
 * Hermana de {@see EnviarMensajeHuespedSkill}: aquélla manda lo que redacta el modelo, ésta
 * manda un texto **aprobado de antemano**, traducido a los siete idiomas y con sus botones. Para
 * la guía de llegada o el menú de tours es lo que hay que usar: escribirlo a mano cada vez sería
 * peor y distinto cada vez.
 *
 * ### 🏷️ El catálogo se compone en tiempo de ejecución
 *
 * La descripción enumera **las plantillas habilitadas y para qué sirve cada una**, leyéndolas de
 * la base. Un `code` suelto (`enviar_guia`, `menu_tours`) no le dice a un modelo cuándo tocan, y
 * `name` tampoco: es una etiqueta de panel, y alguna es una referencia interna («Autorespuesta
 * ER130497»). El texto que decide viene de `MessageTemplate::getAgenteUso()`.
 *
 * Así, habilitar una plantilla nueva desde el panel la pone en circulación sin tocar código.
 *
 * ### 🚫 Por qué la mayoría están apagadas
 *
 * Seis de las once las dispara **solo el motor de reglas** en su hito —bienvenida al crear,
 * llegada al empezar, despedida al terminar—. Mandarlas a mano duplicaría lo que el huésped ya
 * recibió o va a recibir. `agenteHabilitada` está en `false` por defecto justo por eso: se
 * enciende una a una, y es una decisión de negocio.
 *
 * ### Los canales los acota la plantilla
 *
 * No se eligen aquí: `MessageDispatcher::resolveChannels()` toma los que la plantilla tiene con
 * `is_active`, y si además se piden canales, hace la intersección. Una plantilla sin cuerpo de
 * Beds24 no sale por Beds24 aunque se pida.
 */
final readonly class EnviarPlantillaSkill implements SkillInterface
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function nombre(): string
    {
        return 'enviar_plantilla';
    }

    public function definicion(): SkillDefinition
    {
        $catalogo = $this->disponibles();

        $lista = $catalogo === []
            ? 'AHORA MISMO NO HAY NINGUNA HABILITADA: dilo y no la uses.'
            : implode(' ', array_map(
                static fn (MessageTemplate $t) => sprintf('«%s»: %s', $t->getCode(), $t->getAgenteUso()),
                $catalogo
            ));

        return new SkillDefinition(
            descripcion: 'Envía al huésped una plantilla ya redactada y traducida, en su idioma '
                . 'y con sus botones. Úsala en vez de escribir el texto a mano cuando lo que '
                . 'piden encaja con una de éstas. Plantillas disponibles — ' . $lista
                . ' Si lo que te piden no encaja con ninguna, NO fuerces una: redacta el texto y '
                . 'usa enviar_mensaje_huesped. ENVÍA A UNA PERSONA REAL: llama primero con '
                . 'confirmado=false, enseña al operador qué plantilla es, a quién va y por qué '
                . 'canales, léele la advertencia si la hay, y espera su sí. Necesita el '
                . 'conversacion_id de localizar_conversacion.',
            parametros: [
                SkillParameter::texto('conversacion_id', 'Identificador del chat, de '
                    . 'localizar_conversacion.'),
                SkillParameter::texto('plantilla', 'Código exacto de la plantilla, de la lista '
                    . 'de arriba. No inventes códigos.'),
                SkillParameter::booleano('confirmado', 'true SÓLO tras la aprobación explícita '
                    . 'del operador. false para ver la previsualización.'),
            ],
        );
    }

    public function rolesRequeridos(): array
    {
        return [Roles::MENSAJES_WRITE];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Escritura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $conversacionId = trim((string) ($entrada['conversacion_id'] ?? ''));
        $codigo = trim((string) ($entrada['plantilla'] ?? ''));
        $confirmado = filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL);

        if (!Uuid::isValid($conversacionId)) {
            return SkillResult::error('El conversacion_id no es válido.');
        }

        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $codigo]);

        if ($plantilla === null) {
            return SkillResult::error(sprintf(
                'No existe ninguna plantilla con el código «%s». Usa uno de: %s.',
                $codigo,
                implode(', ', array_map(
                    static fn (MessageTemplate $t) => (string) $t->getCode(),
                    $this->disponibles()
                )) ?: '(ninguna habilitada)'
            ));
        }

        // La marca del panel manda sobre lo que pida el modelo: una plantilla automática no se
        // manda a mano ni aunque acierte el código.
        if (!$plantilla->disponibleParaAgente()) {
            return SkillResult::error(sprintf(
                'La plantilla «%s» no está habilitada para el agente. Suele ser porque la manda '
                . 'sola el motor de reglas y enviarla a mano la duplicaría. Dile al operador que '
                . 'la mande desde el panel si de verdad hace falta.',
                $codigo
            ));
        }

        $conversacion = $this->em->getRepository(MessageConversation::class)->find($conversacionId);
        if ($conversacion === null) {
            return SkillResult::error('No existe ese chat.');
        }

        if ($conversacion->getStatus() === MessageConversation::STATUS_ARCHIVED) {
            return SkillResult::error('Este chat está archivado: no se puede escribir en él.');
        }

        $canales = $this->canalesDeLaPlantilla($plantilla);
        if ($canales === []) {
            return SkillResult::error(sprintf(
                'La plantilla «%s» no tiene ningún canal activo, así que no saldría por ninguna '
                . 'parte. Hay que activarle un canal en el panel.',
                $codigo
            ));
        }

        $idioma = $conversacion->getIdioma()?->getId() ?? 'es';
        $cancelada = $this->reservaCancelada($conversacion);

        // Se comprueba contra el origen real de la reserva: `despedida_airbnb` no se le manda a
        // un huésped de Booking aunque el operador se equivoque de código.
        $origen = $this->origenReserva($conversacion);
        $permitidos = $plantilla->getAllowedSources() ?? [];

        if ($permitidos !== [] && $origen !== null && !in_array($origen, $permitidos, true)) {
            return SkillResult::error(sprintf(
                'La plantilla «%s» es sólo para reservas de %s, y ésta vino de «%s». Busca la '
                . 'plantilla del canal que toca.',
                $codigo,
                implode(' o ', $permitidos),
                $origen
            ));
        }

        $resumen = array_filter([
            'conversacion_id' => $conversacionId,
            'plantilla' => $codigo,
            'plantilla_nombre' => $plantilla->getName(),
            'huesped' => $conversacion->getGuestName(),
            'idioma_huesped' => $idioma,
            'canales' => $canales,
            'texto_que_recibira' => $this->cuerpo($plantilla, $canales, $idioma),
            'reserva_cancelada' => $cancelada ?: null,
            'advertencia' => $cancelada
                ? 'ATENCIÓN: la reserva de este huésped está CANCELADA. Díselo al operador ANTES '
                    . 'de pedirle la aprobación y que confirme que aun así quiere enviarlo.'
                : null,
        ], static fn ($v) => $v !== null);

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'enviado' => false,
                'motivo' => 'falta_confirmacion',
                'pregunta_aprobacion' => '¿Apruebas el envío?',
                'previsualizacion' => sprintf(
                    'Se enviará la plantilla «%s» a %s por %s, en %s. Es IRREVERSIBLE. Enséñale '
                    . 'al operador el texto que va a recibir, tal cual.',
                    $plantilla->getName() ?? $codigo,
                    $conversacion->getGuestName() ?? 'el huésped',
                    implode(' y ', $canales),
                    $idioma
                ),
            ]);
        }

        $mensaje = new Message();
        $mensaje->setConversation($conversacion);
        $mensaje->setDirection(Message::DIRECTION_OUTGOING);
        // SENDER_HOST, igual que en enviar_mensaje_huesped: lo manda una persona a través del
        // asistente, y de paso calla al autoresponder 30 minutos.
        $mensaje->setSenderType(Message::SENDER_HOST);
        $mensaje->setStatus(Message::STATUS_PENDING);
        // Los canales NO se pasan: con plantilla, resolveChannels() ya toma los suyos. Pasarlos
        // aquí sólo serviría para recortarlos, y eso lo decide el operador en el panel.
        $mensaje->setTemplate($plantilla);
        $mensaje->setLanguageCode($idioma);
        $mensaje->addMetadata('enviado_por_agente', $actor->etiqueta());

        $conversacion->addMessage($mensaje);
        $this->em->persist($mensaje);
        $this->em->flush();

        return SkillResult::ok($resumen + [
            'enviado' => true,
            'mensaje_id' => (string) $mensaje->getId(),
            'estado' => $mensaje->getStatus(),
            'colas_creadas' => count($mensaje->getAllQueues()),
        ]);
    }

    /**
     * Las que el panel ha habilitado Y tienen escrito para qué sirven.
     *
     * @return list<MessageTemplate>
     */
    private function disponibles(): array
    {
        return array_values(array_filter(
            $this->em->getRepository(MessageTemplate::class)->findBy(['agenteHabilitada' => true]),
            static fn (MessageTemplate $t) => $t->disponibleParaAgente()
        ));
    }

    /**
     * Canales por los que saldría, según el `is_active` de cada cuerpo de la plantilla.
     *
     * Espejo de la REGLA 1 de `MessageDispatcher::resolveChannels()`, para poder decirlo en la
     * previsualización. Si allí cambia el criterio, aquí también — o se previsualizará un envío
     * distinto del que ocurre.
     *
     * @return list<string>
     */
    private function canalesDeLaPlantilla(MessageTemplate $plantilla): array
    {
        $canales = [];

        foreach ($this->em->getRepository(MessageChannel::class)->findBy(['isActive' => true]) as $canal) {
            $getter = 'get' . ucfirst($canal->getTemplateColumn());

            if (!method_exists($plantilla, $getter)) {
                continue;
            }

            $datos = $plantilla->$getter();

            if (is_array($datos) && ($datos['is_active'] ?? false) === true) {
                $canales[] = (string) $canal->getId();
            }
        }

        return $canales;
    }

    /**
     * El cuerpo que verá el huésped, para poder enseñárselo al operador antes de mandarlo.
     *
     * Aprobar «se enviará la plantilla enviar_guia» a ciegas no es aprobar nada: hay que ver el
     * texto. Se muestra el del primer canal, que es el que más se parece a lo que llega.
     */
    private function cuerpo(MessageTemplate $plantilla, array $canales, string $idioma): ?string
    {
        // No hay un `getBodyFor($canal, $idioma)` genérico: cada canal tiene el suyo. Se
        // mapea aquí en vez de añadirlo a la entidad para no tocar el modelo por una vista.
        $getters = [
            'beds24' => 'getBeds24Body',
            'whatsapp_meta' => 'getWhatsappMetaBody',
            'whatsapp_link' => 'getWhatsappLinkBody',
            'email' => 'getEmailBody',
        ];

        foreach ($canales as $canalId) {
            $getter = $getters[$canalId] ?? null;

            if ($getter === null || !method_exists($plantilla, $getter)) {
                continue;
            }

            $texto = $plantilla->$getter($idioma) ?? $plantilla->$getter('es');

            if (is_string($texto) && trim($texto) !== '') {
                return $texto;
            }
        }

        return null;
    }

    /** De qué canal vino la reserva del chat, para respetar `allowedSources`. */
    private function origenReserva(MessageConversation $conversacion): ?string
    {
        return $this->reserva($conversacion)?->getChannel()?->getId();
    }

    private function reservaCancelada(MessageConversation $conversacion): bool
    {
        return $this->reserva($conversacion)?->isCancelada() ?? false;
    }

    private function reserva(MessageConversation $conversacion): ?PmsReserva
    {
        if ($conversacion->getContextType() !== 'pms_reserva' || $conversacion->getContextId() === null) {
            return null;
        }

        return $this->em->getRepository(PmsReserva::class)->find($conversacion->getContextId());
    }
}
