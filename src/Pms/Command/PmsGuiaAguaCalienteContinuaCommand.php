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
 * Las siete duchas dicen ahora que el agua caliente NO SE ACABA (17/10/2026).
 *
 * Explicaban cómo regularla —abrirla del todo, «cuanta más agua pasa, menos caliente sale»— pero
 * no lo que pregunta el huésped que viene en grupo: «¿cuentan con agua caliente suficiente para 5
 * personas?» (18/08/2026). Las siete casitas tienen calentador de paso a gas (confirmado por
 * Jorge): sin depósito, no se vacía por bañarse varios seguidos.
 *
 * Va en el peldaño 0 —lo que el asistente contesta de entrada— porque es una buena noticia. Que el
 * balón de gas se puede vaciar ya está en el segundo paso de las casitas que lo comparten con la
 * cocina (2, 3, 4 y 7); las otras tres tienen gas independiente y no lo llevan a propósito.
 *
 * ── Se AÑADE sobre el texto actual, no se reescribe ─────────────────────────
 * `app:pms:guia:corregir-duchas` reescribía los textos enteros, y la base ya no coincide con él:
 * después pasó `PmsGuiaTildesCommand`. Reescribir aquí habría deshecho las tildes; por eso se lee
 * lo que hay y se le añade el párrafo. Idempotente: si el texto ya lo dice, no se toca.
 *
 * Por comando y no por SQL: `descripcion` está publicada en siete idiomas y el traductor rehace las
 * otras seis al guardar —su huella (`origenHash`) deja de cuadrar con el español nuevo—.
 */
#[AsCommand(
    name: 'app:pms:guia:agua-caliente-continua',
    description: 'Añade a las siete duchas que el agua caliente no se acaba (calentador de paso).',
    hidden: true,
)]
final class PmsGuiaAguaCalienteContinuaCommand extends Command
{
    /** Lo que identifica que el texto ya lo dice. */
    private const string MARCA = 'no se acaba';

    private const string AGENTE = 'Los calentadores son a gas e instantáneos (de paso): calientan el agua '
        . 'mientras pasa y no tienen depósito, así que el agua caliente no se acaba aunque se bañen '
        . 'varias personas seguidas. Si preguntan si alcanza para todos, la respuesta es sí.';

    private const string PUBLICADO = '<p>♨️ Los calentadores son a gas e instantáneos: no tienen depósito, así '
        . 'que el agua caliente <strong>no se acaba</strong> aunque se bañen varias personas seguidas.</p>';

    /** Como lo pregunta la gente, no como lo llamamos nosotros (CLAUDE.md, «agenteTerminos»). */
    private const array TERMINOS = [
        'alcanza el agua caliente', 'suficiente agua caliente', 'cuantas personas', 'varias personas',
        'se acaba el agua caliente', 'enough hot water', 'run out of hot water',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $tocados = 0;

        for ($casa = 1; $casa <= 7; ++$casa) {
            $nombre = sprintf('Ducha (casa %d)', $casa);
            $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => $nombre]);

            if (!$item instanceof PmsGuiaItem) {
                $io->warning(sprintf('No encuentro «%s».', $nombre));
                continue;
            }

            $cambios = [];

            $agente = (string) $item->getAgenteContenido();
            if (!str_contains(mb_strtolower($agente), self::MARCA)) {
                $item->setAgenteContenido(rtrim($agente) . "\n\n" . self::AGENTE);
                $cambios[] = 'asistente';
            }

            $filas = $item->getDescripcion() ?? [];
            $tocaPublicado = false;
            foreach ($filas as $i => $fila) {
                $contenido = (string) ($fila['content'] ?? '');

                if (($fila['language'] ?? null) === 'es' && !str_contains(mb_strtolower($contenido), self::MARCA)) {
                    // Sólo la fila en español: las otras seis llevan la huella del texto viejo y el
                    // traductor las rehace al guardar.
                    $filas[$i]['content'] = $contenido . self::PUBLICADO;
                    $tocaPublicado = true;
                }
            }
            if ($tocaPublicado) {
                $item->setDescripcion($filas);
                $cambios[] = 'app (7 idiomas)';
            }

            $terminos = $item->getAgenteTerminosLista();
            $faltan = array_values(array_diff(self::TERMINOS, $terminos));
            if ($faltan !== []) {
                $item->setAgenteTerminos(implode(', ', [...$terminos, ...$faltan]));
                $cambios[] = sprintf('%d términos', count($faltan));
            }

            if ($cambios === []) {
                $io->text(sprintf('· %s — ya lo dice, sin tocar', $nombre));
                continue;
            }

            ++$tocados;
            $io->text(sprintf('+ %s — %s', $nombre, implode(', ', $cambios)));
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf('%d ducha(s) %s.', $tocados, $seco ? 'se cambiarían' : 'cambiadas'));

        return Command::SUCCESS;
    }
}
