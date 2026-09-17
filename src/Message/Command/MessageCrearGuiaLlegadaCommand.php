<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Contract\ConversationMilestoneInterface;
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
 * La guía de llegada, en dos: con y sin prepago. Sustituye a `recordatorio_llegada`.
 *
 * ── Qué tenía la vieja ──────────────────────────────────────────────────────
 * `recordatorio_llegada` (abril de 2026) sale 30 h antes del check-in y repetía casi entera la
 * lista de la bienvenida con tono de formulario —«Credenciales de la red WiFi», «Detalles
 * operativos sobre la calefacción»—, llevaba `*Sobre la Casita:*` con los asteriscos literales en
 * el chat de Booking y **no decía lo único nuevo**: que a esa hora se abren en la guía el código
 * de las llaves y el WiFi. Tampoco preguntaba la hora de llegada, cuando `check_out` sí pregunta
 * la de salida.
 *
 * ── Por qué DOS ─────────────────────────────────────────────────────────────
 * Las llaves y el WiFi son `solo-ventana` en la guía: se ven con la ventana abierta **y el pago
 * confiable** (`PmsEventoEstadoPago::ESTADOS_PAGO_CONFIABLES`, que incluye `pago-parcial`). En
 * Booking eso es el prepago, y a quien no lo ha hecho le salen con candado: prometérselas sin
 * condición es mandarlo a un candado. En Airbnb no hay prepago que hacer.
 *
 * | plantilla | origen | en Meta | la diferencia |
 * |---|---|---|---|
 * | `guia_llegada` | airbnb, directo | `guia_llegada_v1` | — |
 * | `guia_llegada_booking` | booking | `guia_llegada_booking_v1` | «Se muestran en cuanto recibimos el prepago de tu reserva.» |
 *
 * ⚠️ **«Del prepago» y no «del pago completo»**: el adelanto ya desbloquea las llaves. La primera
 * propuesta decía «completo», y le habría dicho a quien pagó el adelanto que aún no las tenía.
 *
 * Las **directas** van con la de Airbnb por decisión de Jorge: su pago se negocia caso por caso y
 * la condición se habla en la conversación, que es por donde llegan.
 *
 * ── Los dos pasos ───────────────────────────────────────────────────────────
 *
 *   php bin/console msg:plantillas:guia-llegada              # crea las dos
 *   php bin/console msg:meta:push guia_llegada --todos
 *   php bin/console msg:meta:push guia_llegada_booking --todos
 *   php bin/console msg:plantillas:guia-llegada --activar    # cuando Meta apruebe LAS DOS
 *
 * `--activar` hace, en este orden:
 *
 * 1. **Repunta** la regla «Guia de llegada» a `guia_llegada` y la restringe a airbnb y directo.
 *    Se repunta y no se crea otra: el motor reconoce lo programado por el `rule_id`
 *    (`MessageRuleEngine::messageBelongsToRule()`), y una regla nueva cancelaría y volvería a
 *    crear los recordatorios ya en cola — el tipo de vaivén que el 14/09 dejó copias vivas.
 * 2. **Crea** «Guia de llegada Booking» con `guia_llegada_booking`.
 * 3. **Apaga** `recordatorio_llegada` en todos sus canales, para que nadie la mande a mano.
 * 4. **Pasa el motor** por los hilos que tienen un recordatorio en cola. Sin esto la transición
 *    esperaría al siguiente recálculo de cada reserva. Con él, en la misma pasada: los de Airbnb
 *    y directas cambian de plantilla (`syncPendingMessage()` alinea la plantilla con la regla), y
 *    los de Booking se cancelan en la regla vieja y nacen en la nueva — uno por uno.
 *
 * ⚠️ «A las 14:00» va escrito, como «a las 8:00» en la bienvenida. Si cambia el check-in hay que
 * reescribirlo, y en Meta eso es otra plantilla.
 */
#[AsCommand(
    name: 'msg:plantillas:guia-llegada',
    description: 'Crea la guía de llegada con y sin prepago; con --activar, repunta las reglas.',
)]
final class MessageCrearGuiaLlegadaCommand extends Command
{
    private const string CODIGO = 'guia_llegada';
    private const string CODIGO_BOOKING = 'guia_llegada_booking';
    private const string CODIGO_VIEJO = 'recordatorio_llegada';

    /** Lo único que separa las dos. Va detrás de la noticia, que es a lo que condiciona. */
    private const string FRASE_PREPAGO = ' Se muestran en cuanto recibimos el prepago de tu reserva.';

    private const string CUERPO = <<<'TXT'
        ¡Hola {{guest_name}}! 👋

