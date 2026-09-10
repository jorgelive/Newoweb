<?php

declare(strict_types=1);

namespace App\Message\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Borra el `_debug_trace` que dejó el hack de auditoría de `Message::appendDebugTrace()`.
 *
 * ── Qué se borra y por qué se puede ─────────────────────────────────────────
 * Ese hack apilaba una entrada con `debug_backtrace()` en `metadata` **en cada escritura**, con
 * el valor entero dentro. Existía para cazar un bug real —dos procesos leían `metadata`, cada uno
 * añadía su clave y el segundo pisaba la del primero— y el bug está cerrado: repasadas todas las
 * escrituras de julio a septiembre de 2026 contra el metadata final, **3 558 comprobadas y 0 cuyo
 * rastro no sobreviva**.
 *
 * Lo que dejó: **45,5 MB en 3 248 mensajes, el 97 % de la columna**, y 3 479 entradas en el peor.
 *
 * ⚠️ Lo que este comando **no** arregla, aunque al investigar se creyera que sí: el «Out of sort
 * memory» al ordenar seleccionando la columna JSON. Eso depende del ancho declarado de la columna
 * y ocurre con la tabla ya limpia. Ver el aviso en `Message::$metadata`.
 *
 * ── Por qué SQL y no ORM ────────────────────────────────────────────────────
 * Contra la regla general de este proyecto, y con motivo: aquí se quita una clave de un JSON sin
 * tocar ninguna otra. No hay `#[AutoTranslate]` ni listener de coherencia que deba correr —al
 * revés: hacerlo por el ORM dispararía `postUpdate` de **cada mensaje**, y ahí vive
 * `MessageAutoResponderListener`, que mira si cambió `metadata`. Purgar por el ORM podría
 * despertar al autorespondedor sobre 3 248 mensajes viejos.
 *
 * `JSON_REMOVE` deja intactas `beds24`, `whatsappMeta`, `inbound_intent` y las demás.
 *
 * ── Antes de borrar, copia ──────────────────────────────────────────────────
 * Con `--respaldo=<ruta>` se vuelca lo que se va a quitar. Cuesta un archivo y evita descubrir
 * dentro de un mes que alguien sí lo quería.
 *
 * Uso: `php bin/console app:message:purgar-traza --dry-run`
 */
#[AsCommand(
    name: 'app:message:purgar-traza',
    description: 'Quita el `_debug_trace` de auditoría del metadata de los mensajes.'
)]
final class MessagePurgarTrazaAuditoriaCommand extends Command
{
    /** Filas por tanda. Ni una a una —3 248 viajes— ni todas de golpe sobre una columna de 46 MB. */
    private const TANDA = 200;

    public function __construct(private readonly Connection $conexion)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dice qué borraría, sin borrar.')
            ->addOption('respaldo', null, InputOption::VALUE_REQUIRED, 'Archivo donde volcar las trazas antes de quitarlas.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $respaldo = $input->getOption('respaldo');

        $antes = $this->conexion->fetchAssociative(
            'SELECT COUNT(*) AS filas,
                    ROUND(SUM(LENGTH(metadata))/1048576, 1) AS mb,
                    ROUND(SUM(LENGTH(metadata) - LENGTH(JSON_REMOVE(metadata, "$._debug_trace")))/1048576, 1) AS traza_mb
               FROM msg_message
              WHERE JSON_CONTAINS_PATH(metadata, "one", "$._debug_trace")'
        );

        if (!is_array($antes) || (int) $antes['filas'] === 0) {
            $io->success('No queda ni una traza. Nada que hacer.');

            return Command::SUCCESS;
        }

        $io->table(['mensajes con traza', 'metadata total', 'de eso, traza'], [[
            $antes['filas'],
            $antes['mb'] . ' MB',
            $antes['traza_mb'] . ' MB',
        ]]);

        if (is_string($respaldo) && !$seco) {
            $this->respaldar($io, $respaldo);
        }

        if ($seco) {
            $io->note('Modo seco: no se ha borrado nada.');

            return Command::SUCCESS;
        }

        $total = 0;

        do {
            $tocadas = $this->conexion->executeStatement(
                'UPDATE msg_message
                    SET metadata = JSON_REMOVE(metadata, "$._debug_trace")
                  WHERE JSON_CONTAINS_PATH(metadata, "one", "$._debug_trace")
                  LIMIT ' . self::TANDA
            );
            $total += $tocadas;
            $io->write(sprintf("\r  %d / %s mensajes limpiados", $total, $antes['filas']));
        } while ($tocadas > 0);

        $io->newLine(2);

        $despues = $this->conexion->fetchOne('SELECT ROUND(SUM(LENGTH(metadata))/1048576, 1) FROM msg_message');

        $io->success(sprintf(
            '%d mensajes limpiados. La columna `metadata` de toda la tabla pesa ahora %s MB.',
            $total,
            (string) $despues
        ));

        // El espacio no vuelve al disco solo: InnoDB lo deja como hueco reutilizable. Se recupera
        // con `OPTIMIZE TABLE msg_message`, que BLOQUEA la tabla — se hace a mano y con calma, no
        // desde aquí y no en hora punta.
        $io->note('Para devolver el espacio al disco: OPTIMIZE TABLE msg_message (bloquea la tabla).');

        return Command::SUCCESS;
    }

    private function respaldar(SymfonyStyle $io, string $ruta): void
    {
        $manejador = fopen($ruta, 'wb');

        if ($manejador === false) {
            $io->warning(sprintf('No se pudo abrir %s para escribir. Se sigue SIN respaldo.', $ruta));

            return;
        }

        $resultado = $this->conexion->executeQuery(
            'SELECT LOWER(HEX(id)) AS id, JSON_EXTRACT(metadata, "$._debug_trace") AS traza
               FROM msg_message
              WHERE JSON_CONTAINS_PATH(metadata, "one", "$._debug_trace")'
        );

        $n = 0;

        // Una línea por mensaje (JSONL) y no un array gigante: 45 MB en un solo `json_encode` es
        // pedir un pico de memoria por nada, y así se puede leer con `grep`.
        while ($fila = $resultado->fetchAssociative()) {
            fwrite($manejador, (string) json_encode($fila) . "\n");
            $n++;
        }

        fclose($manejador);
        $io->text(sprintf('Respaldo: %d trazas en %s (%s MB).', $n, $ruta, number_format(filesize($ruta) / 1048576, 1)));
    }
}
