<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsReserva;
use App\Pms\Nombre\OrdenDelNombre;
use App\Pms\Nombre\RevisorDeOrdenDeNombre;
use App\Pms\Dispatch\RevisarOrdenDelNombreDispatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('localizador', InputArgument::OPTIONAL, 'Una reserva concreta')
            ->addOption('par', null, InputOption::VALUE_REQUIRED, 'Un par suelto: "NOMBRE|APELLIDO"')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Cuántas reservas recientes revisar', '10')
            ->addOption('aplicar', null, InputOption::VALUE_NONE, 'Guarda el intercambio cuando proceda')
            // ⚠️ Esto NO es lo mismo que lo de arriba y ésa es la gracia. Sin `--encolar` el
            // comando llama al revisor DIRECTAMENTE: comprueba el modelo y el prompt, y nada
            // más. Con `--encolar` manda el mismo mensaje que manda el listener, así que lo que
            // se prueba es la cadena entera —bus, worker, handler, log— que es justo el tramo
            // del que no había ninguna evidencia. Lo que pase se ve en `info.log`.
            ->addOption('encolar', null, InputOption::VALUE_NONE, 'Despacha por el bus, como haría una reserva nueva');
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

        if ((bool) $input->getOption('encolar')) {
            return $this->encolar($io, $reservas);
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

    /**
     * Manda por el bus el MISMO mensaje que manda el listener.
     *
     * Es la única forma de probar el tramo del que no había evidencia: que el worker recoge, que
     * el handler corre y que ahora deja línea. Lo de arriba prueba el modelo; esto prueba la
     * cañería.
     *
     * ⚠️ **Esto sí puede escribir**, porque lo atiende el handler de verdad. No lleva
     * `--aplicar`: el handler aplica cuando `OrdenDelNombre::resultado()` lo permite, igual que
     * con una reserva recién llegada. Fingir lo contrario sería probar otra cosa.
     *
     * @param list<PmsReserva> $reservas
     */
    private function encolar(SymfonyStyle $io, array $reservas): int
    {
        $mandados = 0;

        foreach ($reservas as $reserva) {
            $nombre = trim((string) $reserva->getNombreCliente());
            $apellido = trim((string) $reserva->getApellidoCliente());
            $id = $reserva->getId();

            if ($id === null || !OrdenDelNombre::mereceRevision($nombre, $apellido)) {
                continue;
            }

            $this->bus->dispatch(new RevisarOrdenDelNombreDispatch((string) $id, $nombre, $apellido));
            ++$mandados;
        }

        $io->success(sprintf(
            '%d mensaje(s) al bus. Míralos en var/log/info.log: `grep OrdenNombre`.',
            $mandados
        ));

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
