<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La dirección pasa a «Saphi 877-A y B», y el mapa a coordenadas.
 *
 * ── De dónde sale ───────────────────────────────────────────────────────────
 * Una huésped lo dijo en el chat el 17/09/2026, ya dentro del departamento: «dice 877-A y debería
 * haber sido -B». Y es verdad a medias, que es lo que lo hacía difícil: **el pasaje está en el
 * 877-B, pero el límite entre A y B es difuso** y los carteles no coinciden. Poner un solo número
 * hace que quien compare con la calle crea que se equivocó de sitio.
 *
 * Por eso van los dos: `Calle Saphi 877-A y B, Cusco`.
 *
 * ── Y el mapa deja de buscar por texto ──────────────────────────────────────
 * El enlace era una BÚSQUEDA (`/maps/search/C.+Saphy+877-A+…`) con las coordenadas detrás. Una
 * búsqueda la resuelve Google como quiere, y con un número de calle que ni en la puerta está claro
 * es justo donde puede caer en otro portal. Pasa a un punto por coordenadas: el pin es el nuestro,
 * diga lo que diga el callejero. `MapBlock` enseña la URL como texto, así que se ve que son
 * coordenadas — a cambio de que lleve exactamente a la puerta.
 *
 * ⚠️ **La dirección que ven en Booking y Airbnb vive en Beds24, no aquí.** Esto arregla la guía y
 * la ficha del panel; lo de las OTA se cambia allí a mano.
 *
 * ── Por qué comando y no SQL ────────────────────────────────────────────────
 * `descripcion` lleva `#[AutoTranslate]`: un `UPDATE` se salta el listener y deja las otras seis
 * traducciones con el texto viejo. Se toca **sólo el español** y el listener rehace el resto.
 *
 * Nace `hidden` porque es de una vez: ver la regla de archivado en `CLAUDE.md`.
 */
#[AsCommand(
    name: 'app:pms:guia:direccion-a-y-b',
    description: 'Deja la dirección como «877-A y B» y el mapa por coordenadas. Idempotente.',
    hidden: true,
)]
final class PmsGuiaDireccionAyBCommand extends Command
{
    private const string FICHA = 'Ubicación (general)';

    private const string DIRECCION_VIEJA = 'Calle Saphi 877-A Cusco';
    private const string DIRECCION_NUEVA = 'Calle Saphi 877-A y B, Cusco';

    private const string MAPA_VIEJO = 'https://www.google.com/maps/search/C.+Saphy+877-A+Cusco+08002/@-13.511305,-71.984414,18z';
    private const string MAPA_NUEVO = 'https://www.google.com/maps/place/-13.511305,-71.984414';

    /** Lo que se ve en el panel. No sale en ningún texto al huésped, pero no puede contradecirlo. */
    private const string PANEL_VIEJO = 'Saphi 877-A';
    private const string PANEL_NUEVO = 'Saphi 877-A y B';

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
        $cambios = [];

        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => self::FICHA]);

        if (!$item instanceof PmsGuiaItem) {
            $io->error(sprintf('No existe la ficha «%s».', self::FICHA));

            return Command::FAILURE;
        }

        $contenido = $item->getDescripcion() ?? [];
        $indice = null;

        foreach ($contenido as $i => $fila) {
            if (($fila['language'] ?? null) === 'es') {
                $indice = $i;
                break;
            }
        }

        if ($indice === null) {
            $io->error('La ficha no tiene descripción en español.');

            return Command::FAILURE;
        }

        $texto = (string) ($contenido[$indice]['content'] ?? '');
        $nuevo = str_replace(
            [self::DIRECCION_VIEJA, self::MAPA_VIEJO],
            [self::DIRECCION_NUEVA, self::MAPA_NUEVO],
            $texto
        );

        if ($nuevo !== $texto) {
            $cambios[] = ['guía', self::DIRECCION_VIEJA . ' → ' . self::DIRECCION_NUEVA];
            $cambios[] = ['guía (mapa)', 'búsqueda por texto → punto por coordenadas'];

            if (!$simular) {
                $contenido[$indice]['content'] = $nuevo;
                // `array_values` para que siga siendo una lista: el setter la pide así y una clave
                // reasignada basta para que PHPStan deje de verla como tal.
                $item->setDescripcion($contenido);
            }
        }

        foreach ($this->em->getRepository(PmsEstablecimiento::class)->findAll() as $establecimiento) {
            if ($establecimiento->getDireccionLinea1() !== self::PANEL_VIEJO) {
                continue;
            }

            $cambios[] = ['panel', self::PANEL_VIEJO . ' → ' . self::PANEL_NUEVO];

            if (!$simular) {
                $establecimiento->setDireccionLinea1(self::PANEL_NUEVO);
            }
        }

        if ($cambios === []) {
            $io->success('Ya estaba: la dirección dice «877-A y B» y el mapa va por coordenadas.');

            return Command::SUCCESS;
        }

        $io->table(['Dónde', 'Qué cambia'], $cambios);

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Hecho. Los otros seis idiomas los rehace AutoTranslate al guardar.');

        return Command::SUCCESS;
    }
}
