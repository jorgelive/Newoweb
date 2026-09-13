<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Contract\ConversationMilestoneInterface;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageRule;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La bienvenida única de Booking y Airbnb, y el cambio de reglas que la pone a circular.
 *
 * ── Dos plantillas, y la diferencia es UNA LÍNEA ────────────────────────────
 * `welcome_booking` pesaba 1419 caracteres frente a los 463 de `welcome_airbnb`, y la diferencia
 * entera era el cobro: plazos, prepago y cuentas tecleadas. Eso se mudó a `politicas_booking`,
 * que va por el chat de Booking porque su valor es dejar constancia allí.
 *
 * Quitado el cobro, se intentó dejar **una sola** bienvenida para las dos OTA. No se pudo, y el
 * motivo no es de estilo: **en Booking hay que cobrar por adelantado y en Airbnb no.** Una
 * bienvenida que no menciona el prepago deja al huésped de Booking sin saber que hay algo que
 * pagar si `politicas_booking` se pierde o se lee por encima. Unificar habría borrado una
 * distinción del negocio para ahorrar un texto.
 *
 * Así que son dos, **idénticas salvo un renglón** de la lista:
 *
 *   💳 El prepago para asegurar tu reserva: cuánto es y cómo pagarlo
 *
 * ⚠️ Va DENTRO de la lista de «lo que tienes en la guía», no como párrafo aparte: es una cosa más
 * que está allí, y así no añade tono comercial ni urgencia —que es lo que empuja una plantilla
 * hacia `MARKETING`.
 *
 * | plantilla | OTA | en Meta |
 * |---|---|---|
 * | `bienvenida` | Airbnb | `bienvenida_v2` |
 * | `bienvenida_booking` | Booking | `bienvenida_booking_v2` |
 *
 * ── Por qué DOS pasos, y el segundo se niega a correr antes de tiempo ───────
 *
 *   php bin/console msg:plantillas:bienvenida            # crea la plantilla
 *   php bin/console msg:meta:push bienvenida --todos     # la sube a Meta
 *   php bin/console msg:plantillas:bienvenida --activar  # cuando Meta la apruebe
 *
 * El orden importa por dos cosas que se rompen en silencio:
 *
 * 1. **Repuntar antes de que Meta apruebe** deja la bienvenida de WhatsApp sin salir: al minuto
 *    de reservar la ventana de 24 h está cerrada, así que sólo sale la plantilla aprobada, y un
 *    envío automático que falla no avisa a nadie (`AvisoEnvioFallidoListener` sólo mira los del
 *    equipo). Por eso `--activar` comprueba los siete idiomas en `APPROVED` y si no, no hace nada.
 * 2. **La regla de `politicas_booking` va en el MISMO paso que el repunte.** Antes, la bienvenida
 *    vieja sigue llevando las cuentas y el huésped de Booking las recibiría dos veces; después,
 *    sin la regla, no las recibiría ninguna.
 *
 * ── 🚫 Las DIRECTAS no reciben bienvenida, y es una decisión ────────────────
 * No es un hueco por llenar. Una reserva directa llega por una **negociación personalizada** —se
 * habló con esa persona, se acordó precio y condiciones—, así que un mensaje automático al minuto
 * de reservar llegaría detrás de una conversación que ya existe y diría menos que ella.
 *
 * ⚠️ **Y técnicamente sí se podría**, que es lo que hace tentador proponerlo: `bienvenida` no
 * nombra a ninguna OTA —a diferencia de las viejas, que decían «Reservado vía: Booking.com»— y
 * está aprobada en Meta, así que saldría por WhatsApp aunque la ventana de 24 h esté cerrada. En
 * 90 días fueron 30 directas, 24 con teléfono. Poderse, se puede; no se quiere.
 *
 * Si algún día cambia, sería una TERCERA regla con `allowed_sources: ['directo']` — y habría que
 * decidir aparte qué se les dice del pago: `politicas_booking` no sirve, habla de las políticas de
 * Booking.
 *
 * ── Por qué se REPUNTAN las reglas y no se crean otras ──────────────────────
 * `MessageRuleEngine::messageBelongsToRule()` reconoce lo ya enviado por el `rule_id`. Una regla
 * nueva no reconocería las bienvenidas que salieron con la vieja, y a las reservas de las últimas
 * dos horas (`PAST_THRESHOLD`) les llegaría una segunda. Cambiando la plantilla de la regla que
 * ya existe, lo enviado sigue siendo suyo.
 */
