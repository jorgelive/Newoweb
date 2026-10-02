<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Agent\Entity\AutoResponderRule;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageRule;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\Queue\MessageRuleEngine;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * El aviso de salida con botones. Sustituye a `check_out`.
 *
 * ── Qué tenía la vieja ──────────────────────────────────────────────────────
 * `check_out` (22 h antes de la salida) era una página entera —llaves, cocina, basura, luces,
 * maletas— con la pregunta de la hora enterrada en medio y ningún botón. Quien no contestaba se
 * quedaba con la salida a las 10:00 «por defecto», indistinguible de quien sí la había confirmado.
 *
 * ── Qué hace la nueva ───────────────────────────────────────────────────────
 * Texto corto con la pregunta (Jorge, 01/10/2026) y las instrucciones en la guía (`salida_url`,
 * sección `salida`). Y tres respuestas de un toque:
 *
 * | botón | payload | qué pasa |
 * |---|---|---|
 * | Salgo a las 10:00 | `CMD_SALIDA_A_LA_HORA` | `confirmar_hora`: se apunta confirmada, aviso al equipo, «¡Perfecto…!» |
 * | Saldré antes | `CMD_SALIDA_ANTES` | `pasar_al_agente`: pregunta la hora y la apunta |
 * | Necesito más tiempo | `CMD_SALIDA_MAS_TARDE` | `pasar_al_agente`: pregunta la hora; fuera del horario avisa al equipo, que decide |
 *
 * Por canal: fuera de la ventana, la plantilla de Meta con sus botones; dentro, el texto de
 * WhatsApp con botones de verdad (la casilla «Ocultar botones» va desmarcada); por Beds24, el texto
 * plano con la pregunta abierta y el enlace (sin opciones: ~6 min de ida y vuelta). El huésped que
 * contesta con sus palabras va al agente por cualquier canal.
 *
 *   php bin/console msg:plantillas:aviso-salida               # crea plantillas y reglas de botón
 *   php bin/console msg:meta:push aviso_salida --todos
 *   php bin/console msg:plantillas:aviso-salida --activar     # cuando Meta la apruebe ENTERA
 */
#[AsCommand(
    name: 'msg:plantillas:aviso-salida',
    description: 'Crea el aviso de salida con botones; con --activar, repunta la regla «Check Out».',
)]
final class MessageCrearAvisoSalidaCommand extends Command
{
    public const string CODIGO = 'aviso_salida';
    public const string CODIGO_RESPUESTA = 'salida_confirmada';
    private const string CODIGO_VIEJO = 'check_out';

    public const string CMD_A_LA_HORA = 'CMD_SALIDA_A_LA_HORA';
    public const string CMD_ANTES = 'CMD_SALIDA_ANTES';
    public const string CMD_MAS_TARDE = 'CMD_SALIDA_MAS_TARDE';

    /** El texto de Jorge (01/10/2026), para WhatsApp dentro de la ventana y el envío a mano. */
    private const string CUERPO = "¡Hola {{guest_name}}! Esperamos que hayas disfrutado mucho tu tiempo en Cusco. 😊 "
        . "Te escribimos para recordarte que tu check-out es mañana, {{checkout_date}}, a las 10:00 am. "
        . "¿A qué hora tienes planeado salir?\n\n"
        . "🔑 En este enlace te dejamos unas breves instrucciones para tu salida (llaves, cocina, basura y luces): {{salida_url}}\n\n"
        . "🧳 Si necesitas que guardemos tu equipaje después del check-out, avísanos y lo coordinamos.";

    /** Lo mismo para Meta: el enlace va en su botón, no en el texto. */
    private const string CUERPO_META = "¡Hola {{guest_name}}! Esperamos que hayas disfrutado mucho tu tiempo en Cusco. 😊 "
        . "Te escribimos para recordarte que tu check-out es mañana, {{checkout_date}}, a las 10:00 am. "
        . "¿A qué hora tienes planeado salir?\n\n"
        . "🔑 En el botón de abajo te dejamos unas breves instrucciones para tu salida (llaves, cocina, basura y luces).\n\n"
        . "🧳 Si necesitas que guardemos tu equipaje después del check-out, avísanos y lo coordinamos.";

