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
 * La hora de la RESERVA en los mensajes de llegada y salida, no un 14:00 / 10:00 escrito.
 *
 * Jorge, 02/10/2026: «la hora que figura allí debería ser la de la reserva, que en caso de no ser
 * modificada coincide con el check-in / check-out». Las variables son `checkin_time` y
 * `checkout_time` (`PmsMessageDataResolver::horaDeLaReserva()`).
 *
 * ── Qué hace ────────────────────────────────────────────────────────────────
 * 1. **Los textos que no pasan por Meta**, ya: el de Beds24 de `aviso_salida`, los de Beds24 y del
 *    enlace de `guia_llegada` / `guia_llegada_booking`, y `salida_confirmada`. Sólo se toca la fila
 *    en español; las demás las rehace el traductor al cambiar el origen.
 *    El texto de dentro de la ventana de `aviso_salida` NO: va con el botón «Salgo a las 10:00», y
 *    decir otra hora encima de ese botón sería peor que esperar a la v2.
 * 2. **`aviso_salida_v2`** para Meta, con el botón «Salgo a esa hora» (un botón de respuesta no
 *    admite variables) y su propio payload, que apunta la hora de la ESTANCIA. El viejo sigue
 *    apuntando las 10:00, que es lo que dice su botón, mientras salga la v1.
 * 3. **`--activar`**: cuando Meta apruebe la v2 entera, la regla «Check Out» pasa a ella y los
 *    avisos ya en cola se recolocan.
 *
 * La guía de llegada con botones lleva la hora en `msg:plantillas:guia-llegada-botones`.
 *
 *   php bin/console msg:plantillas:hora-de-la-reserva [--dry-run]
 *   php bin/console msg:meta:push aviso_salida_v2 --todos
 *   php bin/console msg:plantillas:hora-de-la-reserva --activar
 */
#[AsCommand(
    name: 'msg:plantillas:hora-de-la-reserva',
    description: 'Pone la hora de la reserva en llegada y salida; crea aviso_salida_v2 y, con --activar, la estrena.',
)]
final class MessageHoraDeLaReservaCommand extends Command
{
    public const string CODIGO = 'aviso_salida_v2';
    private const string CODIGO_V1 = 'aviso_salida';
    public const string CMD_A_SU_HORA = 'CMD_SALIDA_A_SU_HORA';

    /**
     * El nombre en Meta. `aviso_salida_v2` llevaba «Necesito más tiempo», que no dice para qué
     * —¿para decidir?, ¿para quedarse?—; se rehízo con «Necesito salir tarde» antes de activarla
     * (Jorge, 02/10/2026). La `_v2` queda en Meta sin dueño. El `code` local sigue siendo `_v2`.
     */
    private const string META = 'aviso_salida_v3';

    /** [plantilla, campo, lo que dice ahora, lo que pasa a decir] — sólo en la fila «es». */
    private const array TEXTOS_LOCALES = [
        ['aviso_salida', 'beds24', 'a las 10:00 am.', 'a las {{checkout_time}}.'],
        ['guia_llegada', 'beds24', 'El check-in es desde las 14:00. Por favor, dinos a qué hora tienes planificado llegar.', 'Tu check-in es desde las {{checkin_time}}. ¿A qué hora tienes planeado llegar?'],
        ['guia_llegada', 'link', 'El check-in es desde las 14:00. Por favor, dinos a qué hora tienes planificado llegar.', 'Tu check-in es desde las {{checkin_time}}. ¿A qué hora tienes planeado llegar?'],
        ['guia_llegada_booking', 'beds24', 'El check-in es desde las 14:00. Por favor, dinos a qué hora tienes planificado llegar.', 'Tu check-in es desde las {{checkin_time}}. ¿A qué hora tienes planeado llegar?'],
        ['guia_llegada_booking', 'link', 'El check-in es desde las 14:00. Por favor, dinos a qué hora tienes planificado llegar.', 'Tu check-in es desde las {{checkin_time}}. ¿A qué hora tienes planeado llegar?'],
        ['salida_confirmada', 'link', 'a las 10:00.', 'a las {{checkout_time}}.'],
        ['salida_confirmada', 'meta', 'a las 10:00.', 'a las {{checkout_time}}.'],
    ];