#[AsCommand(
    name: 'msg:plantillas:bienvenida',
    description: 'Crea la bienvenida de Booking y Airbnb; con --activar, repunta las reglas.',
)]
final class MessageCrearBienvenidaCommand extends Command
{
    private const string CODIGO = 'bienvenida';

    /**
     * Para el chat de la OTA y para dentro de la ventana de WhatsApp.
     *
     * - **No dice «gracias por reservar»**: en Booking eso ya lo dice `politicas_booking` un minuto
     *   antes. «Te damos la bienvenida» vale para las dos OTA y no tiene género.
     * - **Las llaves dicen cuándo se abren, y con una HORA.** Su ficha es `solo-ventana`:
     *   `PmsGuiaAcceso` la entrega con pago confiable y dentro de `HORAS_ANTICIPACION` (30 h).
     *   Prometerla sin el paréntesis es mandar al huésped a un candado.
     *
     *   ⚠️ Dice «a las 8:00 del día anterior» y no «30 h antes» porque **es lo mismo y se
     *   entiende sin restar**: con el check-in a las 14:00, 30 h antes son exactamente las 8:00
     *   del día anterior. Nadie sabe cuándo son 30 horas antes de su llegada.
     *
     *   ⚠️ **Y son lo mismo MIENTRAS el check-in sea a las 14:00.** Si cambia, las 30 h se mueven
     *   solas y esta frase no: habría que reescribirla —y en Meta eso es una plantilla nueva—.
     * - **La calefacción sin precio**: el precio y las condiciones viven en su ficha de la guía,
     *   y dos sitios con el mismo precio son dos sitios que actualizar.
     * - **«Por aquí» es cierto**: este cuerpo sale por canales en los que se puede contestar.
     */
    private const string CUERPO = <<<'TXT'
        Hola {{guest_name}}, soy Susan, de Centro Cusco Inti. Te damos la bienvenida 😊

        Tu reserva: {{estancias}}

        En la guía de tu reserva tienes todo lo que necesitas:
        🚪 Cómo encontrar tu puerta, con croquis, foto y video
        🔑 Cómo recoger tus llaves de la caja fuerte digital (se habilita a las 8:00 del día anterior a tu llegada)
        🔥 Calefacción para las noches frías, si la quieres: precio y cómo pedirla
        🏡 Fotos de tu casita, normas de la casa y el detalle de tu cuenta

        👉 {{guide_url}}

        Cualquier duda, escríbenos por aquí.
        TXT;

    /**
     * La de Meta, para WhatsApp con la ventana cerrada — que al minuto de reservar es siempre.
     *
     * ⚠️ **Sin fechas**: `{{estancias}}` ocupa una línea por tramo y Meta no admite saltos de
     * línea en un parámetro. Están en la guía.
     *
     * ⚠️ **El enlace va en el botón, no en el texto**: la de Airbnb lo llevaba en los dos sitios.
     *
     * ⚠️ **Sin tours**, para inclinar la balanza hacia `UTILITY` — más barata y sin el tope de
     * frecuencia de Meta. Las dos bienvenidas viejas, que promocionan, están en `MARKETING`; las
     * que sólo hablan de la guía (`enviar_guia`, `recordatorio_llegada`), en `UTILITY`.
     *
     * ⚠️ **Pero la categoría la decide META, no el texto.** El corte se ha cumplido en las doce
     * plantillas oficiales —lo que promociona o pide reseña cayó en `MARKETING`, lo transaccional
     * en `UTILITY`—, y aun así **no se puede planificar contando con el resultado**: `check_out`
     * está en `MARKETING` y es un aviso de salida. Quitar la promoción mejora las probabilidades;
     * no las garantiza. Si una versión nueva cae en `MARKETING`, no es un fallo que arreglar aquí.
     */
    private const string CUERPO_META = <<<'TXT'
        Hola {{guest_name}}, soy Susan, de Centro Cusco Inti. Te damos la bienvenida 😊

        En la guía de tu reserva tienes todo lo que necesitas:
        🚪 Cómo encontrar tu puerta, con croquis, foto y video
        🔑 Cómo recoger tus llaves (se habilita a las 8:00 del día anterior a tu llegada)
        🔥 Calefacción para las noches frías, si la quieres: precio y cómo pedirla
        🏡 Fotos de tu casita, normas de la casa y el detalle de tu cuenta

        Ábrela con el botón de abajo. Cualquier duda, escríbenos por aquí.
        TXT;

