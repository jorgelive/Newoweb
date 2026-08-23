<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Enum\AudienciaDetalleEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Convierte los detalles de `tipo` a `audiencias`, fusionando los que decían lo mismo dos veces.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * El campo nació con dos tipos, `cliente` y `operativa`, y en producción **los 15 componentes que
 * tenían los dos bloques los tenían idénticos palabra por palabra**. Ni uno solo aprovechó la
 * distinción para decir cosas distintas, y ninguno tenía sólo el operativo. Lo que la forma vieja
 * conseguía era obligar a escribir dos veces y a mantener las dos copias en siete idiomas.
 *
 * ── Qué hace ────────────────────────────────────────────────────────────────
 * 1. `tipo: cliente`   → `audiencias: [cliente]`
 * 2. `tipo: operativa` → `audiencias: [interno]`
 * 3. Dos bloques con **el mismo texto en español** se funden en uno con las dos audiencias.
 *
 * ⚠️ **Nadie sale a `prestador` por conversión automática.** Es el único cambio que no se puede
 * deshacer: que a un proveedor externo le falte una línea se ve y se añade; que le sobre, se ve
 * cuando ya la leyó. `--con-prestador` existe para quien quiera asumirlo a sabiendas sobre los
 * bloques que alguien marcó como operativos.
 *
 * Idempotente: un bloque que ya tiene audiencias y no repite texto se queda igual.
 */
#[AsCommand(
    name: 'app:cotizacion:detalles-a-audiencias',
    description: 'Pasa los detalles de componente del `tipo` viejo a banderas de audiencia',
)]
final class CotizacionDetallesAAudienciasCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin guardar nada')
            ->addOption('con-prestador', null, InputOption::VALUE_NONE, 'Marca también `prestador` en lo que era operativo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $aPrestador = (bool) $input->getOption('con-prestador');

        // Los ids por SQL y las entidades por `findBy`: `JSON_LENGTH` no es una función DQL
        // registrada, y darla de alta en `doctrine.yaml` para un comando de una sola pasada sale
        // caro. Traerse todos los componentes para quedarse con los que tienen detalles, también.
        // Se trae también el JSON crudo: hace falta para saber si la fila GUARDADA ya está en la
        // forma nueva. Comparar contra lo que devuelve el getter no sirve —normaliza al leer, así
        // que un bloque con el `tipo` viejo y sin repetidos saldría «sin cambios» y se quedaría
        // con la forma antigua en base para siempre, volviendo permanente una tolerancia que sólo
        // debería durar lo que dura el despliegue.
        $crudos = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT id, detalles_operativos FROM cotizacion_cotcomponente WHERE JSON_LENGTH(detalles_operativos) > 0',
        );
        $ids = array_keys($crudos);

        /** @var list<CotizacionCotcomponente> $componentes */
        $componentes = $ids === []
            ? []
            : $this->em->getRepository(CotizacionCotcomponente::class)->findBy(['id' => $ids]);

        $tocados = 0;
        $fusionados = 0;
        $filas = [];

        foreach ($componentes as $componente) {
            // El getter ya normaliza `tipo` → `audiencias`; aquí sólo queda fundir repetidos.
            $antes = $componente->getDetallesOperativos();
            [$despues, $fusiones] = $this->fundir($antes, $aPrestador);

            $guardado = json_decode((string) ($crudos[(string) $componente->getId()] ?? '[]'), true);
            if ($despues === $guardado) {
                continue;
            }

            ++$tocados;
            $fusionados += $fusiones;
            $filas[] = [
                substr((string) $componente->getId(), 0, 13),
                (string) $componente->getTipo(),
                count($antes).' → '.count($despues),
                implode(' | ', array_map(
                    static fn (array $b): string => implode('+', $b['audiencias']),
                    $despues,
                )),
            ];

            if (!$seco) {
                $componente->setDetallesOperativos($despues);
            }
        }

        if ($filas !== []) {
            $io->table(['componente', 'tipo', 'bloques', 'audiencias'], $filas);
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d componentes %s, %d bloques repetidos fundidos.',
            $tocados,
            $seco ? 'cambiarían' : 'convertidos',
            $fusionados,
        ));

        return Command::SUCCESS;
    }

    /**
     * Funde los bloques que dicen lo mismo, uniendo sus audiencias.
     *
     * La clave es el texto **en español**, que es el original: es lo que un humano compararía
     * para decir «esto es lo mismo escrito dos veces». Comparar el JSON entero fallaría en cuanto
     * dos traducciones del mismo original salieran con una coma distinta.
     *
     * @param list<array<string, mixed>> $bloques
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function fundir(array $bloques, bool $aPrestador): array
    {
        /** @var array<string, array<string, mixed>> $porTexto */
        $porTexto = [];
        $fusiones = 0;

        foreach ($bloques as $bloque) {
            $clave = $this->textoEnEspanol($bloque);

            if (!isset($porTexto[$clave])) {
                $porTexto[$clave] = $bloque;
                continue;
            }

            ++$fusiones;
            $porTexto[$clave]['audiencias'] = array_values(array_unique(array_merge(
                $porTexto[$clave]['audiencias'],
                $bloque['audiencias'],
            )));
        }

        $salida = [];
        foreach ($porTexto as $bloque) {
            if ($aPrestador && in_array(AudienciaDetalleEnum::INTERNO->value, $bloque['audiencias'], true)) {
                $bloque['audiencias'][] = AudienciaDetalleEnum::PRESTADOR->value;
            }

            // En el orden del enum, para que dos ejecuciones den el mismo JSON.
            $marcadas = array_flip($bloque['audiencias']);
            $bloque['audiencias'] = array_values(array_filter(
                AudienciaDetalleEnum::valores(),
                static fn (string $v): bool => isset($marcadas[$v]),
            ));

            $salida[] = $bloque;
        }

        return [$salida, $fusiones];
    }

    /** @param array<string, mixed> $bloque */
    private function textoEnEspanol(array $bloque): string
    {
        $traducciones = is_array($bloque['detalle'] ?? null) ? $bloque['detalle'] : [];

        foreach ($traducciones as $traduccion) {
            if (is_array($traduccion) && ($traduccion['language'] ?? null) === 'es' && is_string($traduccion['content'] ?? null)) {
                return trim($traduccion['content']);
            }
        }

        // Sin español no hay original con el que comparar: que cada bloque sea único es lo seguro.
        return '__sin_es__'.json_encode($traducciones);
    }
}
