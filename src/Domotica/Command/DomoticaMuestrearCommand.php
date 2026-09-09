<?php

declare(strict_types=1);

namespace App\Domotica\Command;

use App\Domotica\Entity\DomoticaCambioEstado;
use App\Domotica\Entity\DomoticaDispositivo;
use App\Domotica\Repository\DomoticaDispositivoRepository;
use App\Domotica\Service\VigilanteDeDispositivos;
use App\Exchange\Service\Client\TuyaClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * El ciclo de monitorización: en qué estado está cada aparato y cuánto está gastando.
 *
 * ── Dos llamadas para el parque entero ──────────────────────────────────────
 *
 * El coste no crece con el número de aparatos, y eso es lo que hace viable mirarlos todos:
 *
 *   1 llamada   estado de los 28   (`/v1.0/devices/status` con todos los ids)
 *   1 llamada   quién está en línea (`/v1.0/devices` con todos los ids)
 *   N llamadas  sólo los que MIDEN  (`shadow/properties`, que da la hora de cada dato)
 *
 * Con tres aparatos que miden son cinco llamadas por ciclo. A cinco minutos, ~1.400 al día para
 * todo el parque — frente a las ~10.800 que costaba UN aparato observado a ocho segundos. El
 * problema de cuota lo resuelve el lote, no el push.
 *
 * ⚠️ **Nadie consulta a Tuya desde una petición web.** Este ciclo escribe en la tabla; `pax` y
 * `util` leen de ahí. Es lo que impide que la cuota dependa de cuánta gente tenga una pestaña
 * abierta — un huésped con el móvil en el bolsillo toda la noche no puede costar dinero.
 *
 * ── Por qué la hora la pone el aparato ──────────────────────────────────────
 *
 * `/status` devuelve los últimos valores conocidos aunque el aparato lleve tres días sin dar
 * señales, y sin ninguna marca de frescura. Guardar aquí el reloj del muestreo convertiría un dato
 * de hace 58 horas en un dato «de ahora». Por eso los que miden pasan por `shadow/properties`, que
 * da la hora de CADA campo — y son horas distintas: `switch_1` de hace un minuto y `cur_power` de
 * hace nueve días, en el mismo aparato.
 *
 *   5  * * * *   php bin/console app:domotica:muestrear
 *   35 * * * *   php bin/console app:domotica:vigilar
 */
