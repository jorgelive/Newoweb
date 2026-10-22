<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La plantilla con el enlace a la ficha de las LLAVES INTERIORES (22/10/2026).
 *
 * Los pomos de las puertas interiores llevan botón de seguro, y si una se cierra no hay llave en la
 * casita: no se dejan porque se perdían o se las llevaban (Jorge). Están en la caja de arriba, en la
 * cartuchera AZUL, como «interior N». Darlas obliga a dar el código de esa caja, así que, igual que
 * la llave de emergencia y el pago en efectivo, lo decide una persona: el agente escala y el equipo
 * manda esto.
 *
 * Los pasos no van aquí sino en la ficha `llaves-interiores` (`app:pms:guia:enlaces-del-equipo`),
 * que el enlace abre: ver `docs/PmsGuiaHuesped.md` §7 sexies. Misma estructura que
 * `llave_emergencia` y `pago_efectivo_caja`: envío manual, Beds24 y WhatsApp dentro de ventana.
 *
 * Por el ORM (se traduce al guardar). Idempotente por el código, con `--dry-run`. Nace `hidden`.
 */
#[AsCommand(
    name: 'app:pms:crear-plantilla-llaves-interiores',
    description: 'Crea la plantilla «llaves_interiores» (enlace a la ficha de las llaves interiores). Idempotente.',
    hidden: true,
)]
final class PmsCrearPlantillaLlavesInterioresCommand extends Command
{
    public const string CODIGO = 'llaves_interiores';

    private const string CUERPO = <<<'TXT'
        Hola {{ guest_name }}, aquí tienes cómo sacar la llave para abrir la puerta interior de {{ room_name }}, con el código de la caja, la foto y el vídeo:

        {{ acceso_llaves_interiores }}

        Cuando abras, vuelve a dejar la llave en su cartuchera y cierra la caja. El enlace es sólo para ti: no lo compartas.
        TXT;

    /** Lo que lee el agente del panel: es instrucción, no se traduce. */
    private const string AGENTE_USO = 'Instrucciones para sacar la LLAVE INTERIOR de una casita, para abrir una '
        . 'puerta interior (habitación, baño) que se cerró con el botón del pomo. Las llaves interiores NO se '
        . 'quedan en la casita: están en la caja fuerte de arriba. La manda el equipo. SI TE LO PIDE ALGUIEN '
        . 'DEL EQUIPO —por ejemplo, porque el huésped llamó—, mándala. EL HUÉSPED NO PUEDE PEDIRLA por su '
        . 'cuenta: si se le cerró una puerta interior, no la mandes y escala al equipo diciendo «se le cerró '
        . 'una puerta interior: mandarle llaves_interiores».';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Sólo dice qué crearía.')] bool $dryRun = false,
    ): int {
        if ($this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]) !== null) {
            $io->success(sprintf('«%s» ya existe.', self::CODIGO));

            return Command::SUCCESS;
        }

        $io->section('Se creará «' . self::CODIGO . '»');
        $io->writeln(self::CUERPO);

        if ($dryRun) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $cuerpo = [['language' => 'es', 'content' => self::CUERPO]];

        $this->em->persist((new MessageTemplate())
            ->setCode(self::CODIGO)
            ->setName('Instrucciones llaves interiores')
            ->setContextType('pms_reserva')
            ->setEnvioManual(true)
            ->setAgenteUso(self::AGENTE_USO)
            ->setAutoenvioHabilitada(false)
            ->setBeds24Tmpl(['is_active' => true, 'body' => $cuerpo])
            ->setWhatsappLinkTmpl(['is_active' => true, 'body' => $cuerpo])
            // Activa para elegirla en el chat, sin cuerpo oficial en Meta: Meta no aprueba una
            // plantilla así (docs/Mensajeria.md, «Plantillas que se ofrecen a mano»).
            ->setWhatsappMetaTmpl(['is_active' => true, 'is_official_meta' => false, 'body' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]));

        $this->em->flush();
        $io->success(sprintf('«%s» creada, con sus siete idiomas.', self::CODIGO));

        return Command::SUCCESS;
    }
}