        Tu llegada a Cusco ya está cerca. Desde hoy tienes en tu guía el código de la caja fuerte de tus llaves y la clave del WiFi.{{prepago}}

        👉 {{guide_url}}

        Antes de viajar, mira sobre todo:
        🚪 Cómo encontrar tu puerta: croquis, foto y video
        🔑 Cómo abrir la caja fuerte y recoger tus llaves
        🚿 Cómo tener agua caliente en la ducha
        🔥 La calefacción, si la quieres para las noches

        🕑 El check-in es desde las 14:00. Por favor, dinos a qué hora tienes planificado llegar.
        TXT;

    /**
     * Sin enlace en el texto: va en el botón. Sin la lista con emojis: en Meta cada renglón es
     * peso de plantilla, y la guía ya la tiene entera.
     */
    private const string CUERPO_META = <<<'TXT'
        Desde hoy tienes en tu guía el código de tus llaves y la clave del WiFi.{{prepago}}

        Antes de viajar, mira cómo encontrar tu puerta, cómo recoger tus llaves y cómo tener agua caliente.

        🕑 El check-in es desde las 14:00. Por favor, dinos a qué hora tienes planificado llegar.
        TXT;

    private const string CABECERA_META = 'Tu llegada a Cusco, {{guest_name}}';

    private const string AGENTE_USO = 'Guía de llegada: código de llaves, WiFi y cómo entrar. LA MANDA SOLA el motor de '
        . 'reglas 30 h antes del check-in: sólo reenviar si el operador confirma que el huésped no la recibió. Si '
        . 'el huésped sólo pide su guía, usa enviar_guia.';

    /** El nombre con el que se encuentra la regla nueva al volver a correr `--activar`. */
    private const string REGLA_BOOKING = 'Guia de llegada Booking';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageRuleEngine $motor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('activar', null, InputOption::VALUE_NONE, 'Repunta las reglas. Sólo con las dos aprobadas en Meta.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');

