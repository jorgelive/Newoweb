<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageTemplate;
use App\Message\Service\Formato\FormatoDeTexto;
use App\Message\Service\Formato\HidratadorDeMarcadores;
use App\Message\Service\MessageDataResolverRegistry;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enseña una plantilla **hidratada contra una reserva de verdad**, sin enviar nada.
 *
 * ## Por qué existe
 *
 * Porque hasta ahora la única forma de saber qué le llega al huésped era mandárselo. Los
 * cuerpos llevan `{{ bloque_pago }}`, `{{ medios_de_pago }}` o `{{ estancias }}`, que no son
 * campos: son textos que redacta el dominio mirando la situación de cobro, la audiencia del
 * huésped y los días que faltan. Razonar sobre lo que «debería» salir es exactamente cómo se
 * llega a conclusiones falsas sobre datos ciertos.
 *
 * ## Es el mismo texto, no uno parecido
 *
 * Usa {@see HidratadorDeMarcadores}, el mismo servicio que interpola en el envío real de
 * Beds24, y pide las variables al mismo resolver. Si la previsualización miente, es que el
 * envío también.
 *
 * ⚠️ **Enseña el CUERPO.** Si la plantilla tiene la botonera activada para ese canal, al
 * enviarse se le añaden los botones debajo; se avisa cuando es el caso.
 *
 * ```
 * bin/console msg:plantilla:ver politicas_booking H6Q49C
 * bin/console msg:plantilla:ver pago_texto H6Q49C --canal=link --idioma=en
 * ```
 */
#[AsCommand(
    name: 'msg:plantilla:ver',
    description: 'Enseña una plantilla hidratada contra una reserva real, sin enviarla.'
)]
final class MessageVerPlantillaCommand extends Command
{
    private const array CANALES = ['beds24', 'link', 'meta'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageDataResolverRegistry $resolvers,
        private readonly HidratadorDeMarcadores $hidratador,
        private readonly FormatoDeTexto $formato,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Código de la plantilla.')
            ->addArgument('localizador', InputArgument::REQUIRED, 'Localizador de la reserva («H6Q49C»).')
            ->addOption('idioma', null, InputOption::VALUE_REQUIRED, 'Idioma del cuerpo.', 'es')
            ->addOption('canal', null, InputOption::VALUE_REQUIRED, 'beds24 | link | meta', 'beds24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = (string) $input->getArgument('code');
        $localizador = strtoupper((string) $input->getArgument('localizador'));
        $idioma = strtolower((string) $input->getOption('idioma'));
        $canal = strtolower((string) $input->getOption('canal'));

        if (!in_array($canal, self::CANALES, true)) {
            $io->error(sprintf('Canal «%s». Los que hay: %s.', $canal, implode(', ', self::CANALES)));

            return Command::FAILURE;
        }

        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $code]);

        if (!$plantilla instanceof MessageTemplate) {
            $io->error(sprintf('No existe ninguna plantilla con el código «%s».', $code));

            return Command::FAILURE;
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->findOneBy(['localizador' => $localizador]);

        if (!$reserva instanceof PmsReserva) {
            $io->error(sprintf('No existe ninguna reserva con el localizador «%s».', $localizador));

            return Command::FAILURE;
        }

        $cuerpo = match ($canal) {
            'beds24' => (string) $plantilla->getBeds24Body($idioma),
            'link' => (string) $plantilla->getWhatsappLinkBody($idioma),
            default => (string) $plantilla->getWhatsappMetaBody($idioma),
        };

        if (trim($cuerpo) === '') {
            $io->error(sprintf('«%s» no tiene cuerpo de %s en «%s».', $code, $canal, $idioma));

            return Command::FAILURE;
        }

        $resolver = $this->resolvers->getResolver('pms_reserva');
        $variables = $resolver?->getMessageVariables((string) $reserva->getId(), $idioma) ?? [];

        // Beds24 acaba en la bandeja de Airbnb o Booking, que enseñan el texto tal cual: el envío
        // le quita las marcas a los valores y aquí hay que hacer lo mismo, o el comando enseñaría
        // unos asteriscos que no llegan.
        if ($canal === 'beds24') {
            $variables = $this->formato->valoresParaTextoPlano($variables);
        }

        $faltantes = $this->hidratador->sinResolver($cuerpo, $variables);
        $texto = $this->hidratador->hidratar($cuerpo, $variables);

        $io->title(sprintf(
            '%s · %s · %s · %s',
            $code,
            $localizador,
            strtoupper($idioma),
            $canal
        ));

        $io->writeln('<comment>' . str_repeat('─', 64) . '</comment>');
        $io->writeln($texto);
        $io->writeln('<comment>' . str_repeat('─', 64) . '</comment>');
        $io->newLine();

        $io->text(sprintf('%d caracteres.', mb_strlen($texto)));

        // Un marcador que se queda crudo llega crudo al huésped: es lo primero que hay que ver.
        if ($faltantes !== []) {
            $io->error(sprintf(
                'Marcadores que el resolver no conoce y saldrían tal cual: %s',
                implode(', ', array_map(static fn (string $m): string => '{{' . $m . '}}', $faltantes))
            ));

            return Command::FAILURE;
        }

        $botonera = match ($canal) {
            'beds24' => !$plantilla->isBeds24MetaButtonsDisabled(),
            'link' => $plantilla->emulaBotonesEnAlgunCanal() && !$plantilla->isWhatsappLinkMetaButtonsDisabled(),
            default => false,
        };

        if ($botonera) {
            $io->warning('Este canal tiene la botonera encendida: al enviarse se añaden los botones debajo del cuerpo.');
        }

        return Command::SUCCESS;
    }
}
