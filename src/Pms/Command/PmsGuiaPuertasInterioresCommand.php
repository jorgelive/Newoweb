<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaSeccion;
use App\Pms\Entity\PmsGuiaSeccionHasItem;
use App\Pms\Enum\PmsGuiaVisibilidad;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La ficha «Puertas interiores» del manual de cada casita: lo que el AGENTE necesita saber cuando a
 * alguien se le cierra una puerta interior (22/10/2026).
 *
 * ── Por qué hacía falta ──────────────────────────────────────────────────────
 * Los pomos de las puertas interiores llevan botón de seguro, y las llaves interiores no se dejan en
 * la casita (se perdían o se las llevaban): están en la caja de arriba, cartuchera azul, y las da el
 * equipo con la plantilla `llaves_interiores` (`docs/PmsGuiaHuesped.md` §7 sexies). Ensayado con el
 * modelo real ese día, sin esta ficha el agente escalaba bien pero, al pedirle el huésped el código
 * de la caja, contestó que «esa caja guarda sólo las llaves de entrada, no la del baño»: miró la de
 * abajo y dedujo que no hay llave. Lo que el agente no sabe, lo inventa (CLAUDE.md).
 *
 * ── Qué dice cada superficie ────────────────────────────────────────────────
 * - Al huésped (`descripcion`): que el pomo tiene seguro y que, si se cierra, escriba. Sin la
 *   negativa («no hay llave en la casita») a priori.
 * - Al agente (`agenteContenido`): dónde está la llave —la tiene el equipo— y qué decir al escalar.
 *   `agenteRequiereHumano`: lo explica pero no lo resuelve él.
 *
 * Una sola ficha colgada del «Manual (casa N)» de las siete. Por el ORM: se traduce al guardar.
 * Idempotente por `nombreInterno`, con `--dry-run`. Nace `hidden`.
 */
#[AsCommand(
    name: 'app:pms:guia:puertas-interiores',
    description: 'Crea la ficha «Puertas interiores» (pomo con seguro; la llave la da el equipo) en el manual de cada casita.',
    hidden: true,
)]
final class PmsGuiaPuertasInterioresCommand extends Command
{
    private const string NOMBRE = 'Puertas interiores (general)';

    private const string DESCRIPCION = '<p>Las puertas interiores tienen un pomo con botón de seguro: al presionarlo, '
        . 'la puerta queda cerrada.</p><p>Si alguna se cierra y no puedes abrirla, escríbenos y te ayudamos.</p>';

    private const string AGENTE = 'Las puertas interiores (habitaciones, baño) tienen un pomo con botón de seguro. '
        . 'Si a alguien se le cerró una y no puede abrirla: la llave interior la tiene el EQUIPO —no está en la '
        . 'casita ni en la caja de llaves de abajo— y es el equipo quien le indica cómo sacarla. Escala con '
        . 'escalar_al_equipo y este motivo: «se le cerró la puerta interior de <cuál>: mandarle llaves_interiores». '
        . 'Dile que ya avisaste y que en un momento le indican cómo abrirla. Si te pide el código de una caja '
        . 'para sacarla él, dile que el equipo se lo envía, y escala si no lo has hecho.';

    private const string TERMINOS = 'puerta cerrada, seguro, pomo, botón del pomo, baño cerrado, habitación cerrada, '
        . 'no abre la puerta del baño, se cerró la puerta, me quedé encerrado, llave del cuarto, llave interior, '
        . 'llave del baño, locked door, bathroom locked';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Sólo dice qué haría.')] bool $dryRun = false,
    ): int {
        if ($this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => self::NOMBRE]) !== null) {
            $io->success(sprintf('«%s» ya existe: no se toca.', self::NOMBRE));

            return Command::SUCCESS;
        }

        $item = (new PmsGuiaItem())
            ->setNombreInterno(self::NOMBRE)
            ->setCodigo('puertas-interiores')
            ->setTipo(PmsGuiaItem::TIPO_TARJETA)
            ->setVisibilidad(PmsGuiaVisibilidad::Cliente)
            ->setCategoria('general')
            ->setIcono('fa-door-closed')
            ->setTitulo([['language' => 'es', 'content' => 'Puertas interiores']])
            ->setDescripcion([['language' => 'es', 'content' => self::DESCRIPCION]])
            ->setAgenteTerminos(self::TERMINOS)
            ->setAgenteContenido(self::AGENTE)
            ->setAgenteRequiereHumano(true);

        $enlazadas = 0;

        for ($casa = 1; $casa <= 7; ++$casa) {
            $seccion = $this->em->getRepository(PmsGuiaSeccion::class)->findOneBy(['nombreInterno' => sprintf('Manual (casa %d)', $casa)]);

            if (!$seccion instanceof PmsGuiaSeccion) {
                $io->warning(sprintf('No encuentro «Manual (casa %d)»: esa casa se queda sin la ficha.', $casa));

                continue;
            }

            $io->text(sprintf('+ %s → %s', self::NOMBRE, $seccion->getNombreInterno()));
            ++$enlazadas;

            if (!$dryRun) {
                // Detrás de lo que ya hay: no es lo primero que se busca al llegar.
                $this->em->persist((new PmsGuiaSeccionHasItem())->setSeccion($seccion)->setItem($item)->setOrden(60));
            }
        }

        if ($dryRun) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->persist($item);
        $this->em->flush();
        $io->success(sprintf('«%s» creada y colgada de %d manual(es).', self::NOMBRE, $enlazadas));

        return Command::SUCCESS;
    }
}
