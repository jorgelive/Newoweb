<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Entity\Maestro\MaestroIdioma;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Enum\PmsGuiaVisibilidad;
use App\Pms\Guia\PmsGuiaEnlaceDelEquipo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Las fichas que sólo se abren con un enlace del equipo: la llave de emergencia, el pago en
 * efectivo y las llaves interiores. Todas van a la caja de ARRIBA (`PmsGuiaVisibilidad::PorEnlace`),
 * cada una a su cartuchera: VERDE el dinero, CELESTE las llaves de emergencia, AZUL las interiores
 * (Jorge, 22/10/2026).
 *
 * Por qué fichas y no sólo plantillas: Meta no aprueba una plantilla que dé una clave (medido el
 * 21/10/2026, `docs/Mensajeria.md`), y una plantilla con un ENLACE sí sigue el patrón que ya
 * aprueba (`guia_llegada`). El enlace lleva aquí. Ver `docs/PmsGuiaHuesped.md` §7 sexies.
 *
 * Por el ORM y no por SQL: `titulo` y `descripcion` llevan `#[AutoTranslate]`. La etiqueta física
 * de la llave («emergencia», escrita en español en la cartuchera) va en `<span translate="no">`,
 * que en un campo HTML Google respeta (`ProtectorDeMarcadores`): la otra vez se tradujo a
 * «emergency» y el huésped habría buscado una etiqueta que no existe. El comando comprueba al
 * final que sigue en los siete idiomas.
 *
 * No cuelgan de ninguna sección: no hace falta, y así ni por error salen en la guía.
 *
 * Idempotente por el código de ficha, con `--dry-run`. Nace `hidden`.
 */
