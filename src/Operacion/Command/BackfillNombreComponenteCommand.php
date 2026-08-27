<?php

declare(strict_types=1);

namespace App\Operacion\Command;

use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Service\BibliaSnapshotService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rellena `nombreComponente` en las filas de La Biblia y en las líneas ya congeladas.
 *
 * El campo nació con `Version20260827105202`; todo lo anterior lo tiene a null y seguiría
 * enseñando el respaldo —el genérico del tipo en el cuadro, la variante de tarifa en la orden—.
 *
 * ⚠️ **El valor lo calcula `BibliaSnapshotService::resolverNombreComponente()`, no este comando.**
 * La primera versión repetía la resolución en SQL y se equivocó de nombre: cogía el público
 * («Transporte») en vez del operativo («Transporte Aeropuerto Cusco - Hotel Cusco»). Una regla
 * escrita dos veces se arregla una vez y se queda mal en la otra; por eso ahora sólo hay un sitio
 * donde decida.
 *
 * La ESCRITURA sí va por SQL: es texto denormalizado que ningún listener vigila, y pasarlo por el
 * ORM haría un flush de entidades que además disparan `verificarCoherenciaConOrdenServicio()` —
 * una guarda que puede negarse legítimamente y abortaría un backfill que no cambia nada suyo.
 *
 * `--rehacer` recalcula también las que ya tienen valor. Hace falta cuando cambia la regla, que es
 * exactamente lo que pasó el 27/08/2026 con el público vs. el operativo.
 */
#[AsCommand(
    name: 'app:operacion:backfill-nombre-componente',
    description: 'Rellena el nombre operativo del componente en el cuadro y en las órdenes.',
)]
final class BackfillNombreComponenteCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly BibliaSnapshotService $snapshot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.')
            ->addOption('rehacer', null, InputOption::VALUE_NONE, 'Recalcula también las que ya tienen nombre.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $simula  = (bool) $input->getOption('dry-run');
        $rehacer = (bool) $input->getOption('rehacer');

        $repo  = $this->em->getRepository(OperacionServicio::class);
        $filas = $rehacer ? $repo->findAll() : $repo->findBy(['nombreComponente' => null]);

        $io->section(sprintf('Filas de La Biblia a revisar: %d', \count($filas)));

        $puestos   = 0;
        $iguales   = 0;
        $sinNombre = 0;
        $muestra   = [];

        foreach ($filas as $fila) {
            $componente = $fila->getCotizacionComponente();

            if ($componente === null) {
                ++$sinNombre;
                continue;
            }

            $nombre = $this->snapshot->resolverNombreComponente($componente);

            if ($nombre === null || $nombre === '') {
                ++$sinNombre;
                continue;
            }

            if ($nombre === $fila->getNombreComponente()) {
                ++$iguales;
                continue;
            }

            if (\count($muestra) < 6) {
                $muestra[] = sprintf('%s  →  %s', $fila->getNombreComponente() ?? '(vacío)', $nombre);
            }

            if (!$simula) {
                $this->db->executeStatement(
                    'UPDATE operacion_servicio SET nombre_componente = ? WHERE id = ?',
                    [$nombre, $fila->getId()?->toBinary()]
                );
            }

            ++$puestos;
        }

        $io->text(sprintf('  cambiadas: %d · ya correctas: %d · sin nombre resoluble: %d', $puestos, $iguales, $sinNombre));

        foreach ($muestra as $linea) {
            $io->text('    ' . $linea);
        }

        // Las líneas congeladas, desde su fila de origen. `operacion_servicio_id` es texto (un
        // uuid en RFC 4122), no una relación: la línea sobrevive a que borren la fila.
        //
        // Con `--rehacer` se pisa el valor; sin él sólo se rellena lo que está a null. Reescribir
        // un documento emitido no es gratis, así que exige pedirlo.
        $sqlItems = $rehacer
            ? 'UPDATE operacion_orden_servicio_item i
                 JOIN operacion_servicio s ON s.id = UNHEX(REPLACE(i.operacion_servicio_id, "-", ""))
                  SET i.nombre_componente = s.nombre_componente,
                      i.contexto_servicio = s.contexto_servicio
                WHERE s.nombre_componente IS NOT NULL'
            : 'UPDATE operacion_orden_servicio_item i
                 JOIN operacion_servicio s ON s.id = UNHEX(REPLACE(i.operacion_servicio_id, "-", ""))
                  SET i.nombre_componente = COALESCE(i.nombre_componente, s.nombre_componente),
                      i.contexto_servicio = COALESCE(i.contexto_servicio, s.contexto_servicio)
                WHERE i.nombre_componente IS NULL OR i.contexto_servicio IS NULL';

        $tocadas = $simula ? 0 : (int) $this->db->executeStatement($sqlItems);
        $io->section(sprintf('Líneas congeladas %s: %d', $simula ? 'por tocar' : 'tocadas', $tocadas));

        if ($simula) {
            $io->warning('SIMULACRO: no se escribió nada.');

            return Command::SUCCESS;
        }

        $huerfanas = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM operacion_orden_servicio_item WHERE nombre_componente IS NULL'
        );

        if ($huerfanas > 0) {
            $io->note(sprintf(
                '%d líneas se quedan sin nombre: su fila de origen ya no existe. Se dejan como '
                . 'estaban — reescribir un documento emitido es lo que congelar impide.',
                $huerfanas
            ));
        }

        $io->success('Backfill terminado.');

        return Command::SUCCESS;
    }
}
