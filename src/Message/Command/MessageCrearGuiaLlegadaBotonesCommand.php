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
 * La guía de llegada con botones para la hora. Sustituye a `guia_llegada` y `guia_llegada_booking`.
 *
 * ── Qué cambia ──────────────────────────────────────────────────────────────
 * El texto es el mismo (`MessageCrearGuiaLlegadaCommand`); sólo la última línea pasa a ser una
 * pregunta directa con la hora de SU reserva —«Tu check-in es desde las {{checkin_time}}. ¿A qué hora
 * tienes planeado llegar?», como la del aviso de salida (Jorge, 02/10/2026)— y se le añaden dos
 * respuestas de un toque:
 *
 * | botón | payload | qué pasa |
 * |---|---|---|
 * | Llegaré más tarde | `CMD_LLEGADA_DESPUES` | `pasar_al_agente`: pregunta la hora y la apunta confirmada (`confirmar_hora`) |
 * | Llegaré antes | `CMD_LLEGADA_ANTES` | `pasar_al_agente`: pregunta la hora; depende de la disponibilidad, ofrece el equipaje y avisa al equipo, que decide |
 *
 * El botón de enlace [Ver mi guía] se queda, el último: el aviso de salida —ya aprobado por
 * Meta— lleva las respuestas delante y el enlace detrás, y no se arriesga otro orden.
 *
 * Por canal, igual que el aviso de salida: fuera de la ventana, la plantilla de Meta con sus tres
 * botones; dentro, el texto largo con los dos botones de respuesta de verdad (el enlace ya va en
 * el texto); por Beds24, el texto largo con la pregunta abierta, sin opciones (~6 min de ida y
 * vuelta). Quien contesta con sus palabras va al agente por cualquier canal.
 *
 *   php bin/console msg:plantillas:guia-llegada-botones               # plantillas y reglas de botón
 *   php bin/console msg:meta:push guia_llegada_botones --todos
 *   php bin/console msg:meta:push guia_llegada_booking_botones --todos
 *   php bin/console msg:plantillas:guia-llegada-botones --activar     # con las dos aprobadas ENTERAS
 */
#[AsCommand(
    name: 'msg:plantillas:guia-llegada-botones',
    description: 'Crea la guía de llegada con botones para la hora; con --activar, repunta las dos reglas.',
)]
final class MessageCrearGuiaLlegadaBotonesCommand extends Command
{
    public const string CODIGO = 'guia_llegada_botones';
    public const string CODIGO_BOOKING = 'guia_llegada_booking_botones';

    /** Las que sustituye, cada una con la suya: la regla que apunta a la vieja pasa a la nueva. */
    private const array SUSTITUYE = [
        'guia_llegada' => self::CODIGO,
        'guia_llegada_booking' => self::CODIGO_BOOKING,
    ];

    public const string CMD_DESPUES = 'CMD_LLEGADA_DESPUES';
    public const string CMD_ANTES = 'CMD_LLEGADA_ANTES';

    /** Lo único que separa las dos. Va detrás de la noticia, que es a lo que condiciona. */
    private const string FRASE_PREPAGO = ' Se muestran en cuanto recibimos el prepago de tu reserva.';

    /**
     * La hora es la de la RESERVA (`checkin_time`), no un 14:00 escrito: la del alojamiento si
     * nadie la cambió, la acordada si sí (Jorge, 02/10/2026). Por eso los botones no dicen hora:
     * los de respuesta rápida no admiten variables.
     */
    private const string PREGUNTA = '🕑 Tu check-in es desde las {{checkin_time}}. ¿A qué hora tienes planeado llegar?';

    /**
     * El nombre en Meta. `_v1` llevaba «desde las 14:00» y los botones con la hora; se mandó a
     * revisión y se rehízo antes de activarla. Queda en Meta sin dueño (el sincronizador sólo avisa).
     */
    private const string VERSION_META = '_v2';

    private const string CUERPO = <<<'TXT'
        ¡Hola {{guest_name}}! 👋

        Tu llegada a Cusco ya está cerca. Desde hoy tienes en tu guía el código de la caja fuerte de tus llaves y la clave del WiFi.{{prepago}}

