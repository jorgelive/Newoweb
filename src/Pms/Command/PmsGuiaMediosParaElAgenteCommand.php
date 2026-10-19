<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Da al agente los vídeos que el huésped ya tiene en su guía (19/10/2026).
 *
 * ### El hueco
 *
 * `agenteContenido` **sustituye** al texto publicado: lo que no está ahí, el agente no lo sabe. Las
 * siete fichas «Puerta (casa N)» publican el croquis, la foto de la puerta y el vídeo del recorrido
 * (`{{ video_ingreso }}`), pero su texto para el agente sólo llevaba el croquis. Una huésped que no
 * encontraba su casita recibió el croquis y cuatro escaladas, con el vídeo en su guía. Y «Llaves
 * (general)» publica el vídeo de la caja fuerte (`{{ video_caja_llaves }}`) sin que el agente lo
 * supiera para cuando no consiguen abrirla.
 *
 * De paso arregla un marcador viejo: `{{ unit_name }}` se renombró a `{{ room_name }}`
 * ({@see PmsGuiaRenombrarMarcadoresCommand}), pero ese comando no recorría el texto del agente, y en
 * las fichas de puerta salía «Cómo llegar a  :» con el nombre vacío.
 *
 * ### Por qué un comando
 *
 * Es texto que sólo lee el agente —sin traducir—, así que podría ir por migración. Va por comando
 * porque depende de lo que hay escrito en cada ficha (sólo se añade el vídeo donde la guía lo
 * publica, y sólo si no está ya) y eso es más claro en PHP que en un `UPDATE … REPLACE`.
 *
 * Idempotente: relanzarlo no duplica nada. `--dry-run` cuenta lo que haría.
 *
 * Los vídeos son marcadores, no URLs: los resuelve `PmsGuiaInterpolador` con su nivel de acceso, así
 * que a quien todavía no le toca verlos le llegan como bloqueados, igual que en la guía.
 */
#[AsCommand(
    name: 'app:pms:guia:medios-para-el-agente',
    description: 'Añade al texto del agente los vídeos de ingreso y de la caja fuerte que ya publica la guía.',
)]
final class PmsGuiaMediosParaElAgenteCommand extends Command
{
    private const string VIDEO_INGRESO = <<<'TXT'
        Este vídeo enseña el recorrido hasta su puerta: {{ video_ingreso }}
        Mándaselo junto con el croquis cuando pregunte cómo llegar o no encuentre su puerta.
        TXT;

    private const string VIDEO_CAJA = <<<'TXT'
        SI NO CONSIGUE ABRIR LA CAJA: mándale este vídeo, que enseña cómo se abre: {{ video_caja_llaves }}
        Los pasos son los de su guía: la llave está en la caja de abajo, se marca el código y se gira la perilla hacia la derecha.
        TXT;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Sólo dice qué haría.')]
        bool $dryRun = false,
    ): int {
        $cambios = 0;

        /** @var list<PmsGuiaItem> $items */
        $items = $this->em->getRepository(PmsGuiaItem::class)->findAll();

        foreach ($items as $item) {
            $nombre = (string) $item->getNombreInterno();
            $contenido = (string) $item->getAgenteContenido();
            $pasos = $item->getAgentePasos();
            $hecho = [];

            if (str_contains($contenido, 'unit_name') || str_contains(implode("\n", $pasos), 'unit_name')) {
                $contenido = self::renombrar($contenido);
                $pasos = array_map(self::renombrar(...), $pasos);
                $hecho[] = 'unit_name → room_name';
            }

            if (str_starts_with($nombre, 'Puerta (casa ')
                && $contenido !== ''
                && $this->publica($item, 'video_ingreso')
                && !str_contains($contenido, 'video_ingreso')
            ) {
                $contenido = self::anadir($contenido, self::VIDEO_INGRESO);
                $hecho[] = 'vídeo de ingreso';
            }

            if ($nombre === 'Llaves (general)'
                && $contenido !== ''
                && $this->publica($item, 'video_caja_llaves')
                && !str_contains($contenido, 'video_caja_llaves')
            ) {
                $contenido = self::anadir($contenido, self::VIDEO_CAJA);
                $hecho[] = 'vídeo de la caja fuerte';
            }

            if ($hecho === []) {
                continue;
            }

            $io->text(sprintf('+ %s: %s', $nombre, implode(', ', $hecho)));
            ++$cambios;

            if (!$dryRun) {
                $item->setAgenteContenido($contenido);
                $item->setAgentePasos($pasos);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('%d ficha(s) %s.', $cambios, $dryRun ? 'se cambiarían' : 'cambiadas'));

        return Command::SUCCESS;
    }

    /** ¿El texto publicado (en español, el original) usa ese marcador? */
    private function publica(PmsGuiaItem $item, string $marcador): bool
    {
        foreach ($item->getDescripcion() ?? [] as $traduccion) {
            if (($traduccion['language'] ?? null) === 'es' && str_contains((string) ($traduccion['content'] ?? ''), $marcador)) {
                return true;
            }
        }

        return false;
    }

    private static function renombrar(string $texto): string
    {
        return (string) preg_replace('/\{\{\s*unit_name\s*\}\}/', '{{ room_name }}', $texto);
    }

    private static function anadir(string $contenido, string $bloque): string
    {
        return rtrim($contenido) . "\n\n" . $bloque;
    }
}
