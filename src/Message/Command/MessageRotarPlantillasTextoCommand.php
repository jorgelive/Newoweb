<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @phpstan-import-type BloqueDeCanal from MessageTemplate
 * @phpstan-import-type TextoTraducido from MessageTemplate
 *
 * Tres plantillas aprobadas con un defecto de texto, rotadas a `_v2` (26/09/2026).
 *
 * ── Qué se corrige ──────────────────────────────────────────────────────────
 * - `aviso_escalado_interno` y `aviso_cobro_interno` firmaban «Aviso automático del PMS». El
 *   sistema ya no es sólo un PMS —son veinte módulos— y la sigla viajó mal por la traducción
 *   automática: en el aviso técnico el francés la convirtió en «syndrome prémenstruel». Llevan
 *   ahora el mismo pie que `aviso_tecnico_interno_v2`, escrito a mano en los siete idiomas.
 * - `menu_tours` trataba de «usted» cuando todo lo demás tutea. Se corrige el español y el
 *   italiano, que tampoco seguía la convención de la casa. Los otros cuatro ya la siguen: en
 *   alemán, francés, portugués y neerlandés las plantillas van de *Sie*, *vous*, *você* y *u*,
 *   medido sobre `bienvenida`, `check_out` y `guia_llegada`.
 *
 * ── Por qué a mano y no por AutoTranslate ───────────────────────────────────
 * Las tres llevan `is_official_meta`, que veta a la autotraducción pisar una traducción que
 * existe (`preventOverwriteIf: 'isWhatsappMetaOfficial'`). Cambiar el español no propagaría
 * nada: lo que cambia se escribe aquí, idioma por idioma, y lo demás se queda como está.
 *
 * ── Por qué `_v2` ───────────────────────────────────────────────────────────
 * Reescribir una plantilla aprobada es una rotación: nombre nuevo, aprobación, y la vieja se
 * borra (§18.b de `docs/Mensajeria.md`). El `status` guardado por idioma se va con la generación
 * vieja: dejarlo diría que la `_v2` ya está aprobada.
 *
 * ⚠️ **Hay que subirlas en cuanto se escriben**: el sincronizador nocturno reconstruye estas
 * columnas con lo que Meta devuelve, y lo devuelto es la generación vieja hasta que se sube la
 * nueva. Ya se perdió así un pie corregido.
 *
 *   php bin/console msg:plantillas:rotar-textos --dry-run
 *   php bin/console msg:plantillas:rotar-textos
 *   php bin/console msg:meta:push aviso_escalado_interno --todos   (y las otras dos)
 *
 * Nace `hidden` porque es de una vez. Idempotente por contenido.
 */
#[AsCommand(
    name: 'msg:plantillas:rotar-textos',
    description: 'Rota a _v2 los dos avisos internos (pie sin «PMS») y menu_tours (tuteo). Idempotente.',
    hidden: true,
)]
final class MessageRotarPlantillasTextoCommand extends Command
{
    /**
     * El pie de los avisos al equipo: el mismo de `MessageCrearAvisoTecnicoCommand::PIE`.
     *
     * @var list<array{language: string, content: string}>
     */
    private const array PIE_AVISO = [
        ['language' => 'es', 'content' => 'Aviso automático · Sistema OpenPeru'],
        ['language' => 'en', 'content' => 'Automatic alert · Sistema OpenPeru'],
        ['language' => 'pt', 'content' => 'Alerta automático · Sistema OpenPeru'],
        ['language' => 'fr', 'content' => 'Alerte automatique · Sistema OpenPeru'],
        ['language' => 'it', 'content' => 'Avviso automatico · Sistema OpenPeru'],
        ['language' => 'de', 'content' => 'Automatische Meldung · Sistema OpenPeru'],
        ['language' => 'nl', 'content' => 'Automatische melding · Sistema OpenPeru'],
    ];