        👉 {{guide_url}}

        Antes de viajar, mira sobre todo:
        🚪 Cómo encontrar tu puerta: croquis, foto y video
        🔑 Cómo abrir la caja fuerte y recoger tus llaves
        🚿 Cómo tener agua caliente en la ducha
        🔥 La calefacción, si la quieres para las noches

        {{pregunta}}
        TXT;

    /** Sin enlace en el texto (va en su botón) ni la lista: en Meta cada renglón es peso de plantilla. */
    private const string CUERPO_META = <<<'TXT'
        Desde hoy tienes en tu guía el código de tus llaves y la clave del WiFi.{{prepago}}

        Antes de viajar, mira cómo encontrar tu puerta, cómo recoger tus llaves y cómo tener agua caliente.

        {{pregunta}}
        TXT;

    private const string CABECERA_META = 'Tu llegada a Cusco, {{guest_name}}';

    private const string AGENTE_USO = 'Guía de llegada: código de llaves, WiFi y cómo entrar, y la pregunta de a qué '
        . 'hora llega. LA MANDA SOLA el motor de reglas 30 h antes del check-in: sólo reenviar si el operador '
        . 'confirma que el huésped no la recibió. Si el huésped sólo pide su guía, usa enviar_guia.';

    /**
     * Los botones, escritos a mano en cada idioma: el título de un botón interactivo no pasa de 20
     * caracteres, y la traducción automática se pasa. El de ENLACE sólo sale en Meta (25).
     *
     * @var array<string, array{0: string, 1: string, 2: string}> idioma => [después, antes, guía]
     */
    private const array BOTONES = [
        'es' => ['Llegaré más tarde', 'Llegaré antes', 'Ver mi guía'],
        'en' => ['Arriving later', 'Arriving earlier', 'View my guide'],
        'pt' => ['Chego mais tarde', 'Chego antes', 'Ver meu guia'],
        'fr' => ["J'arrive plus tard", "J'arrive plus tôt", 'Voir mon guide'],
        'it' => ['Arrivo più tardi', 'Arrivo prima', 'Vedi la mia guida'],
        'de' => ['Ich komme später', 'Ich komme früher', 'Meinen Guide ansehen'],
        'nl' => ['Ik kom later', 'Ik kom eerder', 'Bekijk mijn gids'],
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
            ->addOption('activar', null, InputOption::VALUE_NONE, 'Repunta las dos reglas. Sólo con las dos aprobadas en Meta.')
            ->addOption('rehacer', null, InputOption::VALUE_NONE, 'Borra y vuelve a crear las dos, sólo si ninguna regla las usa todavía.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');

        if ($input->getOption('activar')) {
            return $this->activar($io, $simular);
        }

        if ($input->getOption('rehacer') && $this->rehacer($io, $simular) === Command::FAILURE) {
            return Command::FAILURE;
        }

        return $this->crear($io, $simular);
    }

    private function crear(SymfonyStyle $io, bool $simular): int
    {
        $plantillas = $this->em->getRepository(MessageTemplate::class);

        foreach ([self::CODIGO => false, self::CODIGO_BOOKING => true] as $codigo => $conPrepago) {
            if ($plantillas->findOneBy(['code' => $codigo]) !== null) {
                // Idempotente y sin pisar: puede haberla afinado alguien desde el panel.
                $io->note(sprintf('«%s» ya existe: no se toca.', $codigo));
                continue;
            }

            if ($simular) {
                $io->note(sprintf('Simulación: se crearía «%s».', $codigo));
                continue;
            }

            $plantilla = $this->plantilla($codigo, $conPrepago);
            $this->em->persist($plantilla);
            // `AutoTranslate` corre en `prePersist`: traduce los cuerpos y PISA los botones.
            $this->em->flush();

            $this->botonesAMano($plantilla);
            $this->em->flush();

            $io->success(sprintf('«%s» creada. Súbela con: msg:meta:push %s --todos', $codigo, $codigo));
        }

        $this->reglasDeBoton($io, $simular);

        return Command::SUCCESS;
    }

