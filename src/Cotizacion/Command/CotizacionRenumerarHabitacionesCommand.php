<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Cotizacion\Enum\PasajeroTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Devuelve las habitaciones a su bloque: alumnos en uno, adultos en otro.
 *
 * ── La convención, y por qué se desvía sola ─────────────────────────────────
 * Un viaje de promoción numera las habitaciones en dos bloques —`HA…` para los alumnos, `HP…` para
 * padres y coordinadores— porque el hotel factura distinto y la operativa los trata distinto. Nada
 * en el sistema la impone: es un acuerdo escrito en un rooming list, y a la décima corrección a
 * mano deja de cumplirse. Medido en el expediente real: **48 de 52 bien, 4 desviadas**.
 *
 * ── 🔥 La unidad es la HABITACIÓN, no la persona ────────────────────────────
 * Es la regla que hay que entender antes de tocar esto. En una `DOBLE` puede dormir una acompañante
 * con su hija participante: mandar «a cada uno a su bloque» las separaría. Así que se mueve el
 * CUARTO entero, y el criterio es:
 *
 *   todos los ocupantes son participantes  →  bloque de alumnos
 *   hay al menos un adulto                 →  bloque de adultos
 *
 * Con eso, la madre y la hija siguen juntas y el bloque dice la verdad. Los cuatro casos reales
 * eran exactamente eso: madre e hija, padre e hijo, y un matrimonio en una `MATRIMONIAL`.
 *
 * ── ⚠️ Sólo se mueve lo que está mal ────────────────────────────────────────
 * Las que ya están en su bloque **no se renumeran**, aunque queden huecos (`HA01` libre). Renumerar
 * las 48 correctas para dejarlas contiguas sería cosmético y **caro**: el pasajero ya ve su código
 * en la app —«CÓDIGO DE GRUPO HA23»— y cambiárselo deja desfasado a todo el que ya lo miró. Un
 * hueco en la numeración no se lo encuentra nadie; un código que cambia, sí.
 *
 * ── ⚠️ Esto NO se avisa al hotel ────────────────────────────────────────────
 * Estos códigos son NUESTROS, no los números de puerta: el hotel no sabe qué es «HA23». Por eso
 * renumerar aquí es barato. Si algún día los códigos pasaran a ser los del hotel, esta suposición
 * deja de valer y este comando se vuelve peligroso.
 *
 * Idempotente: pasarlo dos veces no mueve nada la segunda.
 *
 *   php bin/console app:cotizacion:renumerar-habitaciones 5SRAJV --dry-run
 *   php bin/console app:cotizacion:renumerar-habitaciones 5SRAJV
 */
#[AsCommand(
    name: 'app:cotizacion:renumerar-habitaciones',
    description: 'Manda cada habitación a su bloque: sólo participantes en HA, el resto en HP. Idempotente.',
)]
final class CotizacionRenumerarHabitacionesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('localizador', InputArgument::REQUIRED, 'El localizador del expediente')
            ->addOption('alumnos', null, InputOption::VALUE_REQUIRED, 'Prefijo del bloque de alumnos', 'HA')
            ->addOption('adultos', null, InputOption::VALUE_REQUIRED, 'Prefijo del bloque de adultos', 'HP')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $alumnos = mb_strtoupper(trim((string) $input->getOption('alumnos')));
        $adultos = mb_strtoupper(trim((string) $input->getOption('adultos')));
        $simular = (bool) $input->getOption('dry-run');

        if ($alumnos === '' || $adultos === '' || $alumnos === $adultos) {
            $io->error('Los dos bloques tienen que existir y ser distintos.');

            return Command::FAILURE;
        }

        $file = $this->em->getRepository(CotizacionFile::class)
            ->findOneBy(['localizador' => (string) $input->getArgument('localizador')]);

        if ($file === null) {
            $io->error('No existe ese expediente.');

            return Command::FAILURE;
        }

        /** @var list<CotizacionFileGrupo> $habitaciones */
        $habitaciones = [];
        /** @var array<string, true> $ocupadas */
        $ocupadas = [];

        foreach ($file->getGrupos() as $grupo) {
            if ($grupo->getTipo() === GrupoTipoEnum::HABITACION) {
                $habitaciones[] = $grupo;
                $ocupadas[mb_strtoupper((string) $grupo->getClave())] = true;
            }
        }

        if ($habitaciones === []) {
            $io->success('Este expediente no tiene habitaciones.');

            return Command::SUCCESS;
        }

        $movimientos = [];

        foreach ($habitaciones as $grupo) {
            $clave = mb_strtoupper((string) $grupo->getClave());
            $destino = $this->soloParticipantes($grupo) ? $alumnos : $adultos;

            if (str_starts_with($clave, $destino)) {
                continue;
            }

            $nueva = $this->siguienteLibre($destino, $ocupadas);
            $ocupadas[$nueva] = true;

            $movimientos[] = [$clave, $nueva, $this->quienes($grupo)];

            if (!$simular) {
                $grupo->setClave($nueva);
            }
        }

        if ($movimientos === []) {
            $io->success(sprintf('Nada que mover: las %d habitaciones ya están en su bloque.', count($habitaciones)));

            return Command::SUCCESS;
        }

        $io->table(['de', 'a', 'quiénes duermen ahí'], $movimientos);

        if ($simular) {
            $io->note(sprintf('--dry-run: no se escribió nada. Se moverían %d.', count($movimientos)));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d habitación(es) movida(s) de bloque.', count($movimientos)));

        return Command::SUCCESS;
    }

    /**
     * ⚠️ Una habitación **sin ocupantes** no es «sólo participantes»: es un cuarto vacío, y
     * mandarlo al bloque de alumnos sería inventarse a quién pertenece. Se queda donde está.
     */
    private function soloParticipantes(CotizacionFileGrupo $grupo): bool
    {
        $hay = false;

        foreach ($grupo->getMiembros() as $miembro) {
            $hay = true;

            if ($miembro->getPasajero()?->getTipo() !== PasajeroTipoEnum::PARTICIPANTE) {
                return false;
            }
        }

        return $hay;
    }

    /** @param array<string, true> $ocupadas */
    private function siguienteLibre(string $prefijo, array $ocupadas): string
    {
        // Dos dígitos, como las que ya hay. A partir de 99 se ensancha solo en vez de repetir.
        for ($n = 1; $n <= 999; ++$n) {
            $clave = $prefijo . str_pad((string) $n, 2, '0', STR_PAD_LEFT);

            if (!isset($ocupadas[$clave])) {
                return $clave;
            }
        }

        throw new \RuntimeException(sprintf('No queda ningún número libre en el bloque %s.', $prefijo));
    }

    private function quienes(CotizacionFileGrupo $grupo): string
    {
        $gente = [];

        foreach ($grupo->getMiembros() as $miembro) {
            $pax = $miembro->getPasajero();

            if ($pax === null) {
                continue;
            }

            // ⚠️ El rol es NULABLE en la entidad (`?PasajeroTipoEnum $tipo = null`). PHPStan pedía
            // quitar el `?->` aquí —decía que nunca es nulo— y hacerle caso habría dejado un fatal
            // esperando a la primera persona sin rol. Se escribe la comprobación a la vista.
            $tipo = $pax->getTipo();

            $gente[] = sprintf(
                '%s (%s)',
                trim($pax->getNombre() . ' ' . $pax->getApellido()),
                $tipo === null ? 'sin rol' : $tipo->value,
            );
        }

        return implode(' · ', $gente);
    }
}
