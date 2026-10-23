<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Entity\Maestro\MaestroIdioma;
use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La entrada temprana y la salida tardía PUEDEN tener costo: lo decide el equipo en cada caso
 * (22/10/2026, Jorge). La ficha «Equipaje y horarios flexibles» decía que lo tiene, al huésped («tiene
 * un costo adicional») y al agente («SI PREGUNTA SI TIENE COSTO: sí»), y el agente lo soltaba sin
 * que se lo preguntaran.
 *
 * Sustituye esas dos frases y nada más: el resto de la ficha se escribió en el panel y se respeta.
 * El español del huésped se vuelve a traducir. Con `--dry-run`. Nace `hidden`.
 */
#[AsCommand(
    name: 'app:pms:guia:horario-flexible-costo',
    description: '«Equipaje y horarios flexibles»: el horario extra PUEDE tener costo, lo decide el equipo.',
    hidden: true,
)]
final class PmsGuiaHorarioFlexibleCostoCommand extends Command
{
    private const string CODIGO = 'equipaje-horarios-flexibles';

    /** @var array<string, string> lo que decía => lo que dice */
    private const array AL_HUESPED = [
        'Está sujeto a disponibilidad y tiene un costo adicional.' => 'Está sujeto a disponibilidad y puede tener un costo adicional: te lo confirmamos.',
    ];

    /** @var array<string, string> */
    private const array AL_AGENTE = [
        'SI PREGUNTA SI TIENE COSTO: sí, hay un costo adicional y el equipo le confirma el detalle.' => 'SI PREGUNTA SI TIENE COSTO: puede tenerlo; el equipo valora en cada caso si cobra y se lo confirma. No lo menciones si no lo pregunta.',
    ];

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

        $espanol = (string) MaestroIdioma::textoEn($item->getDescripcion() ?? [], 'es');
        $agente = (string) $item->getAgenteContenido();
        $nuevoEspanol = strtr($espanol, self::AL_HUESPED);
        $nuevoAgente = strtr($agente, self::AL_AGENTE);

        $io->text(sprintf('Al huésped: %s', $nuevoEspanol !== $espanol ? 'se cambia' : 'ya al día (o la frase no está)'));
        $io->text(sprintf('Al agente: %s', $nuevoAgente !== $agente ? 'se cambia' : 'ya al día (o la frase no está)'));

        if ($dryRun || ($nuevoEspanol === $espanol && $nuevoAgente === $agente)) {
            $io->note($dryRun ? 'Simulación: no se ha escrito nada.' : 'Nada que hacer.');

            return Command::SUCCESS;
        }

        if ($nuevoEspanol !== $espanol) {
            // Sólo el español: con el resto fuera, el listener vuelve a traducir los seis idiomas.
            $item->setDescripcion([['language' => 'es', 'content' => $nuevoEspanol]]);
        }

        $item->setAgenteContenido($nuevoAgente);
        $this->em->flush();
        $io->success('Ficha al día.');

        return Command::SUCCESS;
    }
}