    private const string CUERPO = "¡Hola {{guest_name}}! Esperamos que hayas disfrutado mucho tu tiempo en Cusco. 😊 "
        . "Te escribimos para recordarte que tu check-out es mañana, {{checkout_date}}, a las {{checkout_time}}. "
        . "¿A qué hora tienes planeado salir?\n\n"
        . "🔑 En este enlace te dejamos unas breves instrucciones para tu salida (llaves, cocina, basura y luces): {{salida_url}}\n\n"
        . "🧳 Si necesitas que guardemos tu equipaje después del check-out, avísanos y lo coordinamos.";

    private const string CUERPO_META = "¡Hola {{guest_name}}! Esperamos que hayas disfrutado mucho tu tiempo en Cusco. 😊 "
        . "Te escribimos para recordarte que tu check-out es mañana, {{checkout_date}}, a las {{checkout_time}}. "
        . "¿A qué hora tienes planeado salir?\n\n"
        . "🔑 En el botón de abajo te dejamos unas breves instrucciones para tu salida (llaves, cocina, basura y luces).\n\n"
        . "🧳 Si necesitas que guardemos tu equipaje después del check-out, avísanos y lo coordinamos.";

    private const string AGENTE_USO = 'Aviso de salida: la hora del check-out de su reserva, la pregunta de a qué hora '
        . 'sale y el enlace a las instrucciones de salida. LA MANDA SOLA el motor de reglas el día antes de la salida.';

    /** @var array<string, array{0: string, 1: string, 2: string, 3: string}> idioma => [a esa hora, antes, más tiempo, instrucciones] */
    private const array BOTONES = [
        'es' => ['Salgo a esa hora', 'Saldré antes', 'Necesito salir tarde', 'Instrucciones de salida'],
        'en' => ['Leaving at that time', 'Leaving earlier', 'I need to leave late', 'Check-out guide'],
        'pt' => ['Saio nesse horário', 'Vou sair antes', 'Preciso sair tarde', 'Instruções de saída'],
        'fr' => ["Je pars à l'heure", 'Je pars plus tôt', 'Je dois partir tard', 'Consignes de départ'],
        'it' => ["Esco a quell'ora", 'Esco prima', 'Devo uscire tardi', 'Istruzioni uscita'],
        'de' => ['Ich reise dann ab', 'Ich reise früher ab', 'Muss später abreisen', 'Hinweise Abreise'],
        'nl' => ['Ik vertrek dan', 'Ik vertrek eerder', 'Ik moet later weg', 'Vertrekinstructies'],
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
            ->addOption('activar', null, InputOption::VALUE_NONE, 'Repunta «Check Out» a aviso_salida_v2. Sólo con Meta aprobada entera.')
            ->addOption('rehacer', null, InputOption::VALUE_NONE, 'Borra y vuelve a crear aviso_salida_v2, sólo si no la usa ninguna regla ni ningún mensaje.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');

        if ($input->getOption('activar')) {
            return $this->activar($io, $simular);
        }

        if ($input->getOption('rehacer') && !$this->rehacer($io, $simular)) {
            return Command::FAILURE;
        }

        $this->textosLocales($io, $simular);
        $this->crearV2($io, $simular);
        $this->reglaDeBoton($io, $simular);

        return Command::SUCCESS;
    }

    private function textosLocales(SymfonyStyle $io, bool $simular): void
    {
        $plantillas = $this->em->getRepository(MessageTemplate::class);

        foreach (self::TEXTOS_LOCALES as [$codigo, $campo, $antes, $despues]) {
            $plantilla = $plantillas->findOneBy(['code' => $codigo]);

            if (!$plantilla instanceof MessageTemplate) {
                $io->note(sprintf('No existe «%s»: se salta.', $codigo));
                continue;
            }

            $cambiado = $this->ponerHora($plantilla, (string) $campo, (string) $antes, (string) $despues, $simular);

            if (!$cambiado) {
                $io->text(sprintf('· %s (%s): ya dice la hora de la reserva, o el texto cambió a mano. No se toca.', $codigo, $campo));
                continue;
            }

            $io->text(sprintf('+ %s (%s): «%s» → «%s»', $codigo, $campo, $antes, $despues));

            if (!$simular) {
                // Uno a uno: el traductor rehace los idiomas del campo que cambió en el `preUpdate`.
                $this->em->flush();
            }
        }
    }

