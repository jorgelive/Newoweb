<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionVuelo;
use App\Cotizacion\Enum\GrupoTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Carga los vuelos de un expediente desde un JSON.
 *
 * ## Ensaya por defecto, y escribe sólo si se lo piden
 *
 * ⚠️ Lo dispara un correo de la aerolínea, y los correos se leen con prisa. Sin `--aplicar` sólo
 * enseña el diff: cuántos vuelos no cambian, cuáles sí y con qué, y a cuántos localizadores y
 * pasajeros alcanza el cambio.
 *
 * ## Parcial: se toca sólo lo que viene
 *
 * Una aerolínea no manda el itinerario entero, manda «su JA7013 del 23 ahora sale 06:10». Si el
 * archivo trae un vuelo, se toca ese; si un campo no viene, se deja el que había. Es la misma
 * regla del padrón: *una celda vacía es «no lo digo», no «bórralo»*.
 *
 * Nunca borra: quitar un vuelo se pide aparte.
 *
 * ## La forma del archivo
 *
 *     {
 *       "localizador": "5SRAJV",
 *       "vuelos": [{
 *         "numero": "JA7013",
 *         "fecha": "2026-09-23",
 *         "aerolinea": "JetSMART",          // opcional
 *         "emitido": true,                  // opcional, por defecto true
 *         "codigos": ["RBEJRT", "…"],       // opcional; si viene, REEMPLAZA la lista
 *         "segmentos": [{                   // opcional; si viene, REEMPLAZA el itinerario
 *           "numero": "JA7013", "origen": "LIM", "destino": "CUZ",
 *           "salida": "2026-09-23 06:10", "llegada": "2026-09-23 07:30"
 *         }],
 *         "notas": ["Actualizado por JetSMART el 28/08"]
 *       }]
 *     }
 *
 * ⚠️ `salida` y `llegada` son fecha-hora completas: no hay bandera «llega al día siguiente» que
 * pueda contradecir a las fechas.
 *
 * ⚠️ Un localizador desconocido **no se inventa**: se avisa y se deja el vuelo sin ese vínculo.
 * Es el fallo que este modelo viene a cerrar —una cadena mal tecleada que casa con nada— y
 * crearlo en silencio lo reintroduciría por la puerta de atrás.
 */
