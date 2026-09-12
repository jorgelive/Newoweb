<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Guia\PmsGuiaAcceso;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Comprueba que ningún mensaje automático de llegada salga ANTES de que la guía abra sus códigos.
 *
 * ── La invariante, y por qué necesita un guardián ───────────────────────────
 * La ventana de la guía vive en código (`PmsGuiaAcceso::HORAS_ANTICIPACION`, hoy 30 h) y el
 * recordatorio de llegada vive en una fila de `msg_rule`, editable desde el panel. **Son dos
 * valores que tienen que estar de acuerdo y nada lo impone.**
 *
 * El docblock de la constante ya lo dice —«el recordatorio tiene que salir con la ventana ya
 * abierta»— y esa frase es justamente lo que una máquina puede comprobar. No se trata de que los
 * números sean iguales: se trata de que el mensaje no llegue antes de tiempo.
 *
 * Ya pasó al revés: hasta el 11/09/2026 la ventana era de 24 h y el recordatorio salía a 30, así
 * que **durante seis horas el mensaje mandaba al huésped a un candado**, el día en que más busca
 * esos datos. Se arregló subiendo la ventana; lo que faltaba era que no volviera a separarse en
 * silencio.
 *
 * ── Qué mira ────────────────────────────────────────────────────────────────
 * Las reglas ACTIVAS sobre el hito `start`: si alguna se programa más de `HORAS_ANTICIPACION`
 * antes, se denuncia. Las de después no molestan —el huésped ya puede ver todo—, así que no se
 * tocan.
 *
 * ⚠️ **No arregla nada**: dice qué está desalineado y devuelve código ≠ 0 para que un cron lo
 * note. Cuál de los dos números mover es una decisión de producto —se puede abrir antes la guía o
 * atrasar el mensaje— y ya se tomó una vez en cada sentido.
 *
 * Uso: `php bin/console app:pms:verificar-ventana-guia`
 */
#[AsCommand(
    name: 'app:pms:verificar-ventana-guia',
    description: 'Comprueba que ningún recordatorio de llegada salga antes de que la guía abra.'
)]
final class PmsVerificarVentanaGuiaCommand extends Command
{
    public function __construct(private readonly Connection $conexion)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limite = PmsGuiaAcceso::HORAS_ANTICIPACION * 60;

        /** @var list<array{name: string, offset_minutes: int}> $reglas */
        $reglas = $this->conexion->fetchAllAssociative(
            "SELECT name, offset_minutes
               FROM msg_rule
              WHERE is_active = 1 AND milestone = 'start'
              ORDER BY offset_minutes"
        );

        if ($reglas === []) {
            $io->warning('No hay ninguna regla activa sobre el hito «start». Nada que comparar.');

            return Command::SUCCESS;
        }

        $filas = [];
        $desalineadas = 0;

        foreach ($reglas as $regla) {
            $offset = (int) $regla['offset_minutes'];
            $antesDeQueAbra = $offset < 0 && abs($offset) > $limite;

            if ($antesDeQueAbra) {
                $desalineadas++;
            }

            $filas[] = [
                $regla['name'],
                sprintf('%+d min (%+.1f h)', $offset, $offset / 60),
                $antesDeQueAbra ? sprintf('SALE %.1f h ANTES de que la guía abra', (abs($offset) - $limite) / 60) : 'ok',
            ];
        }

        $io->table(
            ['Regla sobre «start»', 'Cuándo sale', sprintf('Contra la ventana de %d h', PmsGuiaAcceso::HORAS_ANTICIPACION)],
            $filas
        );

        if ($desalineadas > 0) {
            $io->error(sprintf(
                '%d regla(s) mandan al huésped a la guía antes de que sus códigos estén visibles. '
                . 'O se abre antes la ventana (PmsGuiaAcceso::HORAS_ANTICIPACION) o se atrasa el mensaje.',
                $desalineadas
            ));

            return Command::FAILURE;
        }

        $io->success('Ninguna regla de llegada se adelanta a la ventana de la guía.');

        return Command::SUCCESS;
    }
}
