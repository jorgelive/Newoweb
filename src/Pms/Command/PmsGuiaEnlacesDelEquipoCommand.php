<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Enum\PmsGuiaVisibilidad;
use App\Pms\Guia\PmsGuiaEnlaceDelEquipo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Las dos fichas que sólo se abren con un enlace del equipo: la llave de emergencia y el pago en
 * efectivo. Las dos van a la caja de ARRIBA, la del dinero (`PmsGuiaVisibilidad::PorEnlace`).
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
    description: 'Crea las fichas «llave-emergencia» y «pago-efectivo», que sólo abre un enlace del equipo.',
    hidden: true,
)]
final class PmsGuiaEnlacesDelEquipoCommand extends Command
{
    /** La etiqueta que tiene que sobrevivir a la traducción. */
    private const string ETIQUETA = 'emergencia';

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
                <li>Antes de cerrar, toma una foto del dinero dentro de la caja y envíanosla por WhatsApp: es tu comprobante y el nuestro.</li>
                <li>Cierra girando la perilla hacia la izquierda y asegúrate de que quedó bien cerrada.</li>
                </ol>
                <p>Así se abre la caja:</p>
                <p>{{ video_caja_dinero }}</p>
                HTML,
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Sólo dice qué haría.')] bool $dryRun = false,
    ): int {
        $repo = $this->em->getRepository(PmsGuiaItem::class);
        $creadas = [];

        foreach (self::FICHAS as $codigo => [$nombre, $titulo, $descripcion]) {
            $existente = $repo->findOneBy(['codigo' => $codigo]);

            if ($existente instanceof PmsGuiaItem) {
                // Un código ya usado por una ficha de OTRO nivel no se pisa: habría que decidir
                // a mano si esa ficha deja de verse en la guía.
                $io->text(sprintf(
                    '= «%s» ya existe (%s).',
                    $codigo,
                    $existente->getVisibilidad() === PmsGuiaVisibilidad::PorEnlace ? 'enlace del equipo' : '⚠️ con OTRO nivel: revísala a mano'
                ));

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
            if ($item->getCodigo() !== 'llave-emergencia') {
                continue;
            }

            foreach ($item->getDescripcion() ?? [] as $fila) {
                if (!str_contains((string) ($fila['content'] ?? ''), self::ETIQUETA)) {
                    $io->warning(sprintf('En «%s» la etiqueta «%s» no sobrevivió a la traducción: corrígela en el panel.', $fila['language'] ?? '?', self::ETIQUETA));
                }
            }
        }

        $io->success(sprintf('%d ficha(s) creada(s). Úsalas en una plantilla con {{ acceso_<código> }}.', count($creadas)));

        return Command::SUCCESS;
    }
}
