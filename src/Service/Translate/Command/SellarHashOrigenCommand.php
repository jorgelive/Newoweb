<?php

declare(strict_types=1);

namespace App\Service\Translate\Command;

use App\Attribute\AutoTranslate;
use App\Service\Translate\AutoTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Estampa el `origenHash` en el contenido que ya estaba traducido, **sin traducir nada**.
 *
 * ### Para qué
 *
 * Desde el 31/08/2026 cada fila traducida lleva la huella del español del que salió, y se rehace
 * sola cuando esa huella deja de cuadrar. El contenido anterior no tiene huella, así que sin este
 * comando el primer guardado de cada entidad retraduciría los seis idiomas — todo el histórico de
 * 25 entidades, de golpe y contra la API de Google.
 *
 * ### ⚠️ Sellar es DECLARAR QUE LO QUE HAY ES CORRECTO
 *
 * Y por eso `--clase` no es una comodidad, es el mecanismo. Una traducción que ya estaba
 * desfasada —de las que decían lo contrario que el español, que en este proyecto han existido—
 * queda **congelada**: el sistema la dará por buena y no la rehará nunca.
 *
 * Se sella módulo a módulo, sellando sólo aquello cuyo contenido te crees. Lo que no selles se
 * retraduce solo la primera vez que se guarde, que es exactamente lo que quieres para el
 * contenido del que dudas.
 *
 * ### Cómo evita traducir
 *
 * Dos cinturones: el modo `soloSellar` del servicio no llama al traductor, y además se apaga
 * `ejecutarTraduccion` en cada entidad, de modo que el listener de Doctrine sale antes de mirar
 * nada cuando llegue el flush. Aunque el primero fallara, el segundo lo impide.
 *
 * ### Por qué descubre las entidades por el atributo
 *
 * Una lista escrita a mano se pudre el día que alguien añada una entidad traducible, y el síntoma
 * sería mudo: esa entidad no se sella y se retraduce entera sin que nadie lo pida. Se recorre la
 * metadata de Doctrine y se reflexiona buscando {@see AutoTranslate}, que es la misma fuente que
 * usa el servicio.
 */
#[AsCommand(
    name: 'app:traduccion:sellar-hash',
    description: 'Estampa el origenHash en las traducciones ya existentes, sin traducir nada.'
)]
final class SellarHashOrigenCommand extends Command
{
    /** Cada cuántas entidades se vacía el lote. */
    private const int TAMANO_LOTE = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AutoTranslationService $autoTranslationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Cuenta lo que sellaría y no guarda nada.')
            ->addOption('clase', null, InputOption::VALUE_REQUIRED, 'Sella sólo las entidades cuyo nombre contenga este texto (ej. PmsGuia).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $filtro = $input->getOption('clase');
        $filtro = is_string($filtro) && $filtro !== '' ? $filtro : null;

        $clases = $this->clasesTraducibles($filtro);

        if ($clases === []) {
            $io->warning($filtro !== null
                ? sprintf('Ninguna entidad traducible coincide con "%s".', $filtro)
                : 'Ninguna entidad lleva #[AutoTranslate].');

            return Command::SUCCESS;
        }

        $io->title($dryRun ? 'Sellado de traducciones (simulacro)' : 'Sellado de traducciones');
        $io->text(sprintf('%d entidades traducibles%s.', count($clases), $filtro !== null ? sprintf(' (filtro "%s")', $filtro) : ''));

        $filas = [];
        $totalSelladas = 0;

        foreach ($clases as $clase) {
            $selladas = $this->sellarClase($clase, $dryRun);
            $totalSelladas += $selladas;
            $filas[] = [$this->nombreCorto($clase), $selladas === 0 ? '—' : (string) $selladas];
        }

        $io->table(['Entidad', 'Filas selladas'], $filas);

        if ($dryRun) {
            $io->note(sprintf('Simulacro: no se ha guardado nada. Se sellarían %d entidades.', $totalSelladas));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d entidades selladas.', $totalSelladas));
        $io->text('Lo que NO se selló se retraducirá solo la primera vez que se guarde.');

        return Command::SUCCESS;
    }

    /**
     * Las clases de entidad con al menos una propiedad `#[AutoTranslate]`.
     *
     * @return list<class-string>
     */
    private function clasesTraducibles(?string $filtro): array
    {
        $clases = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $clase = $meta->getName();

            if ($meta->isMappedSuperclass || $filtro !== null && !str_contains($clase, $filtro)) {
                continue;
            }

            foreach ((new ReflectionClass($clase))->getProperties() as $propiedad) {
                if ($propiedad->getAttributes(AutoTranslate::class) !== []) {
                    $clases[] = $clase;
                    break;
                }
            }
        }

        sort($clases);

        return $clases;
    }

    /**
     * Sella una clase entera por lotes y devuelve cuántas entidades cambiaron.
     *
     * @param class-string $clase
     */
    private function sellarClase(string $clase, bool $dryRun): int
    {
        $selladas = 0;
        $enLote = 0;

        /** @var iterable<object> $entidades */
        $entidades = $this->em->createQuery(sprintf('SELECT e FROM %s e', $clase))->toIterable();

        foreach ($entidades as $entidad) {
            $antes = $this->huella($entidad);

            // El segundo cinturón: con esto apagado el listener sale antes de nada en el flush.
            if (method_exists($entidad, 'setEjecutarTraduccion')) {
                $entidad->setEjecutarTraduccion(false);
            }

            $this->autoTranslationService->processEntity($entidad, forceExecution: true, soloSellar: true);

            if ($this->huella($entidad) === $antes) {
                continue;
            }

            ++$selladas;
            ++$enLote;

            if (!$dryRun && $enLote >= self::TAMANO_LOTE) {
                $this->em->flush();
                $enLote = 0;
            }
        }

        if ($dryRun) {
            // Se descarta todo lo tocado en memoria: el simulacro no debe dejar rastro ni
            // siquiera si alguien hace un flush más adelante en el mismo proceso.
            $this->em->clear();

            return $selladas;
        }

        $this->em->flush();
        $this->em->clear();

        return $selladas;
    }

    /**
     * Huella del estado traducible de una entidad, para saber si el sellado la cambió.
     *
     * Se serializa el valor de cada propiedad `#[AutoTranslate]` en vez de preguntarle a Doctrine:
     * el `UnitOfWork` compara arrays con `!==`, que es sensible al ORDEN, y el propio servicio
     * puede reordenar las filas de idioma al pasarlas por mapa. Contar eso como «cambió» inflaría
     * el número que este comando reporta, que es justo lo que se mira para decidir.
     */
    private function huella(object $entidad): string
    {
        $partes = [];

        foreach ((new ReflectionClass($entidad))->getProperties() as $propiedad) {
            if ($propiedad->getAttributes(AutoTranslate::class) === []) {
                continue;
            }

            $getter = 'get' . ucfirst($propiedad->getName());

            if (method_exists($entidad, $getter)) {
                $partes[$propiedad->getName()] = $entidad->$getter();
            }
        }

        ksort($partes);

        return md5((string) json_encode($partes));
    }

    /** @param class-string $clase */
    private function nombreCorto(string $clase): string
    {
        $piezas = explode('\\', $clase);

        return end($piezas) ?: $clase;
    }
}
