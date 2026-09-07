<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Corrige la hora de FIN de un componente con hora, dejándola en `inicio + N horas`.
 *
 * ── De dónde salen estos casos ──────────────────────────────────────────────
 * Aparecieron al llevar la fecha de fin al documento del proveedor: componentes con hora cuya
 * fecha de fin caía **al día siguiente** sin que el servicio durase dos días.
 *
 * ```
 * Transporte desde el Aeropuerto de Lima   31/08 22:00 → 01/09 23:30   25,5 h
 * Boleto de ingreso a Machu Picchu         08/09 07:00 → 09/09 07:00   24,0 h
 * ```
 *
 * Un traslado de aeropuerto no dura veinticinco horas y un boleto de ingreso no dura un día. Son
 * duraciones mal puestas que nadie veía porque **la hora de fin no se pintaba en ningún sitio**:
 * el itinerario enseña la de inicio y el rango sólo aparece cuando difieren.
 *
 * ── Por qué el valor NO se inventa ──────────────────────────────────────────
 * ⚠️ La duración correcta la dice la propia base: el mismo producto, en otras cotizaciones, ya
 * tiene una. «Boleto de ingreso a Machu Picchu» sale 4 veces con 0 h y 1 con 24; «Transporte desde
 * el Aeropuerto de Lima», 2 veces con 2 h y 3 con 25,5. La mayoría es el dato bueno y la minoría
 * el error — pero **quien decide es quien ejecuta**, no este comando: las horas se pasan por
 * argumento y el ensayo las enseña fila a fila antes de tocar nada.
 *
 * ── Lo que NO toca ──────────────────────────────────────────────────────────
 * - Los componentes **sin hora**: ahí la fecha de fin es el fin de un periodo (un hotel, un
 *   seguro) y cambiarla cambiaría noches o días — o sea, dinero. Eso lo hace
 *   `app:travel:asignar-unidades-de-conteo`, que además lo cuenta.
 * - Las propuestas en **histórico**, salvo `--incluir-historico`: son la foto de lo que se dijo
 *   entonces y reescribirlas es reescribir el pasado.
 * - Ni cantidades, ni importes, ni fechas de inicio.
 */
#[AsCommand(
    name: 'app:cotizacion:corregir-duracion',
    description: 'Deja la hora de fin de un componente con hora en inicio + N horas.',
)]
final class CorregirDuracionComponenteCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('titulo', null, InputOption::VALUE_REQUIRED, 'Título público del componente (búsqueda por prefijo).')
            ->addOption('horas', null, InputOption::VALUE_REQUIRED, 'Duración correcta, en horas decimales. 0 = acaba cuando empieza.')
            ->addOption('incluir-historico', null, InputOption::VALUE_NONE, 'Toca también las propuestas en histórico.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No guarda nada: enseña lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $conHistorico = (bool) $input->getOption('incluir-historico');

        $titulo = trim((string) ($input->getOption('titulo') ?? ''));
        $horasTexto = (string) ($input->getOption('horas') ?? '');

        if ($titulo === '' || $horasTexto === '' || !is_numeric($horasTexto)) {
            $io->error('Hacen falta --titulo y --horas (número decimal).');

            return Command::INVALID;
        }

        $minutos = (int) round(((float) $horasTexto) * 60);

        /** @var list<CotizacionCotcomponente> $componentes */
        $componentes = $this->em->createQuery(
            'SELECT c FROM App\Cotizacion\Entity\CotizacionCotcomponente c WHERE c.sinHorario = false'
        )->getResult();

        $filas = [];
        $tocados = 0;

        foreach ($componentes as $c) {
            $publico = $this->tituloEspanol($c);

            if ($publico === null || !str_starts_with($publico, $titulo)) {
                continue;
            }

            $inicio = $c->getFechaHoraInicio();
            $fin = $c->getFechaHoraFin();

            if ($inicio === null || $fin === null) {
                continue;
            }

            $esperado = DateTimeImmutable::createFromInterface($inicio)->modify(sprintf('+%d minutes', $minutos));

            if ($esperado->format('Y-m-d H:i') === $fin->format('Y-m-d H:i')) {
                continue;   // ya está bien
            }

            $cotizacion = $c->getCotservicio()?->getCotizacion();
            $estado = (string) ($cotizacion?->getEstado()->value ?? '');
            $esHistorico = $estado === 'historico';
            $enAlcance = !$esHistorico || $conHistorico;

            $filas[] = [
                $cotizacion?->getFile()?->getLocalizador() ?? '—',
                $estado === '' ? '—' : $estado,
                mb_substr($publico, 0, 34),
                $inicio->format('d/m H:i'),
                $fin->format('d/m H:i'),
                $esperado->format('d/m H:i'),
                $enAlcance ? '🔧' : '— histórico',
            ];

            if ($enAlcance) {
                $c->setFechaHoraFin($esperado);
                ++$tocados;
            }
        }

        if ($filas === []) {
            $io->success(sprintf('Nada que corregir en «%s».', $titulo));

            return Command::SUCCESS;
        }

        $io->table(['Expediente', 'Estado', 'Componente', 'Inicio', 'Fin actual', 'Fin correcto', ''], $filas);

        if ($seco) {
            $io->warning('Ensayo: no se guardó nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d componente(s) corregido(s).', $tocados));

        return Command::SUCCESS;
    }

    /** El título público en español, que es por el que busca quien ejecuta esto. */
    private function tituloEspanol(CotizacionCotcomponente $c): ?string
    {
        foreach ($c->getTituloSnapshot() as $item) {
            if (($item['language'] ?? '') === 'es' && ($item['content'] ?? '') !== '') {
                return (string) $item['content'];
            }
        }

        return null;
    }
}