#[AsCommand(
    name: 'app:cotizacion:cargar-vuelos',
    description: 'Carga o actualiza los vuelos de un expediente desde un archivo JSON.',
)]
final class CotizacionCargarVuelosCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('archivo', InputArgument::REQUIRED, 'Ruta del JSON con los vuelos.');
        $this->addOption('aplicar', null, InputOption::VALUE_NONE, 'Escribe. Sin esto sólo enseña el diff.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $aplicar = (bool) $input->getOption('aplicar');
        $ruta = (string) $input->getArgument('archivo');

        if (!is_file($ruta)) {
            $io->error(sprintf('No existe el archivo %s', $ruta));

            return Command::FAILURE;
        }

        $crudo = json_decode((string) file_get_contents($ruta), true);

        if (!is_array($crudo) || !isset($crudo['localizador']) || !is_array($crudo['vuelos'] ?? null)) {
            $io->error('El archivo debe traer «localizador» y una lista «vuelos».');

            return Command::FAILURE;
        }

        $localizador = (string) $crudo['localizador'];
        $file = $this->em->getRepository(CotizacionFile::class)->findOneBy(['localizador' => $localizador]);

        if ($file === null) {
            $io->error(sprintf('No existe el expediente %s', $localizador));

            return Command::FAILURE;
        }

        $io->title(sprintf('Vuelos de %s', $localizador));

        $repo = $this->em->getRepository(CotizacionVuelo::class);
        $sinCambios = 0;
        $cambios = [];
        $desconocidos = [];

        foreach ($crudo['vuelos'] as $def) {
            if (!is_array($def) || !isset($def['numero'], $def['fecha'])) {
                $io->warning('Un vuelo sin «numero» o «fecha»: se salta.');
                continue;
            }

            $numero = trim((string) $def['numero']);
            $fecha = new \DateTimeImmutable((string) $def['fecha']);

            $vuelo = $repo->findOneBy(['file' => $file, 'numero' => $numero, 'fecha' => $fecha]);
            $esNuevo = $vuelo === null;

            if ($vuelo === null) {
                $vuelo = (new CotizacionVuelo())->setFile($file)->setNumero($numero)->setFecha($fecha);
                $this->em->persist($vuelo);
            }

            $diff = $this->aplicarDefinicion($vuelo, $def, $file, $desconocidos);

            if ($esNuevo) {
                $cambios[] = sprintf('  <info>nuevo</info>    %s · %s  %s', $numero, $fecha->format('d/m'), $this->resumen($vuelo));
            } elseif ($diff !== []) {
                $cambios[] = sprintf(
                    '  <comment>cambia</comment>   %s · %s   <info>%s</info>',
                    $numero,
                    $fecha->format('d/m'),
                    $this->alcance($vuelo),
                );
                foreach ($diff as $linea) {
                    $cambios[] = '             ' . $linea;
                }
            } else {
                ++$sinCambios;
            }
        }

        if ($sinCambios > 0) {
            $io->text(sprintf('  %d vuelo(s) sin cambios.', $sinCambios));
        }

        if ($cambios === []) {
            $io->success('Nada que hacer: el archivo coincide con lo que ya había.');

            return Command::SUCCESS;
        }

        $io->newLine();
        foreach ($cambios as $linea) {
            $io->writeln($linea);
        }

        if ($desconocidos !== []) {
            $io->newLine();
            $io->warning(sprintf(
                'Localizadores que no existen en el expediente y NO se crean: %s',
                implode(', ', array_unique($desconocidos)),
            ));
        }

        $io->newLine();

        if (!$aplicar) {
            $this->em->clear();
            $io->note('Ensayo: no se escribió nada. Repite con --aplicar.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Aplicado.');

        return Command::SUCCESS;
    }

    /**
     * Vuelca en el vuelo lo que el archivo TRAE, y devuelve qué cambió.
     *
     * @param array<string, mixed> $def
     * @param list<string> $desconocidos
     * @return list<string>
     */
    private function aplicarDefinicion(CotizacionVuelo $vuelo, array $def, CotizacionFile $file, array &$desconocidos): array
    {
        $diff = [];

        if (isset($def['aerolinea']) && (string) $def['aerolinea'] !== (string) $vuelo->getAerolinea()) {
            $diff[] = sprintf('aerolínea: %s → %s', $vuelo->getAerolinea() ?? '—', (string) $def['aerolinea']);
            $vuelo->setAerolinea((string) $def['aerolinea']);
        }

        if (isset($def['emitido']) && (bool) $def['emitido'] !== $vuelo->isEmitido()) {
            $diff[] = sprintf('emitido: %s → %s', $vuelo->isEmitido() ? 'sí' : 'no', $def['emitido'] ? 'sí' : 'no');
            $vuelo->setEmitido((bool) $def['emitido']);
        }

        if (isset($def['segmentos']) && is_array($def['segmentos'])) {
            $nuevos = $this->normalizarSegmentos($def['segmentos']);

            if ($this->canonico($nuevos) !== $this->canonico($vuelo->getSegmentos())) {
                foreach ($this->compararItinerarios($vuelo->getSegmentos(), $nuevos) as $linea) {
                    $diff[] = $linea;
                }
                $vuelo->setSegmentos($nuevos);
            }
        }

        if (isset($def['notas']) && is_array($def['notas'])) {
            $notas = array_values(array_map(static fn ($n): string => (string) $n, $def['notas']));

            if ($notas !== $vuelo->getNotas()) {
                $diff[] = sprintf('notas: %d', count($notas));
                $vuelo->setNotas($notas);
            }
        }

        if (isset($def['codigos']) && is_array($def['codigos'])) {
            $antes = [];
            foreach ($vuelo->getGrupos() as $g) {
                $antes[] = (string) $g->getClave();
            }
            sort($antes);

            foreach ($vuelo->getGrupos() as $g) {
                $vuelo->removeGrupo($g);
            }

            $ahora = [];
            foreach ($def['codigos'] as $clave) {
                $grupo = $this->buscarGrupo($file, (string) $clave);

                if ($grupo === null) {
                    $desconocidos[] = (string) $clave;
                    continue;
                }

                $vuelo->addGrupo($grupo);
                $ahora[] = (string) $grupo->getClave();
            }
            sort($ahora);

            if ($antes !== $ahora) {
                $diff[] = sprintf('códigos: %d → %d', count($antes), count($ahora));
            }
        }

        return $diff;
    }

    /**
     * @param array<int|string, mixed> $crudos
     * @return list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}>
     */
    private function normalizarSegmentos(array $crudos): array
    {
        $salida = [];

        foreach ($crudos as $s) {
            if (!is_array($s)) {
                continue;
            }

            $salida[] = [
                'numero' => trim((string) ($s['numero'] ?? '')),
                'origen' => strtoupper(trim((string) ($s['origen'] ?? ''))),
                'destino' => strtoupper(trim((string) ($s['destino'] ?? ''))),
                'salida' => trim((string) ($s['salida'] ?? '')),
                'llegada' => trim((string) ($s['llegada'] ?? '')),
            ];
        }

        return $salida;
    }

    /**
     * @param list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}> $antes
     * @param list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}> $ahora
     * @return list<string>
     */
    private function compararItinerarios(array $antes, array $ahora): array
    {
        $pinta = static fn (array $s): string => sprintf(
            '%s %s %s → %s %s',
            $s['numero'],
            $s['origen'],
            substr($s['salida'], 11) ?: $s['salida'],
            $s['destino'],
            substr($s['llegada'], 11) ?: $s['llegada'],
        );

        if ($antes === []) {
            return array_map(static fn (array $s): string => 'itinerario: ' . $pinta($s), $ahora);
        }

        $lineas = [];
        foreach ($ahora as $i => $s) {
            $viejo = $antes[$i] ?? null;

            if ($viejo === null) {
                $lineas[] = 'segmento nuevo: ' . $pinta($s);
            } elseif ($viejo !== $s) {
                $lineas[] = sprintf('%s  ⟶  %s', $pinta($viejo), $pinta($s));
            }
        }

        return $lineas;
    }

    /**
     * Forma canónica de un itinerario, para poder compararlo con lo guardado.
     *
     * ⚠️ **MySQL reordena las claves de un objeto JSON al guardarlo** —por longitud y luego
     * alfabéticamente—, así que lo que vuelve de la base no conserva el orden con que se
     * escribió: sale `numero, origen, salida, destino, llegada` donde se puso `numero, origen,
     * destino, salida, llegada`.
     *
     * Y el `!==` de PHP sobre arrays **sí mira el orden de las claves**. Comparar en crudo
     * daba «cambia» en los catorce vuelos con el antes y el después idénticos en pantalla, que
     * es la peor forma de fallar: parece que funciona y sólo miente en el diff.
     *
     * @param list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}> $segmentos
     */
    private function canonico(array $segmentos): string
    {
        $ordenados = array_map(
            static function (array $s): array {
                ksort($s);

                return $s;
            },
            $segmentos,
        );

        return json_encode($ordenados, JSON_THROW_ON_ERROR);
    }

    /**
     * A cuánta gente alcanza tocar este vuelo.
     *
     * Es la pregunta que motivó todo el modelo y que con el itinerario en texto libre no se
     * podía contestar: había que leer las 133 filas del padrón. Se enseña ANTES de aplicar,
     * porque lo que decide si un cambio de horario es una anécdota o una llamada a 111
     * personas es justo este número.
     */
    private function alcance(CotizacionVuelo $vuelo): string
    {
        $pax = 0;

        foreach ($vuelo->getGrupos() as $grupo) {
            $pax += $grupo->getMiembros()->count();
        }

        return sprintf(
            '(%d código%s · %d pax)',
            $vuelo->getGrupos()->count(),
            $vuelo->getGrupos()->count() === 1 ? '' : 's',
            $pax,
        );
    }

    private function buscarGrupo(CotizacionFile $file, string $clave): ?CotizacionFileGrupo
    {
        foreach ($file->getGrupos() as $grupo) {
            if ($grupo->getTipo() === GrupoTipoEnum::RESERVA_AEREA && $grupo->getClave() === $clave) {
                return $grupo;
            }
        }

        return null;
    }

    private function resumen(CotizacionVuelo $vuelo): string
    {
        $s = $vuelo->getSegmentos();

        if ($s === []) {
            return '(sin itinerario)';
        }

        return sprintf('%s → %s', $s[0]['origen'], $s[count($s) - 1]['destino']);
    }
}