        return $input->getOption('activar')
            ? $this->activar($io, $simular)
            : $this->crear($io, $simular);
    }

    private function crear(SymfonyStyle $io, bool $simular): int
    {
        $repo = $this->em->getRepository(MessageTemplate::class);

        foreach ([self::CODIGO => false, self::CODIGO_BOOKING => true] as $codigo => $conPrepago) {
            if ($repo->findOneBy(['code' => $codigo]) !== null) {
                // Idempotente y sin pisar: puede haberla afinado alguien desde el panel.
                $io->note(sprintf('«%s» ya existe: no se toca.', $codigo));
                continue;
            }

            if ($simular) {
                $io->note(sprintf('Simulación: se crearía «%s».', $codigo));
                continue;
            }

            $this->em->persist($this->plantilla($codigo, $conPrepago));
            // `AutoTranslate` corre en `prePersist` y rellena los seis idiomas que faltan.
            $this->em->flush();

            $io->success(sprintf('«%s» creada. Súbela con: msg:meta:push %s --todos', $codigo, $codigo));
        }

        return Command::SUCCESS;
    }

    private function activar(SymfonyStyle $io, bool $simular): int
    {
        $plantillas = $this->em->getRepository(MessageTemplate::class);
        $nueva = $plantillas->findOneBy(['code' => self::CODIGO]);
        $nuevaBooking = $plantillas->findOneBy(['code' => self::CODIGO_BOOKING]);

        if (!$nueva instanceof MessageTemplate || !$nuevaBooking instanceof MessageTemplate) {
            $io->error('Faltan las plantillas nuevas. Créalas primero sin --activar.');

            return Command::FAILURE;
        }

        // ── La puerta: LAS DOS aprobadas enteras, o nada ────────────────────────
        //
        // Más importante aquí que en la bienvenida: repuntar arrastra los recordatorios YA
        // programados (`syncPendingMessage()` alinea su plantilla), así que una plantilla sin
        // aprobar dejaría sin salir por WhatsApp a todos los que esperan en cola.
        foreach ([$nueva, $nuevaBooking] as $plantilla) {
            $pendientes = $this->idiomasSinAprobar($plantilla);

            if ($pendientes !== null) {
                $io->error(sprintf(
                    'Meta todavía no ha aprobado «%s» entera: %s. Trae el estado con '
                    . 'app:whatsapp:sync-templates y vuelve a probar.',
                    $plantilla->getCode(),
                    $pendientes
                ));

                return Command::FAILURE;
            }
        }

        $reglas = $this->em->getRepository(MessageRule::class)->findAll();
        $filas = [];

        // ── 1. Repuntar la regla existente ──────────────────────────────────────
        $regla = $this->reglaQueApuntaA($reglas, self::CODIGO) ?? $this->reglaQueApuntaA($reglas, self::CODIGO_VIEJO);

        if ($regla === null) {
            $io->error('No hay regla que apunte a «recordatorio_llegada» ni a «guia_llegada»: no se crea una a ciegas.');

            return Command::FAILURE;
        }

        $fuentes = ['airbnb', 'directo'];

        if ($regla->getTemplate() === $nueva && $regla->getAllowedSources() === $fuentes) {
            $filas[] = [(string) $regla->getName(), '<comment>ya apuntaba a guia_llegada</comment>'];
        } else {
            $filas[] = [(string) $regla->getName(), 'recordatorio_llegada → guia_llegada, sólo airbnb y directo'];

            if (!$simular) {
                $regla->setTemplate($nueva)->setAllowedSources($fuentes);
            }
        }

        // ── 2. La de Booking, nueva ─────────────────────────────────────────────
        if ($this->reglaQueApuntaA($reglas, self::CODIGO_BOOKING) !== null) {
            $filas[] = [self::REGLA_BOOKING, '<comment>ya existe</comment>'];
        } else {
            $filas[] = [self::REGLA_BOOKING, sprintf('nueva: guia_llegada_booking, %d min, sólo booking', $regla->getOffsetMinutes())];

            if (!$simular) {
                $this->em->persist($this->reglaDeBooking($regla, $nuevaBooking));
            }
        }

        // ── 3. La vieja, apagada ────────────────────────────────────────────────
        $vieja = $plantillas->findOneBy(['code' => self::CODIGO_VIEJO]);

        if ($vieja instanceof MessageTemplate && $this->estaEncendida($vieja)) {
            $filas[] = [self::CODIGO_VIEJO, 'se apaga en todos sus canales'];

            if (!$simular) {
                $this->apagar($vieja);
            }
        }

        $io->table(['Regla / plantilla', 'Qué pasa'], $filas);

        if ($simular) {
            $io->note(sprintf(
                'Simulación: no se ha escrito nada. Pasaría el motor por %d hilos con un recordatorio en cola.',
                count($this->hilosConRecordatorioEnCola($regla))
            ));

            return Command::SUCCESS;
        }

        $this->em->flush();

        // ── 4. El motor, ahora y no en el próximo recálculo ─────────────────────
        $antes = $this->recuento();

        foreach ($this->hilosConRecordatorioEnCola($regla) as $hilo) {
            $this->motor->syncConversationRules($hilo, MessageRuleEngine::TRIGGER_UPDATE);
        }

        $io->table(['Plantilla', 'En cola antes', 'En cola después'], $this->comparar($antes, $this->recuento()));
        $io->success('Guía de llegada activa: airbnb y directas con guia_llegada, booking con guia_llegada_booking.');

        return Command::SUCCESS;
    }

    /** `null` si todos los idiomas de Meta están aprobados; si no, cuáles faltan. */
    private function idiomasSinAprobar(MessageTemplate $plantilla): ?string
    {
        $cuerpos = $plantilla->getWhatsappMetaTmpl()['body'] ?? [];

        if ($cuerpos === []) {
            return 'no tiene cuerpos de Meta';
        }

        $faltan = [];

        foreach ($cuerpos as $cuerpo) {
            $idioma = (string) ($cuerpo['language'] ?? '');

            if ($idioma !== '' && !$plantilla->hasWhatsappMetaOfficialData($idioma)) {
                $faltan[] = sprintf('%s (%s)', strtoupper($idioma), $cuerpo['status'] ?? 'SIN ENVIAR');
            }
        }

        return $faltan === [] ? null : implode(', ', $faltan);
    }

    /**
     * @param list<MessageRule> $reglas
     */
    private function reglaQueApuntaA(array $reglas, string $codigo): ?MessageRule
    {
        foreach ($reglas as $regla) {
            if ($regla->getTemplate()?->getCode() === $codigo) {
                return $regla;
            }
        }

        return null;
    }

    /** Misma cita que la de Airbnb —hito y minutos— y los mismos canales: sólo cambia el texto. */
    private function reglaDeBooking(MessageRule $base, MessageTemplate $plantilla): MessageRule
    {
        $regla = (new MessageRule())
            ->setName(self::REGLA_BOOKING)
            ->setContextType($base->getContextType())
            ->setTemplate($plantilla)
            ->setMilestone(ConversationMilestoneInterface::START)
            ->setOffsetMinutes($base->getOffsetMinutes())
            ->setAllowedSources(['booking']);

        foreach ($base->getTargetCommunicationChannels() as $canal) {
            $regla->addTargetCommunicationChannel($canal);
        }

        return $regla;
    }

    private function estaEncendida(MessageTemplate $plantilla): bool
    {
        return ($plantilla->getBeds24Tmpl()['is_active'] ?? false) === true
            || ($plantilla->getWhatsappMetaTmpl()['is_active'] ?? false) === true
            || ($plantilla->getWhatsappLinkTmpl()['is_active'] ?? false) === true;
    }

    /**
     * Sólo el interruptor: los cuerpos no se tocan, así que `AutoTranslate` no rehace nada y la
     * plantilla queda entera por si hubiera que volver atrás.
     */
    private function apagar(MessageTemplate $plantilla): void
    {
        $plantilla
            ->setBeds24Tmpl(['is_active' => false] + ($plantilla->getBeds24Tmpl() ?? []))
            ->setWhatsappMetaTmpl(['is_active' => false] + ($plantilla->getWhatsappMetaTmpl() ?? []))
            ->setWhatsappLinkTmpl(['is_active' => false] + ($plantilla->getWhatsappLinkTmpl() ?? []));
    }

    /**
     * Los hilos con algún recordatorio de ESTA regla todavía por salir.
     *
     * @return list<MessageConversation>
     */
    private function hilosConRecordatorioEnCola(MessageRule $regla): array
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

    /**
     * Cuántos mensajes futuros vivos hay por plantilla de guía de llegada.
     *
     * @return array<string, int>
     */
    private function recuento(): array
    {
        /** @var list<array{codigo: string, n: int|string}> $filas */
        $filas = $this->em->createQueryBuilder()
            ->select('t.code AS codigo, COUNT(m.id) AS n')
            ->from(Message::class, 'm')
            ->join('m.template', 't')
            ->where('t.code IN (:codigos)')
            ->andWhere('m.status IN (:vivos)')
            ->andWhere('m.scheduledAt > :ahora')
            ->setParameter('codigos', [self::CODIGO_VIEJO, self::CODIGO, self::CODIGO_BOOKING])
            ->setParameter('vivos', [Message::STATUS_QUEUED, Message::STATUS_PENDING])
            ->setParameter('ahora', new DateTimeImmutable())
            ->groupBy('t.code')
            ->getQuery()
            ->getArrayResult();

        $recuento = [self::CODIGO_VIEJO => 0, self::CODIGO => 0, self::CODIGO_BOOKING => 0];

        foreach ($filas as $fila) {
            $recuento[$fila['codigo']] = (int) $fila['n'];
        }

        return $recuento;
    }

    /**
     * @param array<string, int> $antes
     * @param array<string, int> $despues
     * @return list<array{string, int, int}>
     */
    private function comparar(array $antes, array $despues): array
    {
        $filas = [];

        foreach ($antes as $codigo => $n) {
            $filas[] = [$codigo, $n, $despues[$codigo] ?? 0];
        }

        return $filas;
    }

    private function plantilla(string $codigo, bool $conPrepago): MessageTemplate
    {
        $prepago = $conPrepago ? self::FRASE_PREPAGO : '';
        $cuerpo = [['language' => 'es', 'content' => str_replace('{{prepago}}', $prepago, self::CUERPO)]];

        return (new MessageTemplate())
            ->setCode($codigo)
            ->setName($conPrepago ? 'Guía de llegada (Booking)' : 'Guía de llegada (Airbnb y directas)')
            ->setContextType('pms_reserva')
            ->setAllowedSources($conPrepago ? ['booking'] : ['airbnb', 'directo'])
            ->setAutoenvioHabilitada(false)
            ->setAgenteUso(self::AGENTE_USO)
            // El enlace ya va escrito en el texto: con la botonera, saldría dos veces.
            ->setBeds24Tmpl(['is_active' => true, 'disable_meta_buttons' => true, 'body' => $cuerpo])
            ->setWhatsappLinkTmpl(['disable_meta_buttons' => true, 'body' => $cuerpo])
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                // Con sufijo desde el primer día: ver §18 de docs/Mensajeria.md.
                'meta_template_name' => $codigo . '_v1',
                'is_official_meta' => false,
                'header' => [['format' => 'TEXT', 'language' => 'es', 'content' => self::CABECERA_META]],
                'footer' => [],
                'body' => [['language' => 'es', 'content' => str_replace('{{prepago}}', $prepago, self::CUERPO_META)]],
                'buttons_map' => [[
                    'index' => 0,
                    'type' => 'url',
                    'content' => 'https://pax.openperu.pe/{{1}}',
                    'resolver_key' => 'guide_path',
                    'button_text' => [['language' => 'es', 'content' => 'Ver mi guía']],
                ]],
            ])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);
    }
}
