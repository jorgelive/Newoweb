<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Service\TourTarjetaResolver;
use App\Dto\Lee;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pasa a «desde» AUTOMÁTICO los tours de catálogo cuyo override escrito a mano es igual al
 * calculado (07/10/2026).
 *
 * El «desde» pasó a ser el calculado por pasajero salvo override
 * (`TourTarjetaResolver::preciosDesdeEfectivos()`). Los cuatro tours de Cusco tenían escrito a mano
 * exactamente lo que el cálculo ya decía: dejarlos así es dejarlos listos para desincronizarse la
 * próxima vez que cambie una tarifa, que es el problema que esto viene a quitar.
 *
 * Sólo toca los IGUALES (misma moneda y mismos valores, como conjunto): un override distinto es una
 * decisión comercial y se respeta. Por ORM y sin traducir: vaciar un campo no necesita llamar a
 * Google, y el listener de traducción no tiene nada que hacer con una lista vacía.
 *
 *   php bin/console app:cotizacion:catalogo:precios-desde-automaticos --dry-run
 *   php bin/console app:cotizacion:catalogo:precios-desde-automaticos
 */
#[AsCommand(
    name: 'app:cotizacion:catalogo:precios-desde-automaticos',
    description: 'Vacía el «desde» manual de los tours donde coincide con el calculado. Idempotente.',
)]
final class CatalogoPreciosDesdeAutomaticosCommand extends Command
{
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

        // Sin ORDER BY: ordenar filas enteras de cotización (JSON de cientos de KB) revienta el
        // sort buffer de MySQL — «Out of sort memory», visto en producción el 08/10/2026. Se ordena
        // en PHP.
        /** @var list<Cotizacion> $tours */
        $tours = $this->em->createQuery(
            'SELECT c FROM App\Cotizacion\Entity\Cotizacion c WHERE c.catalogo IS NOT NULL'
        )->getResult();
        usort($tours, static fn (Cotizacion $a, Cotizacion $b): int => $a->getPropuesta() <=> $b->getPropuesta());

        $filas = [];
        $cambiados = 0;
        foreach ($tours as $tour) {
            if ($tour->getPreciosDesde() === []) {
                continue;
            }

            $manual = self::firma(array_map(
                static fn (array $r): array => ['moneda' => Lee::texto($r['moneda'] ?? null) ?? '', 'valor' => Lee::texto($r['valor'] ?? null) ?? ''],
                $tour->getPreciosDesde(),
            ));
            $calculado = self::firma(TourTarjetaResolver::preciosDesdeCalculados(
                $tour->getClasificacionFinancieraCliente(),
                $tour->getMonedaGlobal(),
            ));

            $igual = $calculado !== '' && $manual === $calculado;
            $filas[] = [
                Lee::texto(Lee::mapa($tour->getTitulo()[0] ?? [])['content'] ?? null) ?? ('T' . $tour->getPropuesta()),
                $manual,
                $calculado !== '' ? $calculado : '(sin cálculo)',
                $igual ? 'pasa a automático' : 'se respeta',
            ];

            if ($igual && !$simular) {
                $tour->setEjecutarTraduccion(false);
                $tour->setPreciosDesde([]);
                ++$cambiados;
            }
        }

        if ($filas === []) {
            $io->success('Ningún tour tiene «desde» escrito a mano.');
            return Command::SUCCESS;
        }

        $io->table(['tour', 'manual', 'calculado', 'acción'], $filas);

        if ($simular) {
            $io->note('--dry-run: no se escribió nada.');
            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d tour(s) pasaron a «desde» automático.', $cambiados));

        return Command::SUCCESS;
    }

    /**
     * Firma comparable de una lista de precios: moneda y valor entero, ordenados. «119.00» y «119»
     * son el mismo precio; el título no cuenta (el calculado no lo lleva con una sola clase).
     *
     * @param list<array{moneda: string, valor: string}>|list<array{titulo: array<mixed>, moneda: string, valor: string}> $precios
     */
    private static function firma(array $precios): string
    {
        $partes = [];
        foreach ($precios as $p) {
            if (!is_numeric($p['valor'])) {
                continue;
            }
            $partes[] = strtoupper($p['moneda']) . ' ' . (string) (int) ceil(round((float) $p['valor'], 2));
        }
        sort($partes);

        return implode(', ', $partes);
    }
}
