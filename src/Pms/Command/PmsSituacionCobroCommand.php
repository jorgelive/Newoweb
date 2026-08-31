<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsQueSePide;
use App\Pms\Finanzas\PmsSituacionDeCobro;
use App\Pax\Service\TextosUi;
use App\Pms\Finanzas\PmsRedactorDeCobro;
use App\Pms\Finanzas\PmsSituacionDeCobroResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Qué decide {@see PmsSituacionDeCobroResolver} sobre reservas reales.
 *
 * ── Por qué un comando y no un `var/probar-*.php` ───────────────────────────
 * El resolver y sus seis dependencias son servicios privados: un script suelto no puede
 * construirlos sin reimplementar el grafo de inyección, que es exactamente el tipo de
 * andamio que se rompe al primer cambio de constructor. Un comando los recibe inyectados.
 *
 * Y sobre todo: esto **no es un andamio**. El read-model decide qué se le pide a un huésped,
 * y esa decisión hay que poder auditarla en producción, que es donde están los casos raros
 * —canales espejo, dos monedas, canceladas con penalización— y donde no hay más herramienta
 * que la consola. Es el mismo motivo que `pms:reserva:auditar`.
 *
 * Es **solo lectura**: ni escribe, ni recalcula, ni emite enlaces.
 *
 *   php bin/console pms:situacion-cobro              # las 25 más recientes
 *   php bin/console pms:situacion-cobro XTHRMQ       # una, con su detalle
 *   php bin/console pms:situacion-cobro --limite=100
 */
