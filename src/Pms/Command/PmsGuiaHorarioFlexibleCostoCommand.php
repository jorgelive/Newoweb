<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * El costo del horario extra se dice SIEMPRE, no sólo si lo preguntan (22/10/2026, Jorge).
 *
 * La ficha «Equipaje y horarios flexibles» le decía al agente «SI PREGUNTA SI TIENE COSTO: sí…», y un
 * modelo que lee al pie de la letra (DeepSeek, ensayado) sólo lo mencionaba si se lo preguntaban.
 * Cambia esa frase del `agenteContenido` y nada más: el resto se escribió en el panel. Lo que lee el
 * huésped ya decía «tiene un costo adicional». Con `--dry-run`. Nace `hidden`.
 */
#[AsCommand(
    name: 'app:pms:guia:horario-flexible-costo',
    description: '«Equipaje y horarios flexibles»: el agente dice siempre que el horario extra tiene costo.',
    hidden: true,
)]
final class PmsGuiaHorarioFlexibleCostoCommand extends Command
{
    private const string CODIGO = 'equipaje-horarios-flexibles';

    private const string ANTES = 'SI PREGUNTA SI TIENE COSTO: sí, hay un costo adicional y el equipo le confirma el detalle.';

    private const string AHORA = 'Dile SIEMPRE, lo pregunte o no, que adelantar la entrada o atrasar la salida tiene un costo '
        . 'adicional y depende de la disponibilidad, y que se lo pasas al equipo, que le confirma.';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Sólo dice qué haría.')] bool $dryRun = false,
    ): int {
        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['codigo' => self::CODIGO]);

        if (!$item instanceof PmsGuiaItem) {
            $io->error(sprintf('No existe la ficha «%s».', self::CODIGO));

            return Command::FAILURE;
        }

        $agente = (string) $item->getAgenteContenido();

        if (!str_contains($agente, self::ANTES)) {
            $io->note(str_contains($agente, self::AHORA) ? 'Ya al día.' : 'La frase no está: revísala en el panel.');

            return Command::SUCCESS;
        }

        $io->text('Al agente: se cambia la frase del costo.');

        if ($dryRun) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        // `agenteContenido` no se traduce: es instrucción para el agente.
        $item->setAgenteContenido(str_replace(self::ANTES, self::AHORA, $agente));
        $this->em->flush();
        $io->success('Ficha al día.');

        return Command::SUCCESS;
    }
}
