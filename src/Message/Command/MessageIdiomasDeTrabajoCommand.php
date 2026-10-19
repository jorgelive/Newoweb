<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Entity\Maestro\MaestroIdioma;
use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsReserva;
use App\Service\Idioma\IdiomaDeTrabajo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pasa a inglés las conversaciones y reservas que quedaron en un idioma que no trabajamos
 * (prioridad 0: hebreo, coreano, checo…). Ver {@see IdiomaDeTrabajo}.
 *
 * Lo nuevo ya nace bien —la regla está en origen y en `IdiomaDeTrabajoListener`—; esto es para lo
 * que se guardó antes del 19/10/2026. Por el ORM y no por SQL: el idioma de la reserva viaja a
 * Beds24 (`lang`) y el del hilo decide en qué sale lo programado, y los dos se recalculan al
 * guardarse.
 *
 * Idempotente, con `--dry-run`.
 */
#[AsCommand(
    name: 'app:message:idiomas-de-trabajo',
    description: 'Pasa a inglés las conversaciones y reservas guardadas en un idioma que no trabajamos.',
)]
final class MessageIdiomasDeTrabajoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IdiomaDeTrabajo $idiomas,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Sólo dice qué haría.')]
        bool $dryRun = false,
    ): int {
        $cambios = 0;

        foreach ([MessageConversation::class, PmsReserva::class] as $clase) {
            /** @var list<MessageConversation|PmsReserva> $filas */
            $filas = $this->em->createQueryBuilder()
                ->select('x')
                ->from($clase, 'x')
                ->join('x.idioma', 'i')
                ->where('i.prioridad <= 0')
                ->getQuery()
                ->getResult();

            foreach ($filas as $fila) {
                $idioma = $fila->getIdioma();

                if (!$idioma instanceof MaestroIdioma) {
                    continue;
                }

                $io->text(sprintf(
                    '+ %s %s: %s → %s',
                    $fila instanceof PmsReserva ? 'reserva' : 'conversación',
                    $fila instanceof PmsReserva ? (string) $fila->getLocalizador() : (string) $fila->getId(),
                    (string) $idioma->getId(),
                    MaestroIdioma::ID_INGLES,
                ));
                ++$cambios;

                if (!$dryRun) {
                    $fila->setIdioma($this->idiomas->para($idioma));
                }
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('%d fila(s) %s.', $cambios, $dryRun ? 'se cambiarían' : 'cambiadas'));

        return Command::SUCCESS;
    }
}
