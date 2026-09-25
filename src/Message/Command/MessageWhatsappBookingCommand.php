<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mete el enlace de WhatsApp en la BIENVENIDA de Booking, y reescribe la plantilla suelta.
 *
 * ── Qué cambia en Booking ───────────────────────────────────────────────────
 * A partir de esa fecha **los números de teléfono dejan de transmitirse por los canales de
 * conectividad**. Booking lo justifica por el phishing y deja tres salidas: la extranet, su app
 * Pulse, o seguir por su propia mensajería y el email anónimo. Para nosotros significa que un
 * huésped de Booking ya no llega con teléfono, así que:
 *
 * - no se le puede escribir por WhatsApp aunque quiera;
 * - si él escribe, su número no casa con ninguna reserva y cae en un hilo «manual», donde el
 *   agente contesta que no hay reserva asociada a alguien que entra mañana.
 *
 * ── Por qué el texto viejo ya no vale ───────────────────────────────────────
 * Decía: «si prefieres usar WhatsApp, por favor **envíanos tu número**». Eso deja el trabajo del
 * lado de una persona: leerlo, copiarlo y registrarlo a mano, con sus erratas. Y nunca se mandó.
 *
 * El nuevo invierte el sentido: le damos **un enlace con el mensaje ya escrito**
 * (`{{ whatsapp_enlace_reserva }}`), él le da a enviar, y su primer mensaje trae su localizador.
 * {@see \App\Message\Service\Inbound\ReservaPorLocalizador} lo casa con su reserva y le engancha
 * el número solo. Nadie teclea nada y no hay erratas que corregir.
 *
 * ── Sólo Booking ────────────────────────────────────────────────────────────
 * La plantilla estaba acotada a `booking` y `airbnb`. Se queda en **booking** a secas: Airbnb
 * sigue transmitiendo teléfono, y pedirle el WhatsApp a quien ya lo tiene puesto es ruido — y en
 * Airbnb, además, sacar la conversación de la plataforma tiene sus propias reglas.
 *
 * ── Por qué va en la BIENVENIDA y no sólo en su plantilla ───────────────────
 * Porque la bienvenida sale **sola**, dos minutos después de crearse la reserva, a todos los
 * huéspedes de Booking. La plantilla suelta hay que acordarse de mandarla, y la prueba de que
 * eso no ocurre es que existe desde hace meses y **no se ha mandado ni una vez**. Lo que depende
 * de que alguien se acuerde, no pasa.
 *
 * La plantilla suelta se queda igualmente, reescrita, para mandarla a mano a quien ya reservó
 * antes de este cambio.
 *
 * Nace `hidden` porque es de una vez. Idempotente: si el cuerpo ya es el nuevo, no toca nada.
 */
#[AsCommand(
    name: 'msg:whatsapp-booking',
    description: 'Reescribe la plantilla de solicitud de WhatsApp para Booking, con enlace directo.',
    hidden: true,
)]
final class MessageWhatsappBookingCommand extends Command
{
    private const string CODIGO = 'solicitar_numero_whatsapp';

    private const string BIENVENIDA = 'bienvenida_booking';

    /** Dónde se engancha el bloque: justo antes del cierre de siempre. */
    private const string ANCLA_BIENVENIDA = 'Cualquier duda, escríbenos por aquí.';

    /**
     * El bloque para la bienvenida.
     *
     * ⚠️ Empieza por el MOTIVO. Pedir un WhatsApp desde el chat de una OTA es, palabra por
     * palabra, lo que hace el phishing del que Booking avisa este mismo mes; sin explicar por
     * qué, el huésped hace bien en desconfiar. Con el motivo —que Booking dejó de pasarnos su
     * número— la petición se sostiene sola y además es verdad.
     *
     * El enlace va entero y no como «escríbenos al 9…»: un número hay que copiarlo, y al
     * copiarlo se pierde el localizador, que es lo único que hace que esto funcione solo.
     */
    private const string BLOQUE_BIENVENIDA = <<<'TXT'
        📲 *Información sobre nuestro canal de WhatsApp*
        Debido a las nuevas políticas de privacidad de Booking.com, ya no tenemos acceso a los números de teléfono de nuestros huéspedes para iniciar el contacto.

        Si prefieres que gestionemos tu estadía de forma más directa por WhatsApp, haz clic en el siguiente enlace y envía el mensaje predeterminado:
        👉 {{ whatsapp_enlace_reserva }}

        Esto nos permitirá identificar tu reserva al instante. Si prefieres no utilizar WhatsApp, seguiremos atendiéndote con mucho gusto a través de este chat.

        Cualquier consulta, seguimos a tu disposición.
        TXT;

    /**
     * La primera redacción, que se sustituye.
     *
     * Se guarda entera para poder reconocerla y cambiarla: el comando ya corrió el 22/09 y dejó
     * ese texto en producción, así que «si ya lleva el marcador, no toco nada» habría dejado la
     * versión vieja para siempre. Idempotente por CONTENIDO, no por presencia.
     *
     * Arrastra el cierre de la bienvenida porque el bloque nuevo trae el suyo: dejar los dos
     * pondría «cualquier duda» y «cualquier consulta» en renglones seguidos.
     */
    private const string BLOQUE_PRIMERA_VERSION = <<<'TXT'
        📲 *Una cosa más, y es nueva*
        Booking cambió su política y ya no nos comparte el teléfono de sus huéspedes, así que no podemos escribirte por WhatsApp aunque lo necesites.