#[AsCommand(
    name: 'app:domotica:muestrear',
    description: 'Lee el estado de todos los aparatos y el consumo de los que miden.'
)]
final class DomoticaMuestrearCommand extends Command
{
    public function __construct(
        private readonly TuyaClient $tuya,
        private readonly DomoticaDispositivoRepository $dispositivos,
        private readonly VigilanteDeDispositivos $vigilante,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo leído, sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        $aparatos = $this->dispositivos->activos();

        if ($aparatos === []) {
            $io->warning('No hay aparatos activos. Corre primero app:domotica:sincronizar-dispositivos y asígnales unidad.');

            return Command::SUCCESS;
        }

        $ids = array_values(array_filter(array_map(
            static fn (DomoticaDispositivo $d): string => $d->getTuyaDeviceId(),
            $aparatos
        )));

        // Las dos llamadas que cubren el parque entero.
        $estados = $this->tuya->estadoDeVarios($ids);
        $enLinea = $this->tuya->enLineaDeVarios($ids);

        $filas = [];

        foreach ($aparatos as $aparato) {
            $id = $aparato->getTuyaDeviceId();
            $dps = $estados[$id] ?? [];
            $vivo = $enLinea[$id] ?? false;

            $aparato->setEnLinea($vivo);

            $encendido = isset($dps['switch_1']) ? (bool) $dps['switch_1'] : null;

            // ¿Cambió el interruptor desde el ciclo anterior? Es lo único que merece una fila.
            $cambio = $encendido !== null && $encendido !== $aparato->getEncendido();

            // Los que no miden se quedan aquí: su `switch_1` sale del lote y no gastan una llamada
            // propia. Preguntar la hora exacta de un interruptor no compensa una llamada por
            // aparato.
            if (!$aparato->mideConsumo()) {
                if ($cambio) {
                    // Sólo AQUÍ se gasta una llamada extra. El lote no trae marcas de tiempo, así
                    // que la hora exacta hay que pedirla — pero los cambios son raros (una estufa
                    // se acciona un puñado de veces al día), así que el coste del ciclo sigue
                    // siendo dos llamadas casi siempre. Pedirla a los 24 en cada ciclo, por si
                    // acaso, multiplicaría el gasto por doce para el mismo dato.
                    $this->anotarCambio($aparato, (bool) $encendido, $this->horaDelAparato($id));
                }

                $aparato->registrarEstado(new DateTimeImmutable(), $encendido);

                $filas[] = [mb_substr($aparato->getNombre(), 0, 24), $vivo ? 'en línea' : 'desconect.', $this->comoTexto($encendido), '—', $cambio ? '⇄ CAMBIÓ' : '—'];

                continue;
            }

            // Los que miden sí: hace falta saber de cuándo es cada número.
            $props = $this->tuya->propiedadesConHora($id);

            $tSwitch = $props['switch_1']['momento'] ?? null;

            // Los que miden ya pasan por `shadow/properties` en cada ciclo, así que su hora exacta
            // sale gratis.
            if ($cambio) {
                $this->anotarCambio($aparato, (bool) $encendido, $tSwitch);
            }

            $aparato->registrarEstado($tSwitch ?? new DateTimeImmutable(), $encendido);

            $vatios = null;
            $tPotencia = null;

            if (isset($props['cur_power'])) {
                $crudo = $props['cur_power']['valor'];
                // `cur_power` viene con escala 1: el entero se divide entre 10 para leer vatios.
                $vatios = is_numeric($crudo) ? (int) round(((float) $crudo) / 10) : null;
                $tPotencia = $props['cur_power']['momento'];
            }

            $aparato->registrarPotencia($vatios, $tPotencia);

            // Sin dato fresco no hay lectura buena: cuenta como fallo y el vigilante decide si
            // toca avisar. Un aparato desconectado NO actualiza `lecturaTomadaEn`, que es lo que
            // mira el barrido por reloj.
            if (!$vivo) {
                $this->vigilante->anotarFallo($aparato);
            } else {
                $contador = $props['add_ele']['valor'] ?? null;

                if (is_numeric($contador)) {
                    // `add_ele` viene con escala 3: el entero se divide entre 1000 para kW·h.
                    $aparato->registrarLectura(
                        $props['add_ele']['momento'],
                        number_format(((float) $contador) / 1000, 3, '.', '')
                    );
                }
            }

            $filas[] = [
                mb_substr($aparato->getNombre(), 0, 24),
                $vivo ? 'en línea' : 'desconect.',
                $this->comoTexto($encendido),
                $vatios === null ? '—' : $vatios . ' W',
                $aparato->potenciaEsReciente() ? 'reciente' : 'SIN DATO RECIENTE',
            ];
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->table(['Aparato', 'Nube', 'Relé', 'Potencia', 'Frescura'], $filas);

        $io->success(sprintf(
            '%d aparato(s) muestreados en %d llamada(s).%s',
            count($aparatos),
            2 + count(array_filter($aparatos, static fn (DomoticaDispositivo $d): bool => $d->mideConsumo())),
            $seco ? ' Nada escrito (--dry-run).' : ''
        ));

        return Command::SUCCESS;
    }

    /**
     * Escribe la transición.
     *
     * `$momento` a `null` significa que el aparato no dio su hora —está desconectado, o el DP no
     * viene—: se guarda la del muestreo y la fila queda marcada como NO exacta. Con ciclo de cinco
     * minutos el error máximo es de cinco minutos, que no cambia ninguna decisión; lo que sí la
     * cambiaría es creer que es exacta cuando no lo es.
     */
    private function anotarCambio(DomoticaDispositivo $aparato, bool $encendido, ?DateTimeImmutable $momento): void
    {
        $cambio = new DomoticaCambioEstado();
        $cambio->setDispositivo($aparato);
        $cambio->setEncendido($encendido);
        // A la hora de pared del establecimiento del aparato, igual que los `registrar*()`: lo que
        // llega de Tuya es UTC y esta columna guarda hora local.
        $cambio->setOcurridoEn(($momento ?? new DateTimeImmutable())->setTimezone($aparato->zonaHoraria()));
        $cambio->setHoraExacta($momento !== null);

        $this->em->persist($cambio);
    }

    /** La hora que dice el APARATO para su `switch_1`, o `null` si no la da. */
    private function horaDelAparato(string $tuyaDeviceId): ?DateTimeImmutable
    {
        try {
            return $this->tuya->propiedadesConHora($tuyaDeviceId)['switch_1']['momento'] ?? null;
        } catch (\Throwable) {
            // Que no se pueda precisar la hora no puede tumbar el ciclo entero: se anota con la
            // del muestreo, que es peor pero no es nada.
            return null;
        }
    }

    /** El relé, dicho para una persona. `null` es «nunca se ha leído», que no es lo mismo que apagado. */
    private function comoTexto(?bool $encendido): string
    {
        return match ($encendido) {
            true => 'cerrado',
            false => 'abierto',
            null => '?',
        };
    }
}