    /**
     * Quita las dos plantillas para volver a crearlas con el texto de ahora. Sólo mientras ninguna
     * regla apunte a ellas: entonces no ha salido ni está programado nada con ellas, y borrar no
     * quita historia. Con una regla encima, se rota como cualquier plantilla aprobada (`_v3`).
     */
    private function rehacer(SymfonyStyle $io, bool $simular): int
    {
        $plantillas = $this->em->getRepository(MessageTemplate::class);
        $reglas = $this->em->getRepository(MessageRule::class)->findAll();

        foreach ([self::CODIGO, self::CODIGO_BOOKING] as $codigo) {
            $plantilla = $plantillas->findOneBy(['code' => $codigo]);

            if (!$plantilla instanceof MessageTemplate) {
                continue;
            }

            foreach ($reglas as $regla) {
                if ($regla->getTemplate() === $plantilla) {
                    $io->error(sprintf('La regla «%s» ya usa «%s»: no se rehace, se rota con otra versión.', $regla->getName(), $codigo));

                    return Command::FAILURE;
                }
            }

            $io->text(sprintf('Se rehace «%s».', $codigo));

            if (!$simular) {
                $this->em->remove($plantilla);
            }
        }

        if (!$simular) {
            $this->em->flush();
        }

        return Command::SUCCESS;
    }

    /** Las dos reglas que convierten cada botón en una acción. Idempotente por el payload. */
    private function reglasDeBoton(SymfonyStyle $io, bool $simular): void
    {
        foreach ([self::CMD_DESPUES, self::CMD_ANTES] as $payload) {
            if ($this->em->getRepository(AutoResponderRule::class)->findOneBy(['triggerValue' => $payload]) !== null) {
                $io->note(sprintf('La regla «%s» ya existe: no se toca.', $payload));
                continue;
            }

            if ($simular) {
                $io->note(sprintf('Simulación: regla «%s» → pasar_al_agente.', $payload));
                continue;
            }

            // Al agente las dos: hace falta la hora concreta, y con ella `confirmar_hora` decide si
            // va dentro del horario o se escala con lo que el equipo necesita para decidir.
            $regla = (new AutoResponderRule())
                ->setTriggerValue($payload)
                ->setActionType('pasar_al_agente')
                ->setActionParameters(null)
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
        $plantillas = $this->em->getRepository(MessageTemplate::class);
        $reglas = $this->em->getRepository(MessageRule::class)->findAll();
        $cambios = [];

        foreach (self::SUSTITUYE as $vieja => $nueva) {
            $plantilla = $plantillas->findOneBy(['code' => $nueva]);

            if (!$plantilla instanceof MessageTemplate) {
                $io->error(sprintf('Falta «%s». Créala primero sin --activar.', $nueva));

                return Command::FAILURE;
            }

            // Repuntar arrastra las guías YA programadas: con un idioma sin aprobar, esas se
            // quedarían sin salir por WhatsApp fuera de la ventana.
            $faltan = [];
            foreach ($plantilla->getWhatsappMetaTmpl()['body'] ?? [] as $cuerpo) {
                $idioma = (string) ($cuerpo['language'] ?? '');
                if ($idioma !== '' && !$plantilla->hasWhatsappMetaOfficialData($idioma)) {
                    $faltan[] = strtoupper($idioma) . ' (' . ($cuerpo['status'] ?? 'SIN ENVIAR') . ')';
                }
            }

            if ($faltan !== []) {
                $io->error(sprintf('Meta todavía no ha aprobado «%s» entera: %s. Trae el estado con app:whatsapp:sync-templates.', $nueva, implode(', ', $faltan)));

                return Command::FAILURE;
            }

            foreach ($reglas as $regla) {
                $codigo = $regla->getTemplate()?->getCode();
                if ($codigo === $vieja) {
                    $cambios[] = [$regla, $plantilla];
                } elseif ($codigo === $nueva) {
                    $io->note(sprintf('La regla «%s» ya apuntaba a %s.', $regla->getName(), $nueva));
                }
            }
        }

        if ($cambios === []) {
            $io->success('No queda ninguna regla que repuntar.');

            return Command::SUCCESS;
        }

        $hilos = [];
        foreach ($cambios as [$regla, $plantilla]) {
            $suyos = $this->hilosConGuiaEnCola($regla);
            $io->text(sprintf('«%s»: %s → %s. %d hilos con la guía en cola se recolocan.', $regla->getName(), $regla->getTemplate()?->getCode(), $plantilla->getCode(), count($suyos)));
            $hilos += $suyos;
        }

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        foreach ($cambios as [$regla, $plantilla]) {
            $regla->setTemplate($plantilla);
        }
        $this->em->flush();

        // El motor reconoce lo programado por el `rule_id` y alinea la plantilla
        // (`syncPendingMessage()`): las guías en cola salen ya con la nueva.
        foreach ($hilos as $hilo) {
            $this->motor->syncConversationRules($hilo, MessageRuleEngine::TRIGGER_UPDATE);
        }

        $io->success('Guía de llegada con botones activa.');

        return Command::SUCCESS;
    }

    /** @return array<string, MessageConversation> por id, para juntar los de las dos reglas sin repetir */
    private function hilosConGuiaEnCola(MessageRule $regla): array
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

        return $hilos;
    }