    /**
     * Las reglas de bienvenida que se repuntan, con el minuto que les toca.
     *
     * Booking va DESPUÉS de `politicas_booking`, que sale a +1: primero lo que obliga, luego lo
     * cálido. Airbnb no tiene políticas que mandar y se queda en +1.
     *
     * ⚠️ **El hueco es de un minuto, no de siete.** Estuvo en +8 por prudencia, y sobra: el envío
     * a Booking y Airbnb pasa por Beds24 y llega en segundos. Y el orden no depende de que a
     * alguien le dé tiempo — `MessageRuleEngine` calcula un `run_at` exacto para cada uno y la
     * cola los toma por esa fecha, así que un minuto separa de verdad. No es un margen de
     * seguridad contra la latencia: es sólo lo que hace falta para que se lean en orden.
     */
    private const array REPUNTES = [
        'welcome_booking' => ['bienvenida_booking', 2],
        'welcome_airbnb' => ['bienvenida', 1],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('activar', null, InputOption::VALUE_NONE, 'Repunta las reglas. Sólo con la plantilla aprobada en Meta.')
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
        if ($this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]) !== null) {
            // Idempotente y sin pisar: puede haberla afinado alguien desde el panel.
            $io->note('«bienvenida» ya existe: no se toca.');

            return Command::SUCCESS;
        }

        if ($simular) {
            $io->note('Simulación: se crearía «bienvenida».');

            return Command::SUCCESS;
        }

        $this->em->persist($this->plantilla());
        // `AutoTranslate` corre en `prePersist` y rellena los seis idiomas que faltan.
        $this->em->flush();

        $io->success('«bienvenida» creada. Súbela con: msg:meta:push bienvenida --todos');

        return Command::SUCCESS;
    }

    private function activar(SymfonyStyle $io, bool $simular): int
    {
        $plantillas = $this->em->getRepository(MessageTemplate::class);

        // ── La puerta: LAS DOS aprobadas enteras, o nada ────────────────────────
        //
        // Mismo criterio que el encolador (`WhatsappMetaSendEnqueuer`), que es quien de verdad
        // decide si sale fuera de la ventana: el estado POR IDIOMA, no `is_official_meta`.
        //
        // ⚠️ Se comprueban las DOS: si sólo estuviera aprobada una, repuntar dejaría a la otra OTA
        // sin bienvenida por WhatsApp, y un envío automático que falla no avisa a nadie.
        /** @var array<string, MessageTemplate> $destinos */
        $destinos = [];

        foreach (self::REPUNTES as [$codigoNuevo, $minuto]) {
            if (isset($destinos[$codigoNuevo])) {
                continue;
            }

            $plantilla = $plantillas->findOneBy(['code' => $codigoNuevo]);

            if (!$plantilla instanceof MessageTemplate) {
                $io->error(sprintf('No existe «%s». Créala primero sin --activar.', $codigoNuevo));

                return Command::FAILURE;
            }

            $destinos[$codigoNuevo] = $plantilla;
        }

        foreach ($destinos as $codigo => $plantilla) {
            $cuerpos = $plantilla->getWhatsappMetaTmpl()['body'] ?? [];
            $sinAprobar = [];

            foreach ($cuerpos as $cuerpo) {
                $idioma = (string) ($cuerpo['language'] ?? '');

                if ($idioma !== '' && !$plantilla->hasWhatsappMetaOfficialData($idioma)) {
                    $sinAprobar[] = sprintf('%s (%s)', strtoupper($idioma), $cuerpo['status'] ?? 'SIN ENVIAR');
                }
            }

            if ($cuerpos === [] || $sinAprobar !== []) {
                $io->error(sprintf(
                    'Meta todavía no ha aprobado «%s» entera: %s. Repuntar ahora dejaría esa '
                    . 'bienvenida sin salir por WhatsApp. Trae el estado con '
                    . 'app:whatsapp:sync-templates y vuelve a probar.',
                    $codigo,
                    $sinAprobar === [] ? 'no tiene cuerpos de Meta' : implode(', ', $sinAprobar)
                ));

                return Command::FAILURE;
            }
        }

        $reglas = $this->em->getRepository(MessageRule::class)->findAll();
        $filas = [];

        // ── Repuntar las bienvenidas ────────────────────────────────────────────
        foreach (self::REPUNTES as $codigoViejo => [$codigoNuevo, $minuto]) {
            $regla = $this->reglaDe($reglas, $codigoViejo) ?? $this->reglaDe($reglas, $codigoNuevo, $codigoViejo);

            if ($regla === null) {
                $filas[] = [$codigoViejo, '<error>no hay regla que repuntar</error>'];
                continue;
            }

            if ($regla->getTemplate()?->getCode() === $codigoNuevo && $regla->getOffsetMinutes() === $minuto) {
                $filas[] = [(string) $regla->getName(), sprintf('<comment>ya apuntaba a %s</comment>', $codigoNuevo)];
                continue;
            }

            $filas[] = [(string) $regla->getName(), sprintf('%s → %s, +%d min', $codigoViejo, $codigoNuevo, $minuto)];

            if (!$simular) {
                $regla->setTemplate($destinos[$codigoNuevo])->setOffsetMinutes($minuto);
            }
        }

        // ── La regla de las políticas, en el mismo paso ─────────────────────────
        $politicas = $plantillas->findOneBy(['code' => 'politicas_booking']);

        if (!$politicas instanceof MessageTemplate) {
            $io->error('No existe «politicas_booking»: sin ella, repuntar dejaría a Booking sin las cuentas. No se hace nada.');

            return Command::FAILURE;
        }

        if ($this->reglaDe($reglas, 'politicas_booking') !== null) {
            $filas[] = ['Políticas a Booking', '<comment>ya existe</comment>'];
        } else {
            $filas[] = ['Políticas a Booking', 'nueva: politicas_booking, +1 min, sólo Beds24'];

            if (!$simular) {
                $this->em->persist($this->reglaDePoliticas($politicas));
            }
        }

        // ── Y el catálogo de tours, en su propio mensaje ────────────────────────
        $tours = $plantillas->findOneBy(['code' => 'menu_tours']);

        if (!$tours instanceof MessageTemplate) {
            $io->warning('No existe «menu_tours»: la bienvenida se repunta igual, pero por WhatsApp no saldrá el catálogo.');
        } elseif ($this->reglaDe($reglas, 'menu_tours') !== null) {
            $filas[] = ['Tours por WhatsApp', '<comment>ya existe</comment>'];
        } else {
            $filas[] = ['Tours por WhatsApp', 'nueva: menu_tours, +20 min, sólo WhatsApp'];

            if (!$simular) {
                $this->em->persist($this->reglaDeTours($tours));
            }
        }

        if (!$simular) {
            $this->em->flush();
        }

        $io->table(['Regla', 'Qué pasa'], $filas);
        $simular
            ? $io->note('Simulación: no se ha escrito nada.')
            : $io->success('Reglas activas. Las reservas nuevas de Booking y Airbnb reciben ya la bienvenida única.');

        return Command::SUCCESS;
    }

    /**
     * La regla que apunta a una plantilla. Con `$nombreDeRegla`, además, la que ya se repuntó —
     * para que volver a correr `--activar` reconozca su propio trabajo en vez de quejarse.
     *
     * @param list<MessageRule> $reglas
     */
    private function reglaDe(array $reglas, string $codigo, ?string $codigoOriginal = null): ?MessageRule
    {
        foreach ($reglas as $regla) {
            if ($regla->getTemplate()?->getCode() !== $codigo) {
                continue;
            }

            // Repuntada: se distingue por su OTA, que es lo único que separa a las dos.
            if ($codigoOriginal !== null) {
                $ota = $codigoOriginal === 'welcome_booking' ? 'booking' : 'airbnb';

                if (!in_array($ota, $regla->getAllowedSources(), true)) {
                    continue;
                }
            }

            return $regla;
        }

        return null;
    }

    /**
     * El catálogo de tours, 20 minutos después y **sólo por WhatsApp**.
     *
     * ── Por qué va aparte y no dentro de la bienvenida ──────────────────────
     * Porque la promoción inclina la plantilla hacia `MARKETING`, y MARKETING no sólo cuesta más:
     * entra en el **tope de frecuencia** de Meta. Un huésped que ya recibió promociones ese mes
     * podría quedarse sin la bienvenida — que es el mensaje que lleva el enlace a su guía y no
     * puede faltar. Metiéndolos en el mismo mensaje se arriesga lo importante por lo opcional.
     *
     * Separados, cada uno queda en su categoría: la bienvenida transaccional y siempre entregada,
     * el catálogo en `menu_tours`, que ya es `MARKETING` y ya está aprobada en los siete idiomas.
     *
     * ── Sólo WhatsApp, y el reparto es limpio ───────────────────────────────
     * | canal | cómo llega el catálogo |
     * |---|---|
     * | chat de la OTA (Beds24) | **dentro** de la bienvenida: ahí es texto libre y no cuesta nada |
     * | WhatsApp | en **este** mensaje, aparte |
     *
     * Por eso el cuerpo de enlace de la bienvenida ya NO lleva tours: si los llevara, el huésped
     * que hubiera escrito antes de los 20 minutos los recibiría dos veces.
     *
     * ⚠️ **Y `menu_tours` se quedó sin cuerpo de enlace a propósito.** Dentro de la ventana la
     * estrategia prueba ese cuerpo y, al no haberlo, cae al de Meta: así WhatsApp manda siempre el
     * MISMO texto, esté la ventana abierta o cerrada. Una sola manera.
     *
     * ⚠️ El comando `msg:plantilla:ver … --canal=link` dirá que `menu_tours` no tiene cuerpo de
     * enlace. Es una limitación de esa herramienta, que no simula el respaldo; el envío sí lo hace.
     *
     * Las directas no entran: no reciben bienvenida, así que un catálogo suelto llegaría detrás de
     * una negociación personalizada y sin nada que lo anteceda.
     */
    private function reglaDeTours(MessageTemplate $tours): MessageRule
    {
        $regla = (new MessageRule())
            ->setName('Tours por WhatsApp')
            ->setContextType('pms_reserva')
            ->setTemplate($tours)
            ->setMilestone(ConversationMilestoneInterface::CREATED)
            ->setOffsetMinutes(20)
            ->setAllowedSources(['booking', 'airbnb']);

        $whatsapp = $this->em->getRepository(MessageChannel::class)->find('whatsapp_meta');

        if ($whatsapp instanceof MessageChannel) {
            $regla->addTargetCommunicationChannel($whatsapp);
        }

        return $regla;
    }

    private function reglaDePoliticas(MessageTemplate $politicas): MessageRule
    {
        $regla = (new MessageRule())
            ->setName('Políticas a Booking')
            ->setContextType('pms_reserva')
            ->setTemplate($politicas)
            ->setMilestone(ConversationMilestoneInterface::CREATED)
            ->setOffsetMinutes(1)
            ->setAllowedSources(['booking']);

        // Sólo Beds24, aunque la plantilla ya tenga WhatsApp apagado: su valor es quedar escrita
        // en el chat de Booking, y que la regla lo diga también evita que un cambio en la
        // plantilla la mande sola por otro canal.
        $beds24 = $this->em->getRepository(MessageChannel::class)->find('beds24');

        if ($beds24 instanceof MessageChannel) {
            $regla->addTargetCommunicationChannel($beds24);
        }

        return $regla;
    }

    private function plantilla(): MessageTemplate
    {
        $cuerpo = [['language' => 'es', 'content' => self::CUERPO]];

        return (new MessageTemplate())
            ->setCode(self::CODIGO)
            ->setName('Bienvenida (Booking y Airbnb)')
            ->setContextType('pms_reserva')
            ->setAllowedSources(['booking', 'airbnb'])
            // No se la pide el huésped: si quiere su guía, para eso está `enviar_guia`.
            ->setAutoenvioHabilitada(false)
            ->setAgenteUso(
                'La bienvenida automática al reservar por Booking o Airbnb: lleva a la guía. '
                . 'La manda la regla del alta, no hace falta que la mandes tú. Si el huésped pide '
                . 'su guía, usa enviar_guia.'
            )
            // El enlace ya va escrito en el texto: con la botonera, saldría dos veces.
            ->setBeds24Tmpl(['is_active' => true, 'disable_meta_buttons' => true, 'body' => $cuerpo])
            ->setWhatsappLinkTmpl(['disable_meta_buttons' => true, 'body' => $cuerpo])
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                // Con sufijo desde el primer día: ver §18 de docs/Mensajeria.md.
                'meta_template_name' => 'bienvenida_v1',
                // Lo pone el sincronizador cuando Meta responde; a mano diría «aprobada» de algo
                // que nunca se subió.
                'is_official_meta' => false,
                'header' => [],
                'footer' => [],
                'body' => [['language' => 'es', 'content' => self::CUERPO_META]],
                'buttons_map' => [[
                    'index' => 0,
                    'type' => 'url',
                    'content' => 'https://pax.openperu.pe/{{1}}',
                    // `guide_path`, sin ancla: el mismo botón que ya tienen aprobado
                    // `enviar_guia` y `recordatorio_llegada`.
                    'resolver_key' => 'guide_path',
                    'button_text' => [['language' => 'es', 'content' => 'Ver mi guía']],
                ]],
            ])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);
    }
}
