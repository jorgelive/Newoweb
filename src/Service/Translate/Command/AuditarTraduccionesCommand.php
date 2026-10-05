<?php

declare(strict_types=1);

namespace App\Service\Translate\Command;

use App\Attribute\AutoTranslate;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Encuentra traducciones que **no se corresponden con su español**, y opcionalmente las rehace.
 *
 * ## El fallo que persigue
 *
 * `TravelComponente::__clone()` —y sus hermanos— copian el campo i18n **entero**. Quien clona una
 * ficha y reescribe el español se queda con seis traducciones hablando de otra cosa. El caso que
 * lo destapó: el componente «Ingreso a Quelccaya» tenía, en los seis idiomas, el texto del boleto
 * de **Vinicunca** («Entrance ticket to the Rainbow Mountain»).
 *
 * ⚠️ **Y el `origenHash` no lo caza**, porque el origenHash llegó el 31/08/2026 (`18466bab`) y la
 * ficha se creó el 20/07. Sin huella, el listener no tenía con qué notar el desfase; y una vez
 * sellada, el sistema la da por buena para siempre. Ver la advertencia de
 * {@see SellarHashOrigenCommand}: sellar es declarar que lo que hay es correcto.
 *
 * ## Por qué el detector es EXACTO y no una heurística
 *
 * Un clon deja una firma inconfundible: **dos entidades distintas con la traducción idéntica
 * carácter a carácter y el español distinto**. No hace falta entender los idiomas ni comparar
 * palabras: si «Entrance ticket to the Rainbow Mountain» es el inglés de dos fichas cuyo español
 * no coincide, una de las dos miente. Es comparación de cadenas, no adivinación.
 *
 * El segundo detector sí es una pista y se marca como tal: español y traducción que **no comparten
 * ni un número ni una palabra larga**. Caza el clon huérfano —aquel cuyo gemelo ya se borró— y
 * tiene falsos positivos (un título corto bien traducido no comparte nada). Por eso se listan
 * aparte y `--corregir` **no los toca** salvo que se pida `--incluir-sospechosas`.
 *
 * ## Corregir no es desellar
 *
 * Desellar deja la ficha «pendiente de retraducir la próxima vez que alguien la guarde», y nadie
 * va a entrar una por una. `--corregir` enciende `sobreescribirTraduccion` en las señaladas, que
 * rehace sus idiomas **ahora**, en esta ejecución, y sólo en ésas.
 */
