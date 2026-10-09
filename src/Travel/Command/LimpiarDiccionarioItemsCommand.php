<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponenteItem;
use App\Travel\Entity\TravelItemDiccionario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La deuda del diccionario de ítems, encontrada al revisarlo entero el 08/10/2026.
 *
 * El diccionario es **vocabulario controlado y compartido**: 96 términos de los que cuelga el
 * «qué incluye» de todo el catálogo. Un término roto no rompe nada —por eso llevaba ahí— pero
 * se lee en la cotización de cada producto que lo use.
 *
 * ── Qué arregla, y en tres clases distintas ─────────────────────────────────
 *
 * **Fusiones.** Dos términos que dicen lo mismo. Se reapunta lo que cuelga del sobrante y se
 * borra. `Transporte Cusco de Action a Valley` no es un término: son las palabras de
 * `Transporte de Cusco a Action Valley` cambiadas de sitio, y lo delata el uso — los tres
 * productos de Action Valley llevan ida y vuelta, y sólo el Bungee Jumping tiene la rota.
 *
 * **Erratas.** Tildes que faltan y una letra bailada. Van por el ORM y con la sobrescritura
 * activada, porque si no el listener respeta la traducción vieja y los otros seis idiomas se
 * quedan con la errata — la trampa de `docs/TravelCargaDeCatalogo.md` §7.
 *
 * **Títulos.** Dos términos cuyo texto público no corresponde a lo que son.
 * `Almuerzo (Dia 3)` lo tenía vacío —el único de 96 sin traducir— y `Almuerzo (Dia 5)` decía
 * «Cena (Dia 5)», que es peor: no falta, miente.
 *
 * ⚠️ **Lo que NO toca, a propósito:**
 *
 *   `Ticket de ingreso` / `Tickets de ingreso`   no son duplicados: uno y varios
 *   `Transporte from Hidroeléctrica to Cusco`    está en inglés, pero decidirlo es redacción
 *   `Bus Cusco 180ª`                             puede ser 180° y puede no serlo
 *   los 11 términos `Comida (Dia N)`             es un patrón a discutir, no una errata
 */
#[AsCommand(
    name: 'app:travel:limpiar-diccionario-items',
    description: 'Fusiona términos repetidos del diccionario de ítems y corrige erratas y títulos.',
    hidden: true,
)]
final class LimpiarDiccionarioItemsCommand extends Command
{
    /**
     * Sobrante → el que se queda. Lo que cuelga del primero pasa al segundo.
     *
     * ⚠️ Se conserva **«Carpa Comedor»**, el genérico, y no el descriptivo: «con sillas y mesas»
     * es cierto en el Camino Inca, que es quien lo escribió, pero afirmarlo también del
     * Salkantay sería prometer en su nombre algo que nadie ha comprobado. Perder detalle es
     * más barato que prometer de más.
     *
     * @var array<string, string>
     */
    private const FUSIONES = [
        'Transporte Cusco de Action a Valley' => 'Transporte de Cusco a Action Valley',
        'Carpa comedor con sillas y mesas' => 'Carpa Comedor',
    ];

    /**
     * Erratas. Clave: el nombre de hoy. Valor: cómo debe decir.
     *
     * @var array<string, string>
     */
    private const ERRATAS = [
        'Botiquin' => 'Botiquín',
        'Degustacion de tejas y chocotejas' => 'Degustación de tejas y chocotejas',
        'Degustacion de vinos y piscos' => 'Degustación de vinos y piscos',
        'Wifi a bordo (solo en lugares de señal de datos) y cargador de ceular'
            => 'Wifi a bordo (solo en lugares con señal de datos) y cargador de celular',
    ];

