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
 * «No encuentro la casita» son dos preguntas, y el agente contestaba sólo una (19/10/2026).
 *
 * ### El caso
 *
 * Una huésped llegó a la calle, no veía números y escribió cinco veces «where is the room». El
 * agente eligió siempre la ficha «Localización» —dirección y mapa— y le repitió la dirección: lo
 * que le faltaba era su DEPARTAMENTO, ya dentro del pasaje, y eso está en «Puerta del
 * Departamento» (recorrido, croquis, vídeo). Con las palabras de la pregunta no se distingue cuál
 * de las dos busca.
 *
 * ### Qué hace
 *
 * - **«Localización» recibe texto para el agente** —tenía `agenteContenido` vacío y el agente leía
 *   el publicado—: la dirección y el mapa, copiados del texto publicado en español para no tener
 *   dos versiones, y la regla de las dos preguntas. Si no está claro cuál busca, contesta las dos;
 *   si ya está en la casa (no ve números, manda fotos del pasaje), va directo a su puerta.
 * - **Las siete «Puerta del Departamento» reciben los términos** con los que pregunta quien no
 *   encuentra su departamento.
 *
 * ⚠️ El texto de «Localización» para el agente **sustituye** al publicado: si cambia la dirección o
 * el mapa, hay que cambiar las dos superficies. Por eso el comando la copia en vez de escribirla.
 *
 * Idempotente, con `--dry-run`. Sólo toca texto del agente, que no se traduce.
 */
#[AsCommand(
    name: 'app:pms:guia:orientar-llegada',
    description: 'Enseña al agente que «no encuentro la casita» puede ser la casa o el departamento.',
    // Corrió en producción el 19/10/2026 (8 fichas). Se queda como receta y sigue ejecutable.
    hidden: true,
)]
final class PmsGuiaOrientarLlegadaCommand extends Command
{
    private const string UBICACION = 'Ubicación (general)';

    /** Lo que identifica que el bloque ya está puesto. */
    private const string MARCA = 'SON DOS PREGUNTAS';

    private const string DOS_PREGUNTAS = <<<'TXT'
        «NO ENCUENTRO LA CASITA» SON DOS PREGUNTAS: la casa desde la calle, o su departamento ya
        dentro. Cuando no esté claro cuál —«where is the room», «no la encuentro»—, contesta las dos a
        la vez: el mapa de arriba para llegar a la casa, y el recorrido hasta su puerta con el croquis
        y el vídeo, que están en el tema «Puerta del Departamento»: pídelo y mándale las dos cosas.

        Si ya está en la casa —dice que no ve números, que está en la puerta de la calle o manda
        fotos del pasaje—, lo que le falta es su departamento: ve directo a «Puerta del Departamento»
        y mándale el vídeo y el croquis.
        TXT;

    /** Cómo pregunta quien ya está en la casa y no da con su puerta. */
    private const array TERMINOS_PUERTA = [
        'no encuentro el departamento',
        'no encuentro mi puerta',
        'donde esta el departamento',
        'cual es mi puerta',
        'no hay numeros',
        'where is my room',
        'where is the apartment',
        'which door',
        'cant find the apartment',
    ];

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
        $repo = $this->em->getRepository(PmsGuiaItem::class);

        $ubicacion = $repo->findOneBy(['nombreInterno' => self::UBICACION]);

        if (!$ubicacion instanceof PmsGuiaItem) {
            $io->warning(sprintf('No encuentro «%s»: no toco la localización.', self::UBICACION));
        } elseif (!str_contains((string) $ubicacion->getAgenteContenido(), self::MARCA)) {
            $base = trim((string) $ubicacion->getAgenteContenido());
            if ($base === '') {
                $base = $this->publicadoEnTexto($ubicacion);
            }

            if ($base === '') {
                $io->warning('«Localización» no tiene texto publicado en español: no sé qué dirección copiar.');
            } else {
                $io->text('+ ' . self::UBICACION . ': dirección y mapa del publicado + las dos preguntas');
                $io->text('  ' . str_replace("\n", "\n  ", $base));
                ++$cambios;

                if (!$dryRun) {
                    $ubicacion->setAgenteContenido($base . "\n\n" . self::DOS_PREGUNTAS);
                }
            }
        }

        /** @var list<PmsGuiaItem> $items */
        $items = $repo->findAll();

        foreach ($items as $item) {
            if (!str_starts_with((string) $item->getNombreInterno(), 'Puerta (casa ')) {
                continue;
            }

            $actuales = array_map(
                static fn (string $t): string => mb_strtolower(trim($t)),
                explode(',', (string) $item->getAgenteTerminos()),
            );
            $faltan = array_values(array_diff(self::TERMINOS_PUERTA, $actuales));

            if ($faltan === []) {
                continue;
            }

            $io->text(sprintf('+ %s: %d término(s)', $item->getNombreInterno(), \count($faltan)));
            ++$cambios;

            if (!$dryRun) {
                $item->setAgenteTerminos(rtrim(trim((string) $item->getAgenteTerminos()), ',') . ', ' . implode(', ', $faltan));
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('%d ficha(s) %s.', $cambios, $dryRun ? 'se cambiarían' : 'cambiadas'));

        return Command::SUCCESS;
    }

    /**
     * El cuerpo publicado en español, en texto plano y con el `{{ map: … }}` intacto: la skill lo
     * convierte en enlace para el agente.
     */
    private function publicadoEnTexto(PmsGuiaItem $item): string
    {
        foreach ($item->getDescripcion() ?? [] as $traduccion) {
            if (($traduccion['language'] ?? null) !== 'es') {
                continue;
            }

            $html = (string) ($traduccion['content'] ?? '');
            $html = (string) preg_replace('~</p>|<br\s*/?>~i', "\n", $html);
            $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $lineas = array_filter(array_map(
                static fn (string $l): string => trim((string) preg_replace('/\s+/u', ' ', $l)),
                explode("\n", $texto),
            ));

            return implode("\n", $lineas);
        }

        return '';
    }
}
