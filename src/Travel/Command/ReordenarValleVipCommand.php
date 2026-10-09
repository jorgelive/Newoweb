<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La VIP privada era la ruta tradicional con precio de VIP, y vivía en el servicio equivocado.
 *
 * ── Lo que había ────────────────────────────────────────────────────────────
 *
 * «Full Day Valle Vip privada» estaba en `VALLE_SAGRADO` y recorría los mismos cinco sitios que
 * la tradicional —Pisac, mercado, Urubamba, Ollantaytambo, Chinchero—. Lo único distinto era el
 * primer segmento, con Guía y Transporte «Super Valle», entre un 30 y un 50 % más caros. Pero lo
 * que define al VIP —lo que lleva el compartido— son **Maras y Moray**, y no estaban.
 *
 * Tres pruebas de que es de `VALLE_VIP`:
 *
 *   · su ancla, `SAL_EXC-VALLE_VIP-PRIV`, sólo está en el pool de VALLE_VIP: la plantilla vivía
 *     en un servicio que ni siquiera ofrecía su primer segmento;
 *   · su logística —Guiado y Transporte Super Valle— son componentes de VALLE_VIP;
 *   · el pool de VALLE_VIP ya tiene la ruta VIP entera, así que moverla no le quita nada.
 *
 * El doc de agosto decidió no moverla porque «mover de servicio cambia qué segmentos ofrece su
 * pool». Era cierto, y aquí ese cambio es justo el arreglo.
 *
 * Y el VIP compartido tenía dos segmentos en el orden 3 —Salineras y las ruinas de Pisac—,
 * ninguno en el 7 y ningún retorno. Pisac va en el hueco, que además es el orden del camino.
 *
 * ⚠️ **Mover = `setServicio()` desde la plantilla, y NADA más.** `TravelServicio::$itinerarios`
 * lleva `orphanRemoval`: llamar a `removeItinerario()` en el servicio viejo **borraría la
 * plantilla** al hacer flush. Cambiando el servicio desde el lado dueño es un UPDATE.
 */
#[AsCommand(
    name: 'app:travel:reordenar-valle-vip',
    description: 'Pasa la VIP privada a VALLE_VIP con la ruta de Maras y Moray, y ordena el VIP compartido.',
    hidden: true,
)]
final class ReordenarValleVipCommand extends Command
{
    private const SERVICIO_VIP = 'VALLE_VIP';
    private const PLANTILLA_PRIVADA = '1D VALLE VIP PRIV';

    /**
     * El recorrido de cada plantilla, en orden. Todo el día 1.
     *
     * @var array<string, list<string>>
     */
    private const RUTAS = [
        '1D VALLE VIP PRIV' => [
            'SAL_EXC-VALLE_VIP-PRIV',
            'VIS-VALLE_VIP/MARAS_MORAY-CHINCHERO',
            'VIS-VALLE_VIP/MARAS_MORAY-MARAS',
            'VIS-VALLE_VIP/MARAS_MORAY-MORAY',
            'ALM-BUFFET-URU',
            'VIS-VALLE_SAGRADO-OLL',
            'VIS-VALLE_SAGRADO-PISAC-INCA',
            'VIS-VALLE_SAGRADO-PISAC-COLONIAL',
            'RET_EXC-HOTEL-CUSCO',
        ],
        '1D VALLE VIP POOL' => [
            'SAL_EXC-VALLE_VIP-POOL',
            'VIS-VALLE_VIP/MARAS_MORAY-CHINCHERO',
            'VIS-VALLE_VIP/MARAS_MORAY-MARAS',
            'VIS-VALLE_VIP/MARAS_MORAY-MORAY',
            'ALM-BUFFET-URU',
            'VIS-VALLE_SAGRADO-OLL',
            'VIS-VALLE_SAGRADO-PISAC-INCA',
            'VIS-VALLE_SAGRADO-PISAC-COLONIAL',
            'RET_EXC-CENTRO-CUS',
        ],
    ];