    /** Cambia `$antes` por `$despues` en la fila «es» del cuerpo de ese canal. Dice si cambió algo. */
    private function ponerHora(MessageTemplate $plantilla, string $campo, string $antes, string $despues, bool $simular): bool
    {
        $esLaFila = static fn (mixed $fila): bool => is_array($fila) && ($fila['language'] ?? '') === 'es'
            && is_string($fila['content'] ?? null) && str_contains($fila['content'], $antes);

        if ($campo === 'beds24') {
            $tmpl = $plantilla->getBeds24Tmpl() ?? [];
            foreach ($tmpl['body'] ?? [] as $i => $fila) {
                if ($esLaFila($fila)) {
                    $tmpl['body'][$i]['content'] = str_replace($antes, $despues, (string) ($fila['content'] ?? ''));
                    if (!$simular) {
                        $plantilla->setBeds24Tmpl($tmpl);
                    }

                    return true;
                }
            }

            return false;
        }

        if ($campo === 'link') {
            $tmpl = $plantilla->getWhatsappLinkTmpl() ?? [];
            foreach ($tmpl['body'] ?? [] as $i => $fila) {
                if ($esLaFila($fila)) {
                    $tmpl['body'][$i]['content'] = str_replace($antes, $despues, (string) ($fila['content'] ?? ''));
                    if (!$simular) {
                        $plantilla->setWhatsappLinkTmpl($tmpl);
                    }

                    return true;
                }
            }

            return false;
        }

        $tmpl = $plantilla->getWhatsappMetaTmpl() ?? [];
        foreach ($tmpl['body'] ?? [] as $i => $fila) {
            if ($esLaFila($fila)) {
                $tmpl['body'][$i]['content'] = str_replace($antes, $despues, (string) ($fila['content'] ?? ''));
                if (!$simular) {
                    $plantilla->setWhatsappMetaTmpl($tmpl);
                }

                return true;
            }
        }

        return false;
    }

