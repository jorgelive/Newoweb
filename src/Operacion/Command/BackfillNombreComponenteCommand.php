<?php

declare(strict_types=1);

namespace App\Operacion\Command;

use Doctrine\DBAL\Connection;
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
 * enseñando el respaldo —el nombre del itinerario en el cuadro, la variante de tarifa en la
 * orden—. Se puede repetir sin miedo: sólo toca lo que está a null.
 *
 * ⚠️ Va por SQL y no por el ORM **a propósito**: es texto denormalizado que ninguna entidad
 * recalcula al guardarse y ningún listener vigila. Pasarlo por el ORM haría un flush de miles de
 * entidades para escribir una columna de texto. La regla de `CLAUDE.md` es la que decide: sin
 * listeners de por medio, va por SQL.
 *
 * Las líneas congeladas se rellenan desde su `OperacionServicio` de origen, que es de donde
 * salieron el día que se emitieron. Las que ya no lo tienen —órdenes anuladas que soltaron sus
 * filas— se quedan como están: inventarles un nombre hoy sería reescribir lo que decía el
 * documento, y eso es justo lo que congelar existe para impedir.
 */
#[AsCommand(
    name: 'app:operacion:backfill-nombre-componente',
    description: 'Rellena el nombre del componente en el cuadro y en las órdenes ya emitidas.',
)]
final class BackfillNombreComponenteCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        // El nombre público en español vive en un array i18n [{language, content}, ...]. Se
        // resuelve en PHP y no en SQL: `JSON_TABLE` para buscar el elemento con language='es'
        // convierte esto en una consulta que nadie va a saber leer dentro de seis meses.
        $filas = $this->db->fetchAllAssociative(
            'SELECT HEX(s.id) AS id, k.nombre_snapshot AS publico, k.nombre_interno_snapshot AS interno
               FROM operacion_servicio s
               JOIN cotizacion_cotcomponente k ON s.cotizacion_componente_id = k.id
              WHERE s.nombre_componente IS NULL'
        );

        $io->section(sprintf('Filas de La Biblia sin nombre: %d', \count($filas)));

        $puestos   = 0;
        $sinNombre = 0;

        foreach ($filas as $fila) {
            $nombre = $this->nombreEnEspanol($fila['publico']) ?? (trim((string) $fila['interno']) ?: null);

            if ($nombre === null) {
                ++$sinNombre;
                continue;
            }

            if (!$simula) {
                $this->db->executeStatement(
                    'UPDATE operacion_servicio SET nombre_componente = ? WHERE id = UNHEX(?)',
                    [$nombre, $fila['id']]
                );
            }

            ++$puestos;
        }

        $io->text(sprintf('  puestos: %d · sin nombre resoluble: %d', $puestos, $sinNombre));

        // Las líneas congeladas, desde su fila de origen. `operacion_servicio_id` es texto (un
        // uuid en RFC 4122), no una relación: la línea sobrevive a que borren la fila.
        $sqlItems = 'UPDATE operacion_orden_servicio_item i
                       JOIN operacion_servicio s ON s.id = UNHEX(REPLACE(i.operacion_servicio_id, "-", ""))
                        SET i.nombre_componente = COALESCE(i.nombre_componente, s.nombre_componente),
                            i.contexto_servicio = COALESCE(i.contexto_servicio, s.contexto_servicio)
                      WHERE i.nombre_componente IS NULL OR i.contexto_servicio IS NULL';

        $itemsPendientes = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM operacion_orden_servicio_item WHERE nombre_componente IS NULL'
        );

        $io->section(sprintf('Líneas congeladas sin nombre: %d', $itemsPendientes));

        $tocadas = $simula ? 0 : (int) $this->db->executeStatement($sqlItems);
        $io->text(sprintf('  %s: %d', $simula ? 'se tocarían' : 'tocadas', $tocadas));

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

    /** @return string|null El contenido en español de un snapshot i18n, sin etiquetas. */
    private function nombreEnEspanol(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return null;
        }

        $lista = json_decode($json, true);

        if (!\is_array($lista)) {
            return null;
        }

        foreach ($lista as $item) {
            if (!\is_array($item) || ($item['language'] ?? '') !== 'es') {
                continue;
            }

            $texto = trim(strip_tags((string) ($item['content'] ?? '')));

            if ($texto !== '') {
                return $texto;
            }
        }

        return null;
    }
}