    /**
     * Títulos públicos que no corresponden al término. El nombre interno no se toca.
     *
     * @var array<string, string>
     */
    private const TITULOS = [
        'Almuerzo (Dia 3)' => 'Almuerzo (Dia 3)',
        'Almuerzo (Dia 5)' => 'Almuerzo (Dia 5)',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $io->title('Diccionario de ítems');

        $io->section('Fusiones');
        $fusionados = 0;

        foreach (self::FUSIONES as $sobra => $queda) {
            $origen = $this->termino($sobra);
            $destino = $this->termino($queda);

            if ($origen === null) {
                $io->text(sprintf('  nada que hacer · «%s» ya no está', $sobra));
                continue;
            }

            if ($destino === null) {
                $io->error(sprintf('No existe el término destino «%s».', $queda));

                return Command::FAILURE;
            }

            /** @var list<TravelComponenteItem> $items */
            $items = $this->em->getRepository(TravelComponenteItem::class)
                ->findBy(['diccionario' => $origen]);

            foreach ($items as $item) {
                $componente = $item->getComponente();

                // Si el componente ya tiene el término bueno, reapuntar crearía la pareja dos
                // veces: lo que sobra es la FILA, no el apunte.
                $repetido = $componente !== null && $this->em->getRepository(TravelComponenteItem::class)
                    ->findOneBy(['componente' => $componente, 'diccionario' => $destino]) !== null;

                $io->text(sprintf(
                    '  %s · %-36s %s en «%s»',
                    $simula ? 'haría  ' : 'hecho  ',
                    $sobra,
                    $repetido ? 'ya lo tiene → borra la fila' : '→ ' . $queda,
                    $componente?->getNombreInterno() ?? '?',
                ));

                if ($simula) {
                    continue;
                }

                if ($repetido) {
                    $this->em->remove($item);
                } else {
                    $item->setDiccionario($destino);
                }
            }

            ++$fusionados;
            $io->text(sprintf('  %s · borra el término «%s»', $simula ? 'haría  ' : 'hecho  ', $sobra));

            if (!$simula) {
                $this->em->flush();
                $this->em->remove($origen);
                $this->em->flush();
            }
        }

        $io->section('Erratas');
        $corregidas = 0;

        foreach (self::ERRATAS as $viejo => $nuevo) {
            $termino = $this->termino($viejo);

            if ($termino === null) {
                $io->text(sprintf('  nada que hacer · «%s» ya no está', $viejo));
                continue;
            }

            // ⚠️ `findOneBy` NO basta para saber si ya está corregido: la columna va en
            // `utf8mb4_unicode_ci`, que **ignora las tildes**, así que para MySQL «Botiquin» y
            // «Botiquín» son la misma cadena y el término se encuentra con el nombre viejo
            // aunque ya se haya arreglado. La comparación que decide tiene que hacerse en PHP,
            // que sí distingue — si no, cada pasada vuelve a escribir y a disparar las siete
            // traducciones.
            if ($termino->getNombreInterno() === $nuevo) {
                $io->text(sprintf('  ya está  · %s', $nuevo));
                continue;
            }

            ++$corregidas;
            $io->text(sprintf('  %s · %s → %s', $simula ? 'haría  ' : 'hecho  ', $viejo, $nuevo));

            if ($simula) {
                continue;
            }

            // ⚠️ Sin esto el listener respeta lo ya traducido y la errata sobrevive en los otros
            // seis idiomas, que es exactamente el fallo que describe §7 del doc de carga.
            $termino->setSobreescribirTraduccion(true);
            $termino->setNombreInterno($nuevo);
            $termino->setTitulo([['language' => 'es', 'content' => $nuevo]]);
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->section('Títulos');
        $titulados = 0;

        foreach (self::TITULOS as $nombre => $titulo) {
            $termino = $this->termino($nombre);

            if ($termino === null) {
                $io->text(sprintf('  no existe · %s', $nombre));
                continue;
            }

            $actual = $termino->getTitulo()[0]['content'] ?? '';

            if ($actual === $titulo) {
                $io->text(sprintf('  ya está  · %s', $nombre));
                continue;
            }

            ++$titulados;
            $io->text(sprintf(
                '  %s · %-18s título: «%s» → «%s»',
                $simula ? 'haría  ' : 'hecho  ',
                $nombre,
                $actual === '' ? '(vacío)' : $actual,
                $titulo,
            ));

            if ($simula) {
                continue;
            }

            $termino->setSobreescribirTraduccion(true);
            $termino->setTitulo([['language' => 'es', 'content' => $titulo]]);
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%s %d fusión(es), %d errata(s) y %d título(s).',
            $simula ? 'Se harían' : 'Hechas',
            $fusionados,
            $corregidas,
            $titulados,
        ));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    private function termino(string $nombre): ?TravelItemDiccionario
    {
        return $this->em->getRepository(TravelItemDiccionario::class)
            ->findOneBy(['nombreInterno' => $nombre]);
    }
}