#[AsCommand(
    name: 'app:pms:guia:enlaces-del-equipo',
    description: 'Crea las fichas que sólo abre un enlace del equipo (llave de emergencia, pago en efectivo, llaves interiores).',
    hidden: true,
)]
final class PmsGuiaEnlacesDelEquipoCommand extends Command
{
    /**
     * código de ficha => [nombre interno, título, descripción HTML].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const array FICHAS = [
        'llave-emergencia' => [
            'Llave de emergencia (enlace del equipo)',
            'Llave de emergencia',
            <<<'HTML'
                <p>Para entrar a {{ room_name }} usa la llave de emergencia:</p>
                <ol>
                <li>En el pasadizo hay dos cajas fuertes digitales. La de la llave de emergencia es la de <strong>arriba</strong>; la de abajo es la de las llaves de siempre.<br>{{ foto_caja_dinero }}</li>
                <li>Ingresa el código <span>{{ codigo_caja_dinero }}</span> y gira la perilla hacia la derecha para abrir.</li>
                <li>Dentro hay una cartuchera celeste. Toma la llave con la etiqueta «<span translate="no">emergencia</span>» y el número de tu casita ({{ room_name }}).</li>
                <li>Cierra la caja girando la perilla hacia la izquierda y asegúrate de que quedó bien cerrada.</li>
                </ol>
                <p>Así se abre la caja:</p>
                <p>{{ video_caja_dinero }}</p>
                HTML,
        ],
        'pago-efectivo' => [
            'Pago en efectivo (enlace del equipo)',
            'Pago en efectivo',
            <<<'HTML'
                <p>Para dejar el pago en efectivo:</p>
                <ol>
                <li>En el pasadizo hay dos cajas fuertes digitales. La del pago es la de <strong>arriba</strong>; la de abajo es la de las llaves.<br>{{ foto_caja_dinero }}</li>
                <li>Ingresa el código <span>{{ codigo_caja_dinero }}</span> y gira la perilla hacia la derecha para abrir.</li>
                <li>Dentro hay una cartuchera <strong>verde</strong>: deja el dinero dentro de ella.</li>
                <li>Antes de cerrar, toma una foto del dinero en la cartuchera y envíanosla por WhatsApp: es tu comprobante y el nuestro.</li>
                <li>Cierra la caja girando la perilla hacia la izquierda y asegúrate de que quedó bien cerrada.</li>
                </ol>
                <p>Así se abre la caja:</p>
                <p>{{ video_caja_dinero }}</p>
                HTML,
        ],
        // Los pomos de las puertas interiores llevan botón de seguro: si se cierra, no hay llave en
        // la casita —se perdían o se las llevaban (Jorge, 22/10/2026)—. Están en la cartuchera
        // AZUL, etiquetadas «interior N» con el número de la casita, que es `{{ numero }}`.
        'llaves-interiores' => [
            'Llaves interiores (enlace del equipo)',
            'Llave de una puerta interior',
            <<<'HTML'
                <p>Para abrir una puerta interior de {{ room_name }} que se cerró con el botón del pomo:</p>
                <ol>
                <li>En el pasadizo hay dos cajas fuertes digitales. Las llaves interiores están en la de <strong>arriba</strong>; la de abajo es la de las llaves de siempre.<br>{{ foto_caja_dinero }}</li>
                <li>Ingresa el código <span>{{ codigo_caja_dinero }}</span> y gira la perilla hacia la derecha para abrir.</li>
                <li>Dentro hay una cartuchera <strong>azul</strong>. Toma el llavero con la etiqueta «<span translate="no">interior</span> {{ numero }}», el número de tu casita.</li>
                <li>Cierra la caja girando la perilla hacia la izquierda.</li>
                <li>Cuando hayas abierto la puerta, vuelve a dejar el llavero en la cartuchera azul y cierra la caja: las llaves interiores no se quedan en la casita.</li>
                </ol>
                <p>Así se abre la caja:</p>
                <p>{{ video_caja_dinero }}</p>
                HTML,
        ],
    ];

    /**
     * La etiqueta FÍSICA de cada llave, escrita en español, que tiene que sobrevivir a la
     * traducción (va en `<span translate="no">`). Se comprueba en los siete idiomas al final.
     *
     * @var array<string, string>
     */
    private const array ETIQUETAS = [
        'llave-emergencia' => 'emergencia',
        'llaves-interiores' => 'interior',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Sólo dice qué haría.')] bool $dryRun = false,
        #[Option(description: 'Reescribe también el español de las que ya existen y NADIE ha tocado en el panel (se vuelven a traducir).')] bool $actualizar = false,
    ): int {
        $repo = $this->em->getRepository(PmsGuiaItem::class);
        $creadas = [];

        foreach (self::FICHAS as $codigo => [$nombre, $titulo, $descripcion]) {
            $existente = $repo->findOneBy(['codigo' => $codigo]);

            if ($existente instanceof PmsGuiaItem) {
                $esDelEquipo = $existente->getVisibilidad() === PmsGuiaVisibilidad::PorEnlace;
                // Sin `updatedAt`: nadie la ha guardado desde el panel desde que la creó este comando.
                // Editada a mano no se pisa: lo que se escribió allí manda.
                $intacta = $existente->getUpdatedAt() === null;
                $espanolActual = MaestroIdioma::textoEn($existente->getDescripcion() ?? [], 'es');

                if (!$actualizar || !$esDelEquipo || !$intacta || $espanolActual === $descripcion) {
                    $io->text(sprintf(
                        '= «%s» ya existe (%s).',
                        $codigo,
                        match (true) {
                            !$esDelEquipo => '⚠️ con OTRO nivel: revísala a mano',
                            // Guardada después de crearla —en el panel o por un --actualizar anterior—.
                            !$intacta && $actualizar => 'ya guardada después de crearla: no se toca, edítala en el panel',
                            $espanolActual === $descripcion => 'al día',
                            default => 'con texto distinto: --actualizar para reescribirla',
                        }
                    ));

                    continue;
                }

                $io->section(sprintf('%s (se reescribe)', $codigo));
                $io->writeln($descripcion);

                if (!$dryRun) {
                    // Sólo el español: con el resto fuera, el listener vuelve a traducirlos.
                    $existente->setTitulo([['language' => 'es', 'content' => $titulo]]);
                    $existente->setDescripcion([['language' => 'es', 'content' => $descripcion]]);
                    $creadas[] = $existente;
                }

                continue;
            }

            $io->section(sprintf('%s → {{ %s }}', $codigo, PmsGuiaEnlaceDelEquipo::variable($codigo)));
            $io->writeln($descripcion);

            if ($dryRun) {
                continue;
            }

            $item = (new PmsGuiaItem())
                ->setCodigo($codigo)
                ->setNombreInterno($nombre)
                ->setTipo(PmsGuiaItem::TIPO_TARJETA)
                ->setVisibilidad(PmsGuiaVisibilidad::PorEnlace)
                ->setTitulo([['language' => 'es', 'content' => $titulo]])
                ->setDescripcion([['language' => 'es', 'content' => $descripcion]]);

            $this->em->persist($item);
            $creadas[] = $item;
        }

        if ($dryRun) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        // El listener traduce en este flush.
        $this->em->flush();

        foreach ($creadas as $item) {
            $etiqueta = self::ETIQUETAS[(string) $item->getCodigo()] ?? null;

            if ($etiqueta === null) {
                continue;
            }

            foreach ($item->getDescripcion() ?? [] as $fila) {
                if (!str_contains((string) ($fila['content'] ?? ''), $etiqueta)) {
                    $io->warning(sprintf('En «%s» la etiqueta «%s» no sobrevivió a la traducción: corrígela en el panel.', $fila['language'] ?? '?', $etiqueta));
                }
            }
        }

        $io->success(sprintf('%d ficha(s) creada(s) o reescrita(s). Úsalas en una plantilla con {{ acceso_<código> }}.', count($creadas)));

        return Command::SUCCESS;
    }
}