    /** La respuesta a [Salgo a las 10:00]. Sólo dentro de la ventana: acaba de pulsar. */
    private const string CUERPO_RESPUESTA = '¡Perfecto, {{guest_name}}! Anotamos tu salida a las 10:00. ¡Gracias por avisarnos! 😊';

    private const string AGENTE_USO = 'Aviso de salida: la hora del check-out, la pregunta de a qué hora sale y el '
        . 'enlace a las instrucciones de salida. LA MANDA SOLA el motor de reglas el día antes de la salida.';

    /**
     * Los botones, escritos a mano en cada idioma: el título de un botón interactivo no pasa de 20
     * caracteres (el de Meta, de 25), y la traducción automática se pasa. El de ENLACE sólo sale
     * en Meta —dentro de la ventana el enlace va en el texto—, así que tiene los 25.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}> idioma => [10:00, antes, más tiempo, instrucciones]
     */
    private const array BOTONES = [
        'es' => ['Salgo a las 10:00', 'Saldré antes', 'Necesito más tiempo', 'Instrucciones de salida'],
        'en' => ['Leaving at 10:00', 'Leaving earlier', 'Need more time', 'Check-out guide'],
        'pt' => ['Saio às 10:00', 'Vou sair antes', 'Preciso mais tempo', 'Instruções de saída'],
        'fr' => ['Je pars à 10h00', 'Je pars plus tôt', 'Un peu plus de temps', 'Consignes de départ'],
        'it' => ['Esco alle 10:00', 'Esco prima', 'Mi serve più tempo', 'Istruzioni uscita'],
        'de' => ['Abreise um 10:00', 'Ich reise früher ab', 'Brauche mehr Zeit', 'Hinweise Abreise'],
        'nl' => ['Vertrek om 10:00', 'Ik vertrek eerder', 'Meer tijd nodig', 'Vertrekinstructies'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageRuleEngine $motor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('activar', null, InputOption::VALUE_NONE, 'Repunta la regla «Check Out». Sólo con Meta aprobada entera.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');

        return $input->getOption('activar') ? $this->activar($io, $simular) : $this->crear($io, $simular);
    }

    private function crear(SymfonyStyle $io, bool $simular): int
    {
        $plantillas = $this->em->getRepository(MessageTemplate::class);

        foreach ([self::CODIGO => fn () => $this->aviso(), self::CODIGO_RESPUESTA => fn () => $this->respuesta()] as $codigo => $construir) {
            if ($plantillas->findOneBy(['code' => $codigo]) !== null) {
                // Idempotente y sin pisar: puede haberla afinado alguien desde el panel.
                $io->note(sprintf('«%s» ya existe: no se toca.', $codigo));
                continue;
            }

            if ($simular) {
                $io->note(sprintf('Simulación: se crearía «%s».', $codigo));
                continue;
            }

            $plantilla = $construir();
            $this->em->persist($plantilla);
            // `AutoTranslate` corre en `prePersist`: traduce los cuerpos y PISA los botones.
            $this->em->flush();

            // Los botones a mano después de traducir, conservando el `origenHash` que les puso el
            // traductor: es lo que le dice que están al día (ver `MessageCrearMensajePendienteCommand`).
            if ($codigo === self::CODIGO) {
                $this->botonesAMano($plantilla);
                $this->em->flush();
            }

            $io->success(sprintf('«%s» creada.', $codigo));
        }

        $this->reglasDeBoton($io, $simular);

        $io->note('Sube el aviso: php bin/console msg:meta:push ' . self::CODIGO . ' --todos. '
            . 'La respuesta no se sube: sólo sale dentro de la ventana, que el botón acaba de abrir.');

        return Command::SUCCESS;
    }

    /** Las tres reglas que convierten cada botón en una acción. Idempotente por el payload. */
    private function reglasDeBoton(SymfonyStyle $io, bool $simular): void
    {
        $reglas = [
            self::CMD_A_LA_HORA => ['confirmar_hora', ['extremo' => 'salida', 'plantilla_respuesta' => self::CODIGO_RESPUESTA]],
            self::CMD_ANTES => ['pasar_al_agente', null],
            self::CMD_MAS_TARDE => ['pasar_al_agente', null],
        ];

        foreach ($reglas as $payload => [$accion, $parametros]) {
            if ($this->em->getRepository(AutoResponderRule::class)->findOneBy(['triggerValue' => $payload]) !== null) {
                $io->note(sprintf('La regla «%s» ya existe: no se toca.', $payload));
                continue;
            }

            if ($simular) {
                $io->note(sprintf('Simulación: regla «%s» → %s.', $payload, $accion));
                continue;
            }

            $regla = (new AutoResponderRule())
                ->setTriggerValue($payload)
                ->setActionType($accion)
                ->setActionParameters($parametros)
                ->setIsActive(true);
            $regla->initializeId();
            $this->em->persist($regla);
        }

        if (!$simular) {
            $this->em->flush();
        }
    }

    private function activar(SymfonyStyle $io, bool $simular): int
    {
        $nueva = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]);