    /**
     * Se llamaba «Descanso en el Valle Sagrado» —nombre y título público— igual que
     * `RET_EXC-HOTEL-VALLE`, cuando uno deja en el hotel de Cusco y el otro se queda en el Valle.
     * El cliente leía «Descanso en el Valle Sagrado» encima de un texto que hablaba de Cusco.
     */
    private const RETORNO_CUSCO = [
        'slug' => 'RET_EXC-HOTEL-CUSCO',
        'nombre' => 'Retorno al hotel en Cusco',
        'titulo' => 'Retorno al hotel en Cusco',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $io->title('Valle VIP');

        $vip = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => self::SERVICIO_VIP]);
        if ($vip === null) {
            $io->error(sprintf('No existe el servicio %s.', self::SERVICIO_VIP));

            return Command::FAILURE;
        }

        // ── 1 · la privada, a su servicio ───────────────────────────────────────────────
        $io->section('Servicio de la VIP privada');
        $privada = $this->plantilla(self::PLANTILLA_PRIVADA);

        if ($privada === null) {
            $io->error(sprintf('No existe la plantilla «%s».', self::PLANTILLA_PRIVADA));

            return Command::FAILURE;
        }

        if ($privada->getServicio() === $vip) {
            $io->text('  ya está en VALLE_VIP.');
        } else {
            $io->text(sprintf(
                '  %s · %s → %s',
                $simula ? 'haría ' : 'hecho ',
                $privada->getServicio()?->getCodigo() ?? '?',
                self::SERVICIO_VIP,
            ));

            if (!$simula) {
                // ⚠️ Desde la plantilla, nunca con removeItinerario(): ver el docblock.
                $privada->setServicio($vip);
            }
        }

        // ── 2 · los recorridos ──────────────────────────────────────────────────────────
        $cambios = 0;

        foreach (self::RUTAS as $slugPlantilla => $ruta) {
            $io->section($slugPlantilla);
            $itinerario = $this->plantilla($slugPlantilla);

            if ($itinerario === null) {
                $io->error(sprintf('No existe la plantilla «%s».', $slugPlantilla));

                return Command::FAILURE;
            }

            $segmentos = [];
            foreach ($ruta as $slug) {
                $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]);
                if ($segmento === null) {
                    $io->error(sprintf('No existe el segmento «%s».', $slug));

                    return Command::FAILURE;
                }
                $segmentos[] = $segmento;
            }

            /** @var list<TravelItinerarioSegmentoRel> $rels */
            $rels = $this->em->getRepository(TravelItinerarioSegmentoRel::class)
                ->findBy(['itinerario' => $itinerario]);

            $libres = $rels;

            foreach ($segmentos as $pos => $segmento) {
                $orden = $pos + 1;
                $rel = null;

                foreach ($libres as $k => $candidato) {
                    if ($candidato->getSegmento() === $segmento) {
                        $rel = $candidato;
                        unset($libres[$k]);
                        break;
                    }
                }

                if ($rel === null) {
                    ++$cambios;
                    $io->text(sprintf('  %s · añade  %-2d %s', $simula ? 'haría ' : 'hecho ', $orden, $segmento->getSlug()));

                    if (!$simula) {
                        $this->em->persist(
                            (new TravelItinerarioSegmentoRel())
                                ->setItinerario($itinerario)
                                ->setSegmento($segmento)
                                ->setDia(1)
                                ->setOrden($orden),
                        );
                    }

                    continue;
                }

                if ($rel->getOrden() !== $orden || $rel->getDia() !== 1) {
                    ++$cambios;
                    $io->text(sprintf('  %s · mueve  %-2d → %-2d %s', $simula ? 'haría ' : 'hecho ', $rel->getOrden(), $orden, $segmento->getSlug()));

                    if (!$simula) {
                        $rel->setDia(1);
                        $rel->setOrden($orden);
                    }
                }
            }

            foreach ($libres as $sobra) {
                ++$cambios;
                $io->text(sprintf('  %s · quita  %s', $simula ? 'haría ' : 'hecho ', $sobra->getSegmento()?->getSlug() ?? '?'));

                if (!$simula) {
                    $this->em->remove($sobra);
                }
            }
        }

        // ── 3 · el retorno mal nombrado ─────────────────────────────────────────────────
        $io->section('Retorno al hotel en Cusco');
        $retorno = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => self::RETORNO_CUSCO['slug']]);

        if ($retorno === null) {
            $io->error(sprintf('No existe el segmento «%s».', self::RETORNO_CUSCO['slug']));

            return Command::FAILURE;
        }

        // Comprobación en PHP y de traducción: ver `docs/TravelCargaDeCatalogo.md` §4 ter.
        $traducido = count($retorno->getTitulo()) > 1;

        if ($retorno->getNombreInterno() === self::RETORNO_CUSCO['nombre']
            && $traducido
            && ($retorno->getTitulo()[0]['content'] ?? '') === self::RETORNO_CUSCO['titulo']) {
            $io->text('  ya está.');
        } else {
            ++$cambios;
            $io->text(sprintf('  %s · «%s» → «%s»', $simula ? 'haría ' : 'hecho ', $retorno->getNombreInterno(), self::RETORNO_CUSCO['titulo']));

            if (!$simula) {
                $retorno->setSobreescribirTraduccion(true);
                $retorno->setNombreInterno(self::RETORNO_CUSCO['nombre']);
                $retorno->setTitulo([['language' => 'es', 'content' => self::RETORNO_CUSCO['titulo']]]);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf('%s %d cambio(s).', $simula ? 'Se harían' : 'Hechos', $cambios));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    private function plantilla(string $slug): ?TravelItinerario
    {
        return $this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => $slug]);
    }
}
