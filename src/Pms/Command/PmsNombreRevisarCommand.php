<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsReserva;
use App\Pms\Nombre\OrdenDelNombre;
use App\Pms\Nombre\RevisorDeOrdenDeNombre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ver qué contesta el revisor de nombres, sin esperar a que entre una reserva.
 *
 * ── Por qué hacía falta ─────────────────────────────────────────────────────
 * El mecanismo lleva desde el 19/08/2026 funcionando **sin dejar rastro**: corre en un worker,
 * sobre reservas que entran por webhook, y su único desenlace registrado era un `info` que
 * producción oculta. El caso más común —«no estaba cruzado»— ni siquiera se registraba. Así que a
 * la pregunta «¿esto está haciendo algo?» no había nada que mirar, ni un log ni una pantalla.
 *
 * ⚠️ **Pregunta por el MISMO servicio que el worker** ({@see RevisorDeOrdenDeNombre}), con su
 * mismo prompt y su mismo esquema. Un simulador que armara su propia consulta no simularía nada:
 * comprobaría otro sistema parecido, y el día que difirieran no se notaría aquí.
 *
 * ── Cómo se usa ─────────────────────────────────────────────────────────────
 *   pms:nombre:revisar ABC123          # una reserva concreta
 *   pms:nombre:revisar --par "RODRIGUEZ BARRERA|ALISSON ANGELICA"
 *   pms:nombre:revisar --limite 10     # las últimas que entraron
 *   pms:nombre:revisar ABC123 --aplicar
 *
 * **Simula por defecto**: sin `--aplicar` no escribe nada. La decisión de aplicar la sigue
 * tomando {@see OrdenDelNombre::resultado()}, no este comando — exige confianza «alta» y que las
 * cadenas no hayan cambiado desde que se preguntó.
 */
#[AsCommand(
    name: 'pms:nombre:revisar',
    description: 'Qué dice el modelo sobre el orden de nombre y apellido. Simula por defecto.',
)]
final class PmsNombreRevisarCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RevisorDeOrdenDeNombre $revisor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('localizador', InputArgument::OPTIONAL, 'Una reserva concreta')
            ->addOption('par', null, InputOption::VALUE_REQUIRED, 'Un par suelto: "NOMBRE|APELLIDO"')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Cuántas reservas recientes revisar', '10')
            ->addOption('aplicar', null, InputOption::VALUE_NONE, 'Guarda el intercambio cuando proceda');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $aplicar = (bool) $input->getOption('aplicar');

        $io->writeln(sprintf(
            '<comment>Motor:</comment> %s   <comment>Modo:</comment> %s',
            $this->revisor->modeloElegido() ?? '<error>ninguno disponible</error>',
            $aplicar ? '<info>APLICANDO</info>' : 'simulación (no escribe)',
        ));

        $par = $input->getOption('par');

        if (is_string($par) && $par !== '') {
            return $this->unPar($io, $par);
        }

        $localizador = $input->getArgument('localizador');
        $reservas = is_string($localizador) && $localizador !== ''
            ? array_filter([$this->em->getRepository(PmsReserva::class)->findOneBy(['localizador' => $localizador])])
            : $this->em->getRepository(PmsReserva::class)->findBy([], ['createdAt' => 'DESC'], max(1, (int) $input->getOption('limite')));

        if ($reservas === []) {
            $io->error('No hay reservas que revisar.');

            return Command::FAILURE;
        }

        $filas = [];

        foreach ($reservas as $reserva) {
            $filas[] = $this->unaReserva($reserva, $aplicar);
        }

        if ($aplicar) {
            $this->em->flush();
        }

        $io->table(['Reserva', 'Nombre', 'Apellido', 'Veredicto', 'Confianza', 'Qué pasa', 'Motivo'], $filas);

        return Command::SUCCESS;
    }

    /** Un par escrito a mano: para probar el prompt sin tocar ninguna reserva. */
    private function unPar(SymfonyStyle $io, string $par): int
    {
        [$nombre, $apellido] = array_pad(explode('|', $par, 2), 2, '');
        $nombre = trim($nombre);
        $apellido = trim($apellido);

        if (!OrdenDelNombre::mereceRevision($nombre, $apellido)) {
            $io->warning('Ese par no se revisaría: falta una parte, es demasiado corta o es un relleno del pull.');

            return Command::SUCCESS;
        }

        $veredicto = $this->revisor->veredicto($nombre, $apellido);

        if ($veredicto === null) {
            $io->error('El motor no contestó. El motivo está en el log como warning.');

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Se le enseñó' => sprintf('«%s» / «%s»', $nombre, $apellido)],
            ['¿Invertido?' => $veredicto['invertido'] ? 'SÍ' : 'no'],
            ['Confianza' => $veredicto['confianza']],
            ['Quedaría' => $veredicto['invertido'] && $veredicto['confianza'] === OrdenDelNombre::CONFIANZA_EXIGIDA
                ? sprintf('«%s» / «%s»', $apellido, $nombre)
                : 'igual que vino'],
            ['Motivo' => $veredicto['motivo']],
        );

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function unaReserva(PmsReserva $reserva, bool $aplicar): array
    {
        $nombre = trim((string) $reserva->getNombreCliente());
        $apellido = trim((string) $reserva->getApellidoCliente());
        $loc = (string) $reserva->getLocalizador();

        if (!OrdenDelNombre::mereceRevision($nombre, $apellido)) {
            return [$loc, $nombre, $apellido, '—', '—', 'no se revisa', 'falta una parte, es corta o es relleno del pull'];
        }

        $veredicto = $this->revisor->veredicto($nombre, $apellido);

        if ($veredicto === null) {
            return [$loc, $nombre, $apellido, '<error>sin respuesta</error>', '—', 'se queda', 'ver el warning en el log'];
        }

        $par = OrdenDelNombre::resultado(
            invertido: $veredicto['invertido'],
            confianza: $veredicto['confianza'],
            nombreJuzgado: $nombre,
            apellidoJuzgado: $apellido,
            nombreActual: $reserva->getNombreCliente(),
            apellidoActual: $reserva->getApellidoCliente(),
        );

        if ($par === null) {
            return [$loc, $nombre, $apellido, $veredicto['invertido'] ? 'invertido' : 'correcto',
                $veredicto['confianza'], 'se queda', $veredicto['motivo']];
        }

        if ($aplicar) {
            $reserva->setNombreCliente($par[0])->setApellidoCliente($par[1]);
        }

        return [$loc, $nombre, $apellido, '<info>invertido</info>', $veredicto['confianza'],
            sprintf('%s → «%s» / «%s»', $aplicar ? '<info>APLICADO</info>' : 'se cambiaría', $par[0], $par[1]),
            $veredicto['motivo']];
    }
}