#[AsCommand(
    name: 'pms:situacion-cobro',
    description: 'Qué se le pide a cada reserva y por qué. Solo lectura.',
)]
final class PmsSituacionCobroCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsSituacionDeCobroResolver $resolver,
        private readonly TextosUi $textos,
        private readonly PmsRedactorDeCobro $redactor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('localizador', InputArgument::OPTIONAL, 'Una reserva concreta, con su detalle')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Cuántas revisar en el listado', '25')
            // Los rótulos tal cual los va a leer el huésped. Es la única forma de comprobar la
            // cadena entera —código del medio → clave de `pax_ui_i18n` → idioma— sin mandar un
            // WhatsApp de verdad, y el sitio donde se ve si falta una traducción.
            ->addOption('idioma', null, InputOption::VALUE_REQUIRED, 'Rótulos en este idioma (es, en, pt, fr, it, de, nl)', 'es');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $localizador = $input->getArgument('localizador');

        if (is_string($localizador) && $localizador !== '') {
            return $this->unaReserva($io, $localizador, (string) $input->getOption('idioma'));
        }

        return $this->listado($io, max(1, (int) $input->getOption('limite')));
    }

    private function unaReserva(SymfonyStyle $io, string $localizador, string $idioma): int
    {
        $reserva = $this->em->getRepository(PmsReserva::class)->findOneBy(['localizador' => $localizador]);

        if (!$reserva instanceof PmsReserva) {
            $io->error(sprintf('No existe la reserva %s.', $localizador));

            return Command::FAILURE;
        }

        $io->title(sprintf('%s · %s', $localizador, $reserva->getNombreApellido() ?? 'sin titular'));

        $situacion = $this->resolver->paraHuesped($reserva);

        $io->definitionList(
            ['Canal' => $reserva->getChannel()?->getId() ?? 'directo'],
            ['Llegada' => $reserva->getFechaLlegada()?->format('Y-m-d') ?? '—'],
            ['Se pide' => $this->queSePide($situacion->queSePide)],
            ['Motivo' => $situacion->motivo->name ?? '—'],
            ['Enlace vivo' => $situacion->enlacePago ?? '—'],
        );

        if ($situacion->importes !== []) {
            $io->section('Importes (por moneda, sin convertir)');
            $io->table(['Moneda', 'Importe', 'En soles'], array_map(
                static fn ($i): array => [$i->moneda, $i->importe, $i->enSoles ?? '—'],
                $situacion->importes,
            ));
        }

        if ($situacion->medios !== []) {
            // Agrupado por IMPORTE, igual que lo ve el huésped: si aquí saliera una fila por
            // medio, la consola diría algo distinto de la pantalla y esta auditoría dejaría
            // de servir para lo que se hizo.
            $io->section('Cómo puede pagarlo');
            $io->table(['Cuesta', 'En soles', 'Recargo', 'Por estos medios'], array_map(
                // Sin `static`: el rótulo lo traduce `rotulos()`, que es un método de esta clase.
                fn (array $g): array => [
                    $g['importe'],
                    $g['enSoles'] ?? '—',
                    $g['recargoPorcentaje'] !== null ? $g['recargoPorcentaje'] . ' %' : '—',
                    $this->rotulos($g['codigos'], $idioma),
                ],
                $situacion->mediosPorImporte(),
            ));

            // Y las FICHAS, que es lo que la app enseña detrás de la «i». Van aquí porque
            // son parte de lo que recibe el huésped: un medio ofrecido cuyo `FinMedioCobro`
            // está sin número es una «i» que abre un cuadro vacío, y eso no lo dice ninguna
            // otra pantalla — el catálogo se edita en el panel, lejos de la reserva.
            $sinFicha = [];

            foreach ($situacion->medios as $medio) {
                if ($medio->fichas === []) {
                    $sinFicha[] = $medio->etiqueta;
                    continue;
                }

                foreach ($medio->fichas as $ficha) {
                    $io->writeln(sprintf(
                        '  <info>%s</info>  %s',
                        $medio->etiqueta,
                        implode(' · ', array_filter([
                            $ficha->getBanco(),
                            $ficha->getNumero(),
                            $ficha->getCci() !== null ? 'CCI ' . $ficha->getCci() : null,
                            $ficha->getTitular(),
                            $ficha->getMoneda(),
                        // Vacía = la ficha existe en el catálogo pero no lleva ni un dato.
                        // El huésped no ve «i»: `PmsReservaPaxProvider::conFichas()` la
                        // descarta antes de mandarla, para no abrirle un cuadro en blanco.
                        ])) ?: '<comment>ficha vacía — sin «i» en la app</comment>',
                    ));
                }
            }

            if ($sinFicha !== []) {
                // No siempre es un fallo: efectivo se paga en recepción y no tiene cuenta.
                $io->writeln(sprintf('  <comment>Sin ficha (no llevan «i»):</comment> %s', implode(', ', $sinFicha)));
            }
        }

        // El SEGUNDO tramo. Se imprime aparte y no mezclado con el de arriba porque son dos
        // momentos distintos con medios distintos: aquí es donde se ve, de un vistazo, que el
        // efectivo aparece y Western Union desaparece.
        if ($situacion->saldoTrasAdelanto !== null) {
            $tramo = $situacion->saldoTrasAdelanto;

            $io->section($this->textos->texto('res_saldo_al_llegar', $idioma));
            $io->definitionList(
                ['Queda por pagar' => sprintf(
                    '%s %s%s',
                    $tramo->importe->importe,
                    $tramo->importe->moneda,
                    $tramo->importe->enSoles !== null ? ' (S/ ' . $tramo->importe->enSoles . ')' : '',
                )],
            );
            $io->table(['Cuesta', 'En soles', 'Recargo', 'Por estos medios'], array_map(
                // Sin `static`: el rótulo lo traduce `rotulos()`, que es un método de esta clase.
                fn (array $g): array => [
                    $g['importe'],
                    $g['enSoles'] ?? '—',
                    $g['recargoPorcentaje'] !== null ? $g['recargoPorcentaje'] . ' %' : '—',
                    $this->rotulos($g['codigos'], $idioma),
                ],
                $tramo->mediosPorImporte(),
            ));
        }

        // Y lo último, el TEXTO: lo que de verdad va a leer el huésped en el mensaje. Las tablas
        // de arriba dicen qué decidió el read-model; esto dice cómo queda dicho, que es donde se
        // ven las cosas que ninguna tabla enseña — una línea vacía de más, un rótulo sin
        // traducir, un enlace donde no toca. Ver `PmsRedactorDeCobro`.
        $bloque = $this->redactor->bloque($reserva, $idioma);

        $io->section(sprintf('El bloque del mensaje (%s)', $idioma));
        $io->writeln($bloque !== '' ? $bloque : '<comment>(vacío: no hay nada honesto que decirle)</comment>');
        $io->newLine();

        // La proyección del equipo tiene que DECIDIR lo mismo: sólo cambia qué campos lleva.
        $equipo = $this->resolver->paraEquipo($reserva);

        if ($equipo->queSePide !== $situacion->queSePide) {
            $io->warning('La decisión difiere entre huésped y equipo. No debería: la proyección no decide.');
        }

        return Command::SUCCESS;
    }

    private function listado(SymfonyStyle $io, int $limite): int
    {
        $reservas = $this->em->getRepository(PmsReserva::class)
            ->findBy([], ['createdAt' => 'DESC'], $limite);

        $filas = [];
        $reparto = [];

        foreach ($reservas as $reserva) {
            $s = $this->resolver->paraHuesped($reserva);

            $detalle = $s->hayAlgoQuePedir()
                ? implode(' + ', array_map(
                    static fn ($i): string => $i->moneda . ' ' . $i->importe,
                    $s->importes,
                ))
                : ($s->motivo->name ?? '—');

            $clave = $s->hayAlgoQuePedir() ? $this->queSePide($s->queSePide) : ($s->motivo->name ?? '—');
            $reparto[$clave] = ($reparto[$clave] ?? 0) + 1;

            $filas[] = [
                $reserva->getLocalizador() ?? '—',
                $reserva->getChannel()?->getId() ?? 'directo',
                $this->queSePide($s->queSePide),
                $detalle,
                (string) count($s->medios),
                $s->enlacePago !== null ? 'sí' : '—',
            ];
        }

        $io->table(['Localizador', 'Canal', 'Se pide', 'Importes / motivo', 'Medios', 'Enlace'], $filas);

        arsort($reparto);
        $io->section('Reparto');
        foreach ($reparto as $clave => $n) {
            $io->writeln(sprintf('  %-22s %d', $clave, $n));
        }

        return Command::SUCCESS;
    }

    /**
     * Los rótulos de unos medios en el idioma del huésped.
     *
     * Va por el **código** (`yape`, `efectivo`) y no por la etiqueta que trae el grupo: ésa sale
     * de `FinMedioCobroTipo::label()` y está en español y sólo en español. Las claves
     * `res_medio_*` de `pax_ui_i18n` llevan los siete idiomas desde hace tiempo — lo que faltaba
     * era leerlas desde PHP ({@see TextosUi}).
     *
     * @param list<string> $codigos
     */
    private function rotulos(array $codigos, string $idioma): string
    {
        return implode(' · ', array_map(
            fn (string $codigo): string => $this->textos->texto('res_medio_' . $codigo, $idioma),
            $codigos,
        ));
    }

    private function queSePide(PmsQueSePide $q): string
    {
        return match ($q) {
            PmsQueSePide::ADELANTO => 'ADELANTO',
            PmsQueSePide::TOTAL => 'TOTAL',
            PmsQueSePide::NADA => 'nada',
        };
    }
}