    /** Quita la v2 para crearla otra vez, sólo si ni una regla ni un mensaje la han usado. */
    private function rehacer(SymfonyStyle $io, bool $simular): bool
    {
        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]);

        if (!$plantilla instanceof MessageTemplate) {
            return true;
        }

        foreach ($this->em->getRepository(MessageRule::class)->findAll() as $regla) {
            if ($regla->getTemplate() === $plantilla) {
                $io->error(sprintf('La regla «%s» ya usa «%s»: no se rehace.', $regla->getName(), self::CODIGO));

                return false;
            }
        }

        if ($this->em->getRepository(Message::class)->count(['template' => $plantilla]) > 0) {
            $io->error(sprintf('Ya hay mensajes con «%s»: no se rehace.', self::CODIGO));

            return false;
        }

        $io->text(sprintf('Se rehace «%s».', self::CODIGO));

        if (!$simular) {
            $this->em->remove($plantilla);
            $this->em->flush();
        }

        return true;
    }

    private function crearV2(SymfonyStyle $io, bool $simular): void
    {
        if ($this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]) !== null) {
            $io->note(sprintf('«%s» ya existe: no se toca.', self::CODIGO));

            return;
        }

        if ($simular) {
            $io->note(sprintf('Simulación: se crearía «%s».', self::CODIGO));

            return;
        }

        $plantilla = $this->aviso();
        $this->em->persist($plantilla);
        // `AutoTranslate` corre en `prePersist`: traduce los cuerpos y PISA los botones.
        $this->em->flush();

        $this->botonesAMano($plantilla);
        $this->em->flush();

        $io->success(sprintf('«%s» creada. Súbela con: msg:meta:push %s --todos', self::CODIGO, self::CODIGO));
    }

    /** [Salgo a esa hora] apunta la hora de la ESTANCIA y contesta con `salida_confirmada`. */
    private function reglaDeBoton(SymfonyStyle $io, bool $simular): void
    {
        if ($this->em->getRepository(AutoResponderRule::class)->findOneBy(['triggerValue' => self::CMD_A_SU_HORA]) !== null) {
            $io->note(sprintf('La regla «%s» ya existe: no se toca.', self::CMD_A_SU_HORA));

            return;
        }

        if ($simular) {
            $io->note(sprintf('Simulación: regla «%s» → confirmar_hora (hora de la estancia).', self::CMD_A_SU_HORA));

            return;
        }

        $regla = (new AutoResponderRule())
            ->setTriggerValue(self::CMD_A_SU_HORA)
            ->setActionType('confirmar_hora')
            ->setActionParameters(['extremo' => 'salida', 'hora' => 'estancia', 'plantilla_respuesta' => 'salida_confirmada'])
            ->setIsActive(true);
        $regla->initializeId();
        $this->em->persist($regla);
        $this->em->flush();
    }

    private function activar(SymfonyStyle $io, bool $simular): int
    {
        $nueva = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]);

        if (!$nueva instanceof MessageTemplate) {
            $io->error(sprintf('Falta «%s». Créala primero sin --activar.', self::CODIGO));

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
            $io->error(sprintf('Meta todavía no ha aprobado «%s» entera: %s. Trae el estado con app:whatsapp:sync-templates.', self::CODIGO, implode(', ', $faltan)));

            return Command::FAILURE;
        }

        $regla = null;
        foreach ($this->em->getRepository(MessageRule::class)->findAll() as $candidata) {
            if (in_array($candidata->getTemplate()?->getCode(), [self::CODIGO_V1, self::CODIGO], true)) {
                $regla = $candidata;
                break;
            }
        }

        if ($regla === null) {
            $io->error('No hay regla que apunte a «aviso_salida» ni a «aviso_salida_v2»: no se crea una a ciegas.');

            return Command::FAILURE;
        }

        if ($regla->getTemplate() === $nueva) {
            $io->success(sprintf('La regla «%s» ya apuntaba a %s.', $regla->getName(), self::CODIGO));

            return Command::SUCCESS;
        }

        $hilos = $this->hilosConAvisoEnCola($regla);
        $io->text(sprintf('«%s»: aviso_salida → %s. %d hilos con el aviso en cola se recolocan.', $regla->getName(), self::CODIGO, count($hilos)));

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $regla->setTemplate($nueva);
        $this->em->flush();

        // El motor reconoce lo programado por el `rule_id` y alinea la plantilla.
        foreach ($hilos as $hilo) {
            $this->motor->syncConversationRules($hilo, MessageRuleEngine::TRIGGER_UPDATE);
        }

        $io->success('Aviso de salida con la hora de la reserva activo.');

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
        [$aSuHora, $antes, $masTarde, $instrucciones] = self::BOTONES['es'];

        return (new MessageTemplate())
            ->setCode(self::CODIGO)
            ->setName('Aviso de salida con la hora de la reserva (con botones)')
            ->setContextType('pms_reserva')
            ->setAllowedSources([])
            ->setAutoenvioHabilitada(false)
            ->setAgenteUso(self::AGENTE_USO)
            // Por Beds24 sin opciones (~6 min de ida y vuelta): pregunta abierta y el enlace en el texto.
            ->setBeds24Tmpl(['is_active' => true, 'disable_meta_buttons' => true, 'body' => [['language' => 'es', 'content' => self::CUERPO]]])
            // Dentro de la ventana, con botones de verdad: la casilla «Ocultar» va DESMARCADA.
            ->setWhatsappLinkTmpl(['disable_meta_buttons' => false, 'body' => [['language' => 'es', 'content' => self::CUERPO]]])
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                'meta_template_name' => self::META,
                'is_official_meta' => false,
                'header' => [],
                'footer' => [],
                'body' => [['language' => 'es', 'content' => self::CUERPO_META]],
                'buttons_map' => [
                    ['index' => 0, 'type' => 'quick_reply', 'content' => null, 'resolver_key' => self::CMD_A_SU_HORA, 'button_text' => [['language' => 'es', 'content' => $aSuHora]]],
                    ['index' => 1, 'type' => 'quick_reply', 'content' => null, 'resolver_key' => MessageCrearAvisoSalidaCommand::CMD_ANTES, 'button_text' => [['language' => 'es', 'content' => $antes]]],
                    ['index' => 2, 'type' => 'quick_reply', 'content' => null, 'resolver_key' => MessageCrearAvisoSalidaCommand::CMD_MAS_TARDE, 'button_text' => [['language' => 'es', 'content' => $masTarde]]],
                    ['index' => 3, 'type' => 'url', 'content' => 'https://pax.openperu.pe/{{1}}', 'resolver_key' => 'salida_path', 'button_text' => [['language' => 'es', 'content' => $instrucciones]]],
                ],
                'ejemplos' => array_map(
                    static fn (): array => ['guest_name' => 'Anna', 'checkout_date' => '05/02/2027', 'checkout_time' => '10:00'],
                    self::BOTONES
                ),
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
