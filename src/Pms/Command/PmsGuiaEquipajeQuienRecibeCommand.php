<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Quién recibe las maletas, que la ficha no lo decía.
 *
 * ── Por qué hace falta justo ahora ──────────────────────────────────────────
 * Desde el 18/09/2026 el agente sabe —y dice— que NO hay recepción ni personal en el sitio. Eso
 * era lo que faltaba, pero deja a esta ficha coja: promete un almacén para el equipaje y no
 * cuenta quién lo recibe, así que a «¿y quién me guarda las maletas?» al modelo sólo le quedan
 * dos salidas malas, inventarse a alguien o negar un servicio que sí existe. Es la regla de la
 * casa: **lo que se le quita a un texto, el modelo lo niega**.
 *
 * ── Cómo funciona de verdad ─────────────────────────────────────────────────
 * No hay nadie esperando, pero el día de la salida el personal de limpieza llega a la hora del
 * check-out —o a la que se haya coordinado— y es quien recibe el equipaje; si entran huéspedes
 * nuevos antes de que lo recojan, o si va a quedarse varios días, lo lleva al almacén. Y no hay
 * que esperarles: a todo el mundo se le pide una **foto del equipaje por WhatsApp**, y con eso
 * queda registrado. Quien sale de madrugada lo deja dentro, manda la foto y ya está.
 *
 * ── Por qué comando y no SQL ────────────────────────────────────────────────
 * `descripcion` lleva `#[AutoTranslate]`: un `UPDATE` se salta el listener y los otros seis
 * idiomas se quedarían sin el párrafo. Se toca sólo el español y el listener rehace el resto.
 * `agenteContenido` no se traduce —lo lee el modelo, que ya traduce—, pero se cambia aquí mismo
 * para que las dos versiones no se separen.
 *
 * Nace `hidden` porque es de una vez. Idempotente: si el párrafo ya está, no toca nada.
 */
#[AsCommand(
    name: 'app:pms:guia:equipaje-quien-recibe',
    description: 'Añade a «Equipaje y horarios flexibles» quién recibe las maletas. Idempotente.',
    hidden: true,
)]
final class PmsGuiaEquipajeQuienRecibeCommand extends Command
{
    private const string FICHA = 'Equipaje y horarios flexibles (general)';

    /** El párrafo que hoy cierra la ficha: se sustituye entero. */
    private const string VIEJO = '<p>🧳 Cuando lo dejes, dinos <strong>cuántos bultos</strong> '
        . 'son. Puedes recogerlo antes de lo previsto sin problema.</p>';

    private const string NUEVO = '<p>🧳 Cuando lo dejes, dinos <strong>cuántos bultos</strong> '
        . 'son y mándanos una <strong>foto por WhatsApp</strong>: se la pedimos a todo el mundo y '
        . 'es el registro de lo que dejaste. Puedes recogerlo antes de lo previsto sin problema.'
        . "</p>\n"
        . '<p>🧹 El día de la salida <strong>no necesitas esperar a nadie</strong>. El personal de '
        . 'limpieza llega a la hora del check-out —o a la que hayas coordinado—, recibe el '
        . 'equipaje y lo lleva al almacén si hace falta. Si sales de madrugada, déjalo dentro y '
        . 'mándanos la foto: con eso basta.</p>';

    /** Lo que ya está en `agenteContenido` y detrás de lo cual entra el párrafo nuevo. */
    private const string ANCLA_AGENTE = 'día de antelación para coordinarlo, y que diga cuantos bultos son.';

    private const string AGENTE = "\n\n"
        . "QUIÉN LO RECIBE, que es la siguiente pregunta: no hay nadie esperando en el sitio, "
        . "pero el día de la salida el personal de limpieza llega a la hora del check-out —o a la "
        . "que se haya coordinado— y es quien recibe el equipaje; si entran huéspedes nuevos "
        . "antes de que lo recoja, o si lo deja varios días, lo lleva al almacén.\n\n"
        . "NO le digas que espere a nadie ni que alguien le abrirá: se le pide SIEMPRE una foto "
        . "del equipaje por WhatsApp y con eso queda registrado. Quien sale de madrugada lo deja "
        . "dentro, manda la foto y listo.";

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

        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => self::FICHA]);

        if (!$item instanceof PmsGuiaItem) {
            $io->error(sprintf('No existe la ficha «%s».', self::FICHA));

            return Command::FAILURE;
        }

        $tocado = false;

        // ── El texto del huésped ────────────────────────────────────────────
        $descripcion = $item->getDescripcion();
        $indice = null;

        foreach ($descripcion as $i => $fila) {
            if (($fila['language'] ?? null) === 'es') {
                $indice = $i;
                break;
            }
        }

        if ($indice === null) {
            $io->error('La ficha no tiene descripción en español.');

            return Command::FAILURE;
        }

        $texto = (string) ($descripcion[$indice]['content'] ?? '');

        if (str_contains($texto, 'no necesitas esperar a nadie')) {
            $io->text('· La guía ya lo cuenta.');
        } elseif (!str_contains($texto, self::VIEJO)) {
            // A ciegas no: si el párrafo de los bultos no está tal cual, alguien lo editó y
            // pegar aquí el nuevo dejaría la ficha diciendo dos veces lo mismo de otra manera.
            $io->error('No encuentro el párrafo de los bultos tal cual: míralo en el panel.');

            return Command::FAILURE;
        } else {
            $io->section('Guía (español)');
            $io->writeln('<fg=green>+ foto por WhatsApp · el personal de limpieza recibe y guarda · madrugada</>');

            if (!$simular) {
                $descripcion[$indice]['content'] = str_replace(self::VIEJO, self::NUEVO, $texto);
                $item->setDescripcion($descripcion);
            }

            $tocado = true;
        }

        // ── Lo que lee el agente ────────────────────────────────────────────
        $agente = (string) $item->getAgenteContenido();

        if (str_contains($agente, 'QUIÉN LO RECIBE')) {
            $io->text('· El texto del agente ya lo cuenta.');
        } elseif (!str_contains($agente, self::ANCLA_AGENTE)) {
            $io->error('El texto del agente no es el que esperaba: míralo en el panel.');

            return Command::FAILURE;
        } else {
            $io->section('Agente');
            $io->writeln('<fg=green>+ quién recibe · la foto sustituye a la espera</>');

            if (!$simular) {
                $item->setAgenteContenido(str_replace(
                    self::ANCLA_AGENTE,
                    self::ANCLA_AGENTE . self::AGENTE,
                    $agente
                ));
            }

            $tocado = true;
        }

        if (!$tocado) {
            $io->success('Ya estaba todo dicho.');

            return Command::SUCCESS;
        }

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Hecho. Los otros seis idiomas los rehace AutoTranslate al guardar.');

        return Command::SUCCESS;
    }
}