        if (!$nueva instanceof MessageTemplate) {
            $io->error('Falta «aviso_salida». Créala primero sin --activar.');

            return Command::FAILURE;
        }

        // Repuntar arrastra los avisos YA programados: con un idioma sin aprobar, esos se
        // quedarían sin salir por WhatsApp fuera de la ventana.
        $faltan = [];
        foreach ($nueva->getWhatsappMetaTmpl()['body'] ?? [] as $cuerpo) {
            $idioma = (string) ($cuerpo['language'] ?? '');
            if ($idioma !== '' && !$nueva->hasWhatsappMetaOfficialData($idioma)) {
                $faltan[] = strtoupper($idioma) . ' (' . ($cuerpo['status'] ?? 'SIN ENVIAR') . ')';
            }
        }

        if ($faltan !== []) {
            $io->error('Meta todavía no ha aprobado «aviso_salida» entera: ' . implode(', ', $faltan)
                . '. Trae el estado con app:whatsapp:sync-templates y vuelve a probar.');

            return Command::FAILURE;
        }

        $regla = null;
        foreach ($this->em->getRepository(MessageRule::class)->findAll() as $candidata) {
            if (in_array($candidata->getTemplate()?->getCode(), [self::CODIGO_VIEJO, self::CODIGO], true)) {
                $regla = $candidata;
                break;
            }
        }

        if ($regla === null) {
            $io->error('No hay regla que apunte a «check_out» ni a «aviso_salida»: no se crea una a ciegas.');

            return Command::FAILURE;
        }

        if ($regla->getTemplate() === $nueva) {
            $io->success('La regla «' . $regla->getName() . '» ya apuntaba a aviso_salida.');

            return Command::SUCCESS;
        }

        $hilos = $this->hilosConAvisoEnCola($regla);
        $io->text(sprintf('«%s»: check_out → aviso_salida. %d hilos con el aviso en cola se recolocan.', $regla->getName(), count($hilos)));

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $regla->setTemplate($nueva);
        $this->em->flush();

        // El motor reconoce lo programado por el `rule_id` y alinea la plantilla
        // (`syncPendingMessage()`): los avisos en cola salen ya con la nueva.
        foreach ($hilos as $hilo) {
            $this->motor->syncConversationRules($hilo, MessageRuleEngine::TRIGGER_UPDATE);
        }

        $io->success('Aviso de salida activo.');

