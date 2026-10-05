<?php

declare(strict_types=1);

namespace App\Service\Translate\Command;

use App\Attribute\AutoTranslate;
use App\Service\Translate\AutoTranslationService;
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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AutoTranslationService $traductor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('clase', null, InputOption::VALUE_REQUIRED, 'Sólo las entidades cuyo nombre contenga este texto.')
            ->addOption('corregir', null, InputOption::VALUE_NONE, 'Rehace las traducciones de las señaladas. Sin esto, sólo informa.')
            ->addOption('minimo-palabras', null, InputOption::VALUE_REQUIRED, 'Palabras mínimas de la traducción para considerarla sospechosa.', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filtro = $input->getOption('clase');
        $filtro = \is_string($filtro) ? $filtro : null;
        $corregir = (bool) $input->getOption('corregir');
        $crudo = $input->getOption('minimo-palabras');
        $minimo = max(1, \is_numeric($crudo) ? (int) $crudo : 4);

        /** @var array<string, list<array{entidad: object, es: string, texto: string}>> $porTraduccion */
        $porTraduccion = [];
        /** @var array<int, object> $desfasadas */
        $desfasadas = [];
        /** @var list<string> $detalleDesfase */
        $detalleDesfase = [];
        $revisados = 0;
        $colapsos = 0;
        $variantes = 0;
        $reformulaciones = 0;

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $clase = $meta->getName();

            // ⚠️ El filtro NO se aplica aquí: siempre se escanea TODO. Un clon se detecta por
            // su gemelo, y filtrar el escaneo haría desaparecer los pares cruzados —el gemelo de
            // un `TravelComponente` puede ser una `Cotizacion`— sin decir nada. `--clase` acota
            // lo que se CORRIGE, que es lo que se quiere acotar.
            if ($meta->isMappedSuperclass) {
                continue;
            }

            $propiedades = $this->propiedadesTraducibles(new ReflectionClass($clase));

            if ($propiedades === []) {
                continue;
            }

            foreach ($this->em->getRepository($clase)->findAll() as $entidad) {
                foreach ($propiedades as [$prop, $attr]) {
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

                        // ── Detector 2: la HUELLA no cuadra con su español ──
                        //
                        // Exacto, no heurístico: es el mismo criterio que usa el servicio para
                        // decidir que una fila está desfasada. Caza lo que el detector de
                        // duplicados NO puede ver —el clon cuyo gemelo ya cambió, que se queda
                        // huérfano y por tanto único—, y fue así como apareció el snapshot de
                        // «Ingreso a Quelccaya» con el texto de Vinicunca dentro.
                        //
                        // 'manual' se respeta: es el blindaje documentado en §10 para una
                        // traducción escrita a mano que nadie debe rehacer.
                        $huella = $fila['origenHash'] ?? null;

                        if ($huella !== null && $huella !== 'manual'
                            && $huella !== $this->huellaDe($es, $attr->getFormat())) {
                            $desfasadas[spl_object_id($entidad)] = $entidad;
                            $detalleDesfase[] = sprintf(
                                '%-30s %-3s %s',
                                $this->nombre($entidad),
                                $idioma,
                                mb_substr($es, 0, 44)
                            );
                        }
                    }
                }
            }
        }

        // ── Detector 1: misma traducción, distinto español ──────────────────
        $clones = [];

        foreach ($porTraduccion as $clave => $filas) {
            // ⚠️ El español se compara APLANADO. Tercera medición contra producción: de 83
            // señaladas, casi todas eran el mismo texto escrito de dos maneras —«Excursion» y
            // «Excursión», «Noche y actividades» y «Noche y Actividades»—, que por supuesto
            // traducen igual. Comparar en crudo convierte una errata de tecleo en un falso clon.
            $espanoles = array_values(array_unique(array_map(
                fn (array $f): string => $this->plano($f['es']),
                $filas
            )));

            if (\count($espanoles) < 2) {
                ++$variantes;
                continue;
            }

            // ⚠️ Cuarta medición: los que quedaban eran el mismo texto dicho LARGO y CORTO
            // —«Vuelo desde la ciudad de Cusco a la ciudad de Lima» y «Vuelo de Cusco a Lima»—,
            // que por supuesto dan el mismo neerlandés. Eso no es un clon: es que el idioma
            // destino no arrastra la verbosidad del español.
            //
            // Un clon se delata porque su español no se parece a nada: «Convento de San
            // Francisco» contra «degustación de Pisco Sour», compartiendo el mismo neerlandés.
            // Así que sólo cuenta si ALGÚN par comparte poco vocabulario.
            if (!$this->algunParEsAjeno($espanoles)) {
                ++$reformulaciones;
                continue;
            }

            // Una etiqueta corta colapsa al traducirse y eso es correcto. Sólo una FRASE
            // repetida carácter a carácter delata un clon. Ver el docblock.
            if ($this->palabras($filas[0]['texto']) < $minimo) {
                ++$colapsos;
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

        if ($desfasadas !== []) {
            $io->section(sprintf('%d entidad(es) con la huella desfasada de su español', \count($desfasadas)));
            foreach (\array_slice($detalleDesfase, 0, 30) as $linea) {
                $io->text('  ' . $linea);
            }
            if (\count($detalleDesfase) > 30) {
                $io->text(sprintf('  … y %d línea(s) más', \count($detalleDesfase) - 30));
            }
        }

        $io->newLine();
        $io->table(
            ['revisados', 'duplicadas', 'huella desfasada', sprintf('colapsos (<%d pal.)', $minimo), 'otra grafía', 'reformulado'],
            [[$revisados, \count($clones), \count($desfasadas), $colapsos, $variantes, $reformulaciones]]
        );

        if (!$corregir) {
            $io->note('Sólo informe. Con --corregir se rehacen las traducciones de las señaladas.');

            return Command::SUCCESS;
        }

        $candidatas = $clones + $desfasadas;

        $aRehacer = $filtro === null
            ? $candidatas
            : array_filter($candidatas, static fn (object $e): bool => str_contains($e::class, $filtro));

        if ($filtro !== null) {
            $io->note(sprintf('Se detectó sobre todo el catálogo; se corrigen %d de %d por --clase=%s.',
                \count($aRehacer), \count($candidatas), $filtro));
        }

        foreach ($aRehacer as $entidad) {
            if (method_exists($entidad, 'setSobreescribirTraduccion')) {
                $entidad->setSobreescribirTraduccion(true);
            }
        }

        // El recuento es de ESTA tanda, no de lo que llevara el proceso encima.
        $this->traductor->olvidarFallos();
        $this->em->flush();
        $fallos = $this->traductor->fallos();

        if ($fallos === []) {
            $io->success(sprintf('%d entidad(es) retraducidas.', \count($aRehacer)));

            return Command::SUCCESS;
        }

        // ⚠️ Sin esto el comando se despedía con «[OK] 34 retraducidas» teniendo un NOT_FOUND de
        // Google dentro: el error quedaba en error.log y la consola decía que todo fue bien.
        // Un idioma que no se tradujo conserva el texto ANTERIOR, que es justo el que se quería
        // cambiar — así que dar la pasada por buena deja el clon vivo y a nadie mirándolo.
        $io->warning(sprintf(
            '%d entidad(es) procesadas, pero %d traducción(es) FALLARON y conservan el texto anterior.',
            \count($aRehacer),
            \count($fallos)
        ));

        foreach ($fallos as $fallo) {
            $io->text('  · ' . $fallo);
        }

        $io->text('Vuelve a correr el comando cuando esté resuelto: sólo tocará lo que siga señalado.');

        return Command::FAILURE;
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
     * @return list<array{ReflectionProperty, AutoTranslate}>
     */
    private function propiedadesTraducibles(ReflectionClass $rc): array
    {
        $salida = [];

        foreach ($rc->getProperties() as $prop) {
            foreach ($prop->getAttributes(AutoTranslate::class) as $attr) {
                $instancia = $attr->newInstance();

                if ($instancia->nestedFields === []) {
                    $prop->setAccessible(true);
                    $salida[] = [$prop, $instancia];
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
     * ¿Hay algún par de españoles que no se parezcan en nada?
     *
     * Dos redacciones del mismo servicio comparten casi todo el vocabulario largo; un clon no
     * comparte nada. El umbral es la mitad del texto más corto: por debajo, hablan de cosas
     * distintas y comparten traducción, que es imposible salvo que una esté copiada.
     *
     * @param list<string> $espanoles
     */
    private function algunParEsAjeno(array $espanoles): bool
    {
        $vocabularios = array_map(fn (string $t): array => $this->vocabulario($t), $espanoles);
        $n = \count($vocabularios);

        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                $menor = min(\count($vocabularios[$i]), \count($vocabularios[$j]));

                if ($menor === 0) {
                    continue;
                }

                $comunes = \count(array_intersect($vocabularios[$i], $vocabularios[$j]));

                if ($comunes / $menor < 0.5) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Las palabras de 4+ letras, sin etiquetas HTML: parte del contenido viene en `<p>` con
     * atributos, y comparar el marcado haría que dos párrafos cualesquiera se parecieran.
     *
     * @return list<string>
     */
    private function vocabulario(string $texto): array
    {
        $limpio = $this->plano(strip_tags($texto));
        preg_match_all('/[\p{L}\p{N}]{4,}/u', $limpio, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * La huella del texto de origen. **Espejo de `AutoTranslationService::hashDeOrigen()`** — si
     * cambia allí, cambia aquí, o este detector señalaría el catálogo entero.
     */
    private function huellaDe(string $texto, string $mime): string
    {
        return sha1($mime . '|' . trim((string) preg_replace('/\s+/u', ' ', $texto)));
    }

    /** Minúsculas, sin tildes y con los espacios colapsados. */
    private function plano(string $texto): string
    {
        $limpio = mb_strtolower(trim($texto));
        $limpio = strtr($limpio, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return (string) preg_replace('/\s+/', ' ', $limpio);
    }

    /** Palabras de la traducción: el umbral que separa una frase de una etiqueta. */
    private function palabras(string $texto): int
    {
        return preg_match_all('/[\p{L}\p{N}]+/u', $texto) ?: 0;
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
