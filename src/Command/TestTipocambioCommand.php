<?php

namespace App\Command;

use App\Service\TipocambioManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


/**
 * Prueba el Servicio de tipo de cambio tipo de cambio para una fecha específica o sin fecha (hoy).
 *
 * @example Probando el servicio vía Consola (CLI):
 * php bin/console app:test-tipocambio 2026-01-18
 *
 * @example Probando con fecha de hoy:
 * php bin/console app:test-tipocambio
 */
#[AsCommand(
    name: 'app:test-tipocambio',
    description: 'Prueba el servicio TipocambioManager para una fecha dada.',
)]
class TestTipocambioCommand extends Command
{
    public function __construct(
        private TipocambioManager $manager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fecha', InputArgument::OPTIONAL, 'Fecha a consultar (YYYY-MM-DD)', 'now')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fechaInput = $input->getArgument('fecha');

        // Convertir input a DateTime
        try {
            $fecha = new \DateTime($fechaInput);
        } catch (\Exception $e) {
            $io->error(sprintf('Formato de fecha inválido: "%s". Usa YYYY-MM-DD.', $fechaInput));
            return Command::FAILURE;
        }

        $io->title(sprintf('Consultando Tipo de Cambio para: %s', $fecha->format('Y-m-d')));

        // 🔥 LLAMADA AL SERVICIO
        $start = microtime(true);
        $tc = $this->manager->getTipodecambio($fecha);
        $duration = number_format((microtime(true) - $start) * 1000, 2);

        if (!$tc) {
            $io->error('No se encontró tipo de cambio (y fallaron los fallbacks).');
            return Command::FAILURE;
        }

        // Mostrar resultados
        $io->success('¡Dato encontrado!');

        $io->table(
            ['Propiedad', 'Valor'],
            [
                ['ID (BD)', $tc->getId() ?? 'N/A'],
                ['Fecha Solicitada', $fecha->format('Y-m-d')],
                ['Fecha del Dato', $tc->getFecha()->format('Y-m-d')], // Para ver si hizo fallback
                ['Moneda', $tc->getMoneda()->getId() ?? 'USD'], // Asumiendo que tu entidad tiene getCodigo
                ['Compra', $tc->getCompra()],
                ['Venta', $tc->getVenta()],
                ['Tiempo de respuesta', $duration . ' ms'],
            ]
        );

        if ($fecha->format('Y-m-d') !== $tc->getFecha()->format('Y-m-d')) {
            $io->note('Nota: La fecha del dato es diferente a la solicitada. Esto indica que se usó un día hábil anterior (Fallback).');
        }

        return Command::SUCCESS;
    }
}