#[AsCommand(
    name: 'app:traduccion:auditar',
    description: 'Busca traducciones que no se corresponden con su español (clones) y puede rehacerlas.',
)]
final class AuditarTraduccionesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('clase', null, InputOption::VALUE_REQUIRED, 'Sólo las entidades cuyo nombre contenga este texto.')
            ->addOption('corregir', null, InputOption::VALUE_NONE, 'Rehace las traducciones de las señaladas. Sin esto, sólo informa.')
            ->addOption('incluir-sospechosas', null, InputOption::VALUE_NONE, 'Corrige también las del segundo detector, el de pistas.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filtro = $input->getOption('clase');
        $filtro = \is_string($filtro) ? $filtro : null;
        $corregir = (bool) $input->getOption('corregir');
        $tambienSospechosas = (bool) $input->getOption('incluir-sospechosas');

        /** @var array<string, list<array{entidad: object, es: string, texto: string}>> $porTraduccion */
        $porTraduccion = [];
        /** @var list<array{entidad: object, campo: string, es: string, idioma: string, texto: string}> $sospechosas */
        $sospechosas = [];
        $revisados = 0;

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $clase = $meta->getName();

            if ($meta->isMappedSuperclass || ($filtro !== null && !str_contains($clase, $filtro))) {
                continue;
            }

            $propiedades = $this->propiedadesTraducibles(new ReflectionClass($clase));

            if ($propiedades === []) {
                continue;
            }

            foreach ($this->em->getRepository($clase)->findAll() as $entidad) {
                foreach ($propiedades as $prop) {
                    $valor = $prop->getValue($entidad);

                    if (!\is_array($valor)) {
                        continue;
                    }

                    ++$revisados;
                    $es = $this->contenido($valor, 'es');

                    if ($es === '') {
                        continue;
                    }

                    foreach ($valor as $fila) {
                        if (!\is_array($fila)) {
                            continue;
                        }

                        $idioma = \is_string($fila['language'] ?? null) ? $fila['language'] : '';
                        $texto = \is_string($fila['content'] ?? null) ? trim($fila['content']) : '';

                        if ($idioma === '' || $idioma === 'es' || $texto === '') {
                            continue;
                        }

                        $porTraduccion[$prop->getName() . '|' . $idioma . '|' . $texto][] = [
                            'entidad' => $entidad, 'es' => $es, 'texto' => $texto,
                        ];

                        if (!$this->compartenAlgo($es, $texto)) {
                            $sospechosas[] = [
                                'entidad' => $entidad, 'campo' => $prop->getName(),
                                'es' => $es, 'idioma' => $idioma, 'texto' => $texto,
                            ];
                        }
                    }
                }
            }
        }

        // ── Detector 1: misma traducción, distinto español ──────────────────
        $clones = [];

        foreach ($porTraduccion as $clave => $filas) {
            $espanoles = array_unique(array_column($filas, 'es'));

            if (\count($espanoles) < 2) {
                continue;
            }

            [$campo, $idioma] = explode('|', $clave);
            $io->section(sprintf('%s · %s — misma traducción, %d españoles distintos', $campo, $idioma, \count($espanoles)));
            $io->text(sprintf('  «%s»', $filas[0]['texto']));

            foreach ($filas as $fila) {
                $io->text(sprintf('    ← %-30s %s', $this->nombre($fila['entidad']), $fila['es']));
                $clones[spl_object_id($fila['entidad'])] = $fila['entidad'];
            }
        }

        $io->newLine();
        $io->table(
            ['campos revisados', 'entidades con traducción duplicada', 'sospechosas (pista)'],
            [[$revisados, \count($clones), \count($sospechosas)]]
        );

        if ($sospechosas !== []) {
            $io->section('Pista: el español y la traducción no comparten ni una palabra larga ni un número');
            foreach (\array_slice($sospechosas, 0, 25) as $s) {
                $io->text(sprintf('  %-26s %-3s %-42s │ %s', $this->nombre($s['entidad']), $s['idioma'], $s['es'], $s['texto']));
            }
            if (\count($sospechosas) > 25) {
                $io->text(sprintf('  … y %d más', \count($sospechosas) - 25));
            }
        }

        if (!$corregir) {
            $io->note('Sólo informe. Con --corregir se rehacen las traducciones de las señaladas.');

            return Command::SUCCESS;
        }

        $aRehacer = $clones;

        if ($tambienSospechosas) {
            foreach ($sospechosas as $s) {
                $aRehacer[spl_object_id($s['entidad'])] = $s['entidad'];
            }
        }

        foreach ($aRehacer as $entidad) {
            if (method_exists($entidad, 'setSobreescribirTraduccion')) {
                $entidad->setSobreescribirTraduccion(true);
            }
        }

        $this->em->flush();
        $io->success(sprintf('%d entidad(es) retraducidas.', \count($aRehacer)));

        return Command::SUCCESS;
    }

    /**
     * Las propiedades con `#[AutoTranslate]` de estructura PLANA.
     *
     * Las anidadas (`nestedFields`) se saltan a propósito: su forma la decide cada entidad y
     * recorrerlas a ciegas aquí duplicaría la lógica del servicio de traducción, que es justo lo
     * que este proyecto paga cada vez que lo hace.
     *
     * @param ReflectionClass<object> $rc
     *
     * @return list<ReflectionProperty>
     */
    private function propiedadesTraducibles(ReflectionClass $rc): array
    {
        $salida = [];

        foreach ($rc->getProperties() as $prop) {
            foreach ($prop->getAttributes(AutoTranslate::class) as $attr) {
                if ($attr->newInstance()->nestedFields === []) {
                    $prop->setAccessible(true);
                    $salida[] = $prop;
                }
            }
        }

        return $salida;
    }

    /** @param array<mixed> $i18n */
    private function contenido(array $i18n, string $idioma): string
    {
        foreach ($i18n as $fila) {
            if (\is_array($fila) && ($fila['language'] ?? null) === $idioma && \is_string($fila['content'] ?? null)) {
                return trim($fila['content']);
            }
        }

        return '';
    }

    /**
     * ¿Comparten un número o una palabra de 4+ letras? Es la pista, no la prueba: los nombres
     * propios y las cifras sobreviven a cualquier traducción, así que no compartir ninguno es
     * raro — pero un título corto bien traducido tampoco comparte nada.
     */
    private function compartenAlgo(string $a, string $b): bool
    {
        $tokens = static function (string $t): array {
            $t = mb_strtolower($t);
            preg_match_all('/[\p{L}\p{N}]{4,}/u', $t, $m);

            return array_unique($m[0]);
        };

        return array_intersect($tokens($a), $tokens($b)) !== [];
    }

    /** El id puede ser un Uuid, un int o null: aquí sólo se quiere algo que se lea. */
    private function comoTexto(mixed $valor): string
    {
        return \is_scalar($valor) || $valor instanceof \Stringable ? (string) $valor : '?';
    }

    private function nombre(object $entidad): string
    {
        $corto = (new ReflectionClass($entidad))->getShortName();
        $id = method_exists($entidad, 'getId') ? $this->comoTexto($entidad->getId()) : '?';

        return $corto . ' ' . substr($id, 0, 8);
    }
}
