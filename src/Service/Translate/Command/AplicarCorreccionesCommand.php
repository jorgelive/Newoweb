<?php

declare(strict_types=1);

namespace App\Service\Translate\Command;

use App\Attribute\AutoTranslate;
use App\Service\Translate\CorreccionesDeTraduccion;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pasa {@see CorreccionesDeTraduccion} —las frases corregidas y los rótulos fijos— por lo que ya
 * está traducido y guardado.
 *
 * Una traducción nueva sale corregida sola; ésta es la vía para lo que se tradujo ANTES de que
 * existiera la corrección. Se relanza cada vez que se añade una.
 *
 * - **No toca el `origenHash`**: la fila sigue correspondiendo a su español, sólo dice mejor lo
 *   mismo. Y apaga `ejecutarTraduccion`, para que el listener no se meta en el flush.
 * - **Respeta el veto** (`preventOverwriteIf`): una plantilla aprobada por Meta no se reescribe,
 *   o dejaría de coincidir con el texto que Meta aprobó.
 * - **Sólo campos planos** (`[{language, content}]`). Los anidados son las plantillas de WhatsApp,
 *   que van vetadas de todos modos.
 *
 * Al final lista las **huérfanas**: correcciones que no casan con nada guardado, ni con su frase
 * mala ni con la buena. Suele ser que Google cambió la traducción y la corrección ya no la
 * encuentra: el error puede haber vuelto con otras palabras.
 */
#[AsCommand(
    name: 'app:traduccion:corregir',
    description: 'Aplica las correcciones de traducción a lo ya guardado y lista las que no casan con nada.',
)]
final class AplicarCorreccionesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CorreccionesDeTraduccion $correcciones,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Cuenta lo que corregiría y no guarda nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $todas = $this->correcciones->todas();
        /** @var array<string, true> $vistas */
        $vistas = [];
        /** @var array<string, int> $porClase */
        $porClase = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass) {
                continue;
            }

            $clase = $meta->getName();
            /** @var list<array{\ReflectionProperty, AutoTranslate}> $propiedades */
            $propiedades = [];
            foreach ((new ReflectionClass($clase))->getProperties() as $prop) {
                $attr = ($prop->getAttributes(AutoTranslate::class)[0] ?? null)?->newInstance();
                if ($attr instanceof AutoTranslate && $attr->nestedFields === []) {
                    $propiedades[] = [$prop, $attr];
                }
            }

            if ($propiedades === []) {
                continue;
            }

            foreach ($this->em->getRepository($clase)->findAll() as $entidad) {
                $tocada = false;

                foreach ($propiedades as [$prop, $attr]) {
                    $veto = $attr->preventOverwriteIf;
                    if ($veto !== null && method_exists($entidad, $veto) && $entidad->$veto() === true) {
                        continue;
                    }

                    $filas = $prop->getValue($entidad);
                    if (!\is_array($filas)) {
                        continue;
                    }

                    $origen = '';
                    foreach ($filas as $fila) {
                        if (\is_array($fila) && ($fila['language'] ?? null) === 'es' && \is_string($fila['content'] ?? null)) {
                            $origen = $fila['content'];
                        }
                    }

                    $cambio = false;
                    foreach ($filas as $i => $fila) {
                        if (!\is_array($fila)) {
                            continue;
                        }

                        $idioma = \is_string($fila['language'] ?? null) ? $fila['language'] : '';
                        $texto = \is_string($fila['content'] ?? null) ? $fila['content'] : null;
                        if ($texto === null || $idioma === 'es') {
                            continue;
                        }

                        // Un rótulo corto con traducción fija se pone tal cual, diga lo que diga.
                        $fija = $origen !== '' ? $this->correcciones->fija($origen, $idioma) : null;
                        if ($fija !== null) {
                            if ($fija !== $texto) {
                                $fila['content'] = $fija;
                                $filas[$i] = $fila;
                                $cambio = true;
                                $porClase[$clase] = ($porClase[$clase] ?? 0) + 1;
                            }

                            continue;
                        }

                        if (!isset($todas[$idioma])) {
                            continue;
                        }

                        foreach ($todas[$idioma] as $buscar => $reemplazo) {
                            if ($this->correcciones->casaCon($buscar, $reemplazo, $texto)) {
                                $vistas[$idioma . "\0" . $buscar] = true;
                            }
                        }

                        $nuevo = $this->correcciones->aplicar($idioma, $texto);
                        if ($nuevo !== $texto) {
                            $fila['content'] = $nuevo;
                            $filas[$i] = $fila;
                            $cambio = true;
                            $porClase[$clase] = ($porClase[$clase] ?? 0) + 1;
                        }
                    }

                    if ($cambio && !$seco) {
                        $prop->setValue($entidad, $filas);
                        $tocada = true;
                    }
                }

                if ($tocada && method_exists($entidad, 'setEjecutarTraduccion')) {
                    $entidad->setEjecutarTraduccion(false);
                }
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        if ($porClase !== []) {
            $io->table(['Entidad', 'Filas corregidas'], array_map(
                static fn (string $c, int $n): array => [substr((string) strrchr('\\' . $c, '\\'), 1), (string) $n],
                array_keys($porClase),
                $porClase,
            ));
        }

        $huerfanas = [];
        foreach ($todas as $idioma => $pares) {
            foreach (array_keys($pares) as $buscar) {
                if (!isset($vistas[$idioma . "\0" . $buscar])) {
                    $huerfanas[] = sprintf('[%s] %s', $idioma, $buscar);
                }
            }
        }

        if ($huerfanas !== []) {
            $io->warning(array_merge(['Correcciones que no casan con nada guardado (¿cambió la traducción?):'], $huerfanas));
        }

        $io->success(sprintf('%d fila(s) %s.', array_sum($porClase), $seco ? 'se corregirían' : 'corregidas'));

        return $huerfanas === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