    private function plantilla(string $codigo, bool $conPrepago): MessageTemplate
    {
        $sustituir = ['{{prepago}}' => $conPrepago ? self::FRASE_PREPAGO : '', '{{pregunta}}' => self::PREGUNTA];
        $cuerpo = [['language' => 'es', 'content' => strtr(self::CUERPO, $sustituir)]];
        [$despues, $antes, $guia] = self::BOTONES['es'];

        return (new MessageTemplate())
            ->setCode($codigo)
            ->setName($conPrepago ? 'Guía de llegada con botones (Booking)' : 'Guía de llegada con botones (Airbnb y directas)')
            ->setContextType('pms_reserva')
            ->setAllowedSources($conPrepago ? ['booking'] : ['airbnb', 'directo'])
            ->setAutoenvioHabilitada(false)
            ->setAgenteUso(self::AGENTE_USO)
            // Por Beds24 sin opciones (~6 min de ida y vuelta): la pregunta abierta, el enlace en el texto.
            ->setBeds24Tmpl(['is_active' => true, 'disable_meta_buttons' => true, 'body' => $cuerpo])
            // Dentro de la ventana, con botones de verdad: la casilla «Ocultar» va DESMARCADA. El
            // enlace a la guía ya está en el texto y no se repite.
            ->setWhatsappLinkTmpl(['disable_meta_buttons' => false, 'body' => $cuerpo])
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                'meta_template_name' => $codigo . self::VERSION_META,
                'is_official_meta' => false,
                'header' => [['format' => 'TEXT', 'language' => 'es', 'content' => self::CABECERA_META]],
                'footer' => [],
                'body' => [['language' => 'es', 'content' => strtr(self::CUERPO_META, $sustituir)]],
                'buttons_map' => [
                    ['index' => 0, 'type' => 'quick_reply', 'content' => null, 'resolver_key' => self::CMD_DESPUES, 'button_text' => [['language' => 'es', 'content' => $despues]]],
                    ['index' => 1, 'type' => 'quick_reply', 'content' => null, 'resolver_key' => self::CMD_ANTES, 'button_text' => [['language' => 'es', 'content' => $antes]]],
                    ['index' => 2, 'type' => 'url', 'content' => 'https://pax.openperu.pe/{{1}}', 'resolver_key' => 'guide_path', 'button_text' => [['language' => 'es', 'content' => $guia]]],
                ],
                'ejemplos' => array_map(static fn (): array => ['guest_name' => 'Anna', 'checkin_time' => '14:00'], self::BOTONES),
            ])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);
    }

    /** Pone los botones de `BOTONES` en cada idioma, conservando el `origenHash` del traductor. */
    private function botonesAMano(MessageTemplate $plantilla): void
    {
        $meta = $plantilla->getWhatsappMetaTmpl() ?? [];

        foreach ($meta['buttons_map'] ?? [] as $i => $boton) {
            $hashes = [];
            foreach ($boton['button_text'] ?? [] as $texto) {
                $hashes[$texto['language'] ?? ''] = $texto['origenHash'] ?? null;
            }

            $traducidos = [];
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