        return Command::SUCCESS;
    }

    /** @return list<MessageConversation> */
    private function hilosConAvisoEnCola(MessageRule $regla): array
    {
        /** @var list<Message> $mensajes */
        $mensajes = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.rule = :regla')
            ->andWhere('m.status IN (:vivos)')
            ->andWhere('m.scheduledAt > :ahora')
            ->setParameter('regla', $regla->getId(), 'uuid')
            ->setParameter('vivos', [Message::STATUS_QUEUED, Message::STATUS_PENDING])
            ->setParameter('ahora', new DateTimeImmutable())
            ->getQuery()
            ->getResult();

        $hilos = [];
        foreach ($mensajes as $mensaje) {
            $hilo = $mensaje->getConversation();
            if ($hilo !== null) {
                $hilos[(string) $hilo->getId()] = $hilo;
            }
        }

        return array_values($hilos);
    }

    private function aviso(): MessageTemplate
    {
        [$aLaHora, $antes, $masTarde, $instrucciones] = self::BOTONES['es'];

        return (new MessageTemplate())
            ->setCode(self::CODIGO)
            ->setName('Aviso de salida (con botones)')
            ->setContextType('pms_reserva')
            ->setAllowedSources([])
            ->setAutoenvioHabilitada(false)
            ->setAgenteUso(self::AGENTE_USO)
            // Por Beds24 sin opciones (~6 min de ida y vuelta): pregunta abierta y el enlace en el texto.
            ->setBeds24Tmpl(['is_active' => true, 'disable_meta_buttons' => true, 'body' => [['language' => 'es', 'content' => self::CUERPO]]])
            // Dentro de la ventana, con botones de verdad: la casilla «Ocultar» va DESMARCADA. El
            // enlace ya está en el texto y no se repite.
            ->setWhatsappLinkTmpl(['disable_meta_buttons' => false, 'body' => [['language' => 'es', 'content' => self::CUERPO]]])
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                'meta_template_name' => self::CODIGO . '_v1',
                'is_official_meta' => false,
                'header' => [],
                'footer' => [],
                'body' => [['language' => 'es', 'content' => self::CUERPO_META]],
                'buttons_map' => [
                    ['index' => 0, 'type' => 'quick_reply', 'content' => null, 'resolver_key' => self::CMD_A_LA_HORA, 'button_text' => [['language' => 'es', 'content' => $aLaHora]]],
                    ['index' => 1, 'type' => 'quick_reply', 'content' => null, 'resolver_key' => self::CMD_ANTES, 'button_text' => [['language' => 'es', 'content' => $antes]]],
                    ['index' => 2, 'type' => 'quick_reply', 'content' => null, 'resolver_key' => self::CMD_MAS_TARDE, 'button_text' => [['language' => 'es', 'content' => $masTarde]]],
                    ['index' => 3, 'type' => 'url', 'content' => 'https://pax.openperu.pe/{{1}}', 'resolver_key' => 'salida_path', 'button_text' => [['language' => 'es', 'content' => $instrucciones]]],
                ],
                'ejemplos' => array_map(
                    static fn (): array => ['guest_name' => 'Anna', 'checkout_date' => '05/02/2027'],
                    self::BOTONES
                ),
            ])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);
    }

    private function respuesta(): MessageTemplate
    {
        $cuerpo = [['language' => 'es', 'content' => self::CUERPO_RESPUESTA]];

        return (new MessageTemplate())
            ->setCode(self::CODIGO_RESPUESTA)
            ->setName('Respuesta: salida confirmada a la hora')
            ->setContextType('pms_reserva')
            ->setAllowedSources([])
            ->setAutoenvioHabilitada(false)
            ->setAgenteUso('Interna: la manda SOLA el botón «Salgo a las 10:00» del aviso de salida. No la envíes tú.')
            ->setBeds24Tmpl(['is_active' => false, 'body' => []])
            ->setWhatsappLinkTmpl(['disable_meta_buttons' => true, 'body' => $cuerpo])
            // Activa para WhatsApp pero NO oficial: sólo dentro de la ventana, que es donde está
            // quien acaba de pulsar el botón. No hay nada que subir a Meta.
            ->setWhatsappMetaTmpl(['is_active' => true, 'is_official_meta' => false, 'header' => [], 'footer' => [], 'body' => $cuerpo, 'buttons_map' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);
    }

    /** Pone los botones de `BOTONES` en cada idioma, conservando el `origenHash` del traductor. */
    private function botonesAMano(MessageTemplate $plantilla): void
    {
        $meta = $plantilla->getWhatsappMetaTmpl() ?? [];

        foreach ($meta['buttons_map'] ?? [] as $i => $boton) {
            $traducidos = [];
            $hashes = [];
            foreach ($boton['button_text'] ?? [] as $texto) {
                $hashes[$texto['language'] ?? ''] = $texto['origenHash'] ?? null;
            }

            foreach (self::BOTONES as $idioma => $textos) {
                $fila = ['language' => $idioma, 'content' => $textos[$i]];
                if (($hashes[$idioma] ?? null) !== null) {
                    $fila['origenHash'] = $hashes[$idioma];
                }
                $traducidos[] = $fila;
            }

            $meta['buttons_map'][$i]['button_text'] = $traducidos;
        }

        $plantilla->setWhatsappMetaTmpl($meta);
    }
}