    /**
     * Lo que cambia en el cuerpo de `menu_tours`, por idioma: [texto viejo, texto nuevo].
     *
     * Se reemplaza el FRAGMENTO y no el cuerpo entero para no reescribir lo que ya estaba bien;
     * y si el fragmento no está tal cual, el comando se niega en vez de adivinar.
     *
     * @var array<string, array{string, string}>
     */
    private const array TUTEO_MENU = [
        'es' => [
            'En el catálogo verá precios, horarios y qué incluye cada uno. Dígame cuál le interesa y se lo reservamos.',
            'En el catálogo verás precios, horarios y qué incluye cada uno. Dime cuál te interesa y te lo reservamos.',
        ],
        'it' => [
            'Nel catalogo trova prezzi, orari e cosa include ciascuna. Mi dica quale le interessa e la prenotiamo.',
            'Nel catalogo trovi prezzi, orari e cosa include ciascuna. Dimmi quale ti interessa e la prenotiamo.',
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');
        $cambios = 0;

        foreach (['aviso_escalado_interno', 'aviso_cobro_interno'] as $codigo) {
            $resultado = $this->rotar($io, $codigo, static function (array $meta): array {
                $meta['footer'] = self::PIE_AVISO;

                return $meta;
            }, $simular);

            if ($resultado === null) {
                return Command::FAILURE;
            }

            $cambios += $resultado;
        }

        $resultado = $this->rotar($io, 'menu_tours', static function (array $meta) use ($io): ?array {
            $cuerpo = $meta['body'] ?? [];

            foreach ($cuerpo as $i => $fila) {
                $idioma = $fila['language'] ?? '';

                if (!isset(self::TUTEO_MENU[$idioma])) {
                    continue;
                }

                [$viejo, $nuevo] = self::TUTEO_MENU[$idioma];
                $texto = $fila['content'] ?? '';

                if (str_contains($texto, $nuevo)) {
                    continue;
                }

                if (!str_contains($texto, $viejo)) {
                    $io->error(sprintf('menu_tours [%s]: el texto no está tal cual; míralo en el panel.', $idioma));

                    return null;
                }

                $cuerpo[$i]['content'] = str_replace($viejo, $nuevo, $texto);
            }

            $meta['body'] = $cuerpo;

            return $meta;
        }, $simular);

        if ($resultado === null) {
            return Command::FAILURE;
        }

        $cambios += $resultado;

        if ($cambios === 0) {
            $io->success('Ya estaba todo rotado.');

            return Command::SUCCESS;
        }

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Hecho. Súbelas YA a Meta, antes de que el sincronizador nocturno las pise: msg:meta:push <código> --todos');

        return Command::SUCCESS;
    }

    /**
     * Aplica el cambio de texto, pone el nombre `_v2` y quita el estado viejo de cada idioma.
     *
     * @param callable(BloqueDeCanal): (BloqueDeCanal|null) $cambiar
     * @return int|null 1 si cambió algo, 0 si ya estaba, null si hubo que parar.
     */
    private function rotar(SymfonyStyle $io, string $codigo, callable $cambiar, bool $simular): ?int
    {
        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $codigo]);

        if (!$plantilla instanceof MessageTemplate) {
            $io->error(sprintf('No existe la plantilla «%s».', $codigo));

            return null;
        }

        $meta = $plantilla->getWhatsappMetaTmpl() ?? [];
        $nombre = $codigo . '_v2';
        $nuevo = $cambiar($meta);

        if ($nuevo === null) {
            return null;
        }

        $nuevo['meta_template_name'] = $nombre;
        // El estado por idioma es de la generación vieja. Ver la cabecera.
        $nuevo['body'] = self::sinEstado($nuevo['body'] ?? []);

        $comparable = $meta;
        $comparable['body'] = self::sinEstado($meta['body'] ?? []);
        $comparable['meta_template_name'] = $nombre;

        // `==` y no `===` a propósito: MySQL guarda los objetos JSON con las claves ORDENADAS
        // —`content` antes que `language`— y `===` exige el mismo orden. Con él, el pie recién
        // escrito nunca casaba con el leído y el comando reescribía en cada pasada.
        if ($nuevo == $comparable && ($meta['meta_template_name'] ?? null) === $nombre) {
            $io->text(sprintf('= %s: ya está como debe.', $codigo));

            return 0;
        }

        $io->text(sprintf(
            '<fg=green>~ %s: %s → %s</>',
            $codigo,
            $meta['meta_template_name'] ?? '—',
            $nombre
        ));

        if (!$simular) {
            $plantilla->setWhatsappMetaTmpl($nuevo);
        }

        return 1;
    }

    /**
     * @param list<TextoTraducido> $filas
     * @return list<TextoTraducido>
     */
    private static function sinEstado(array $filas): array
    {
        return array_map(static function (array $fila): array {
            unset($fila['status']);

            return $fila;
        }, $filas);
    }
}