        Si prefieres que hablemos por ahí, abre este enlace y envía el mensaje que ya aparece escrito:
        👉 {{ whatsapp_enlace_reserva }}

        Con eso te reconocemos al instante. Si no, seguimos por aquí sin problema.

        Cualquier duda, escríbenos por aquí.
        TXT;

    /**
     * El cuerpo, para el chat de Booking.
     *
     * ⚠️ Dice POR QUÉ se le pide. Sin el motivo, «pásame tu WhatsApp» desde una plataforma es
     * exactamente lo que hace el phishing del que Booking está avisando ese mismo mes, y el
     * huésped desconfía con razón. Con el motivo —que Booking dejó de pasarnos su número— la
     * petición se explica sola y además es verdad.
     *
     * El enlace va ENTERO y no como «escríbenos al 9…»: un número escrito hay que copiarlo, y al
     * copiarlo se pierde el localizador, que es lo único que hace que esto funcione.
     */
    private const string CUERPO = <<<'TXT'
        ¡Hola {{ guest_name }}!

        📲 Información sobre nuestro canal de WhatsApp

        Debido a las nuevas políticas de privacidad de Booking.com, ya no tenemos acceso a los números de teléfono de nuestros huéspedes para iniciar el contacto.

        Si prefieres que gestionemos tu estadía de forma más directa por WhatsApp, haz clic en el siguiente enlace y envía el mensaje predeterminado:
        👉 {{ whatsapp_enlace_reserva }}

        Esto nos permitirá identificar tu reserva al instante y recibirás por ahí las instrucciones de llegada, la dirección y lo que necesites.

        Si prefieres no utilizar WhatsApp, seguiremos atendiéndote con mucho gusto a través de este chat.

        ¡Saludos desde Cusco!
        TXT;

    /**
     * Engancha el bloque en la bienvenida de Booking, justo antes del cierre.
     *
     * Se inserta por ANCLA y no al final porque después del cierre viene el gancho de los tours,
     * y dejar la petición detrás de un anuncio la convierte en una posdata. Va donde el huésped
     * todavía está leyendo.
     */
    private function enBienvenida(SymfonyStyle $io): void
    {
        $bienvenida = $this->em->getRepository(MessageTemplate::class)
            ->findOneBy(['code' => self::BIENVENIDA]);

        if (!$bienvenida instanceof MessageTemplate) {
            $io->warning(sprintf('No existe «%s»: el bloque no se añadió.', self::BIENVENIDA));

            return;
        }

        $beds24 = $bienvenida->getBeds24Tmpl() ?? [];
        $cuerpo = (string) ($beds24['body'][0]['content'] ?? '');

        if (str_contains($cuerpo, 'Información sobre nuestro canal de WhatsApp')) {
            $io->text('· La bienvenida ya lo lleva.');

            return;
        }

        // Dos caminos: la bienvenida que aún no lo tiene, y la que quedó con la primera
        // redacción del 22/09 —que se sustituye entera, cierre incluido—.
        if (str_contains($cuerpo, self::BLOQUE_PRIMERA_VERSION)) {
            $nuevo = str_replace(self::BLOQUE_PRIMERA_VERSION, self::BLOQUE_BIENVENIDA, $cuerpo);
        } elseif (str_contains($cuerpo, self::ANCLA_BIENVENIDA)) {
            $nuevo = str_replace(self::ANCLA_BIENVENIDA, self::BLOQUE_BIENVENIDA, $cuerpo);
        } else {
            $io->warning('La bienvenida no es la que esperaba: míralo en el panel.');

            return;
        }

        $bienvenida->setBeds24Tmpl(['body' => [['language' => 'es', 'content' => $nuevo]]] + $beds24);

        $this->em->flush();

        $io->text('<fg=green>+ bloque de WhatsApp añadido a la bienvenida de Booking</>');
    }

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');

        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]);

        if (!$plantilla instanceof MessageTemplate) {
            $io->error(sprintf('No existe la plantilla «%s».', self::CODIGO));

            return Command::FAILURE;
        }

        $beds24 = $plantilla->getBeds24Tmpl() ?? [];
        $actual = (string) ($beds24['body'][0]['content'] ?? '');

        if ($actual === self::CUERPO && $plantilla->getAllowedSources() === ['booking']) {
            $io->success('Ya está como debe.');

            return Command::SUCCESS;
        }

        $io->section('Cuerpo nuevo (Booking)');
        $io->writeln(self::CUERPO);
        $io->newLine();
        $io->text('Orígenes: ' . implode(', ', $plantilla->getAllowedSources() ?: ['—']) . ' → booking');

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        // Sólo el español: `#[AutoTranslate]` rehace los otros seis al guardar, y el marcador
        // del enlace lo protege `ProtectorDeMarcadores` para que el traductor no se lo coma.
        $plantilla
            ->setBeds24Tmpl(['body' => [['language' => 'es', 'content' => self::CUERPO]]] + $beds24)
            ->setAllowedSources(['booking']);

        $this->em->flush();

        $this->enBienvenida($io);

        $io->success('Plantilla y bienvenida listas.');

        return Command::SUCCESS;
    }
}
