<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Contract\ConversationMilestoneInterface;
use App\Message\Entity\MessageConversation;
use App\Contract\VinculoComercial;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Service\Message\PmsProveedorDeEnlaces;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Message\PmsHitosDeEstancia;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crea el ENLACE que le falta a cada conversación de alojamiento.
 *
 * Segundo paso de la cirugía (ver docs/Mensajeria.md §20): las conversaciones ya apuntan a una
 * reserva por `context_type`/`context_id`, pero apuntan a **una sola**, y por eso hace falta una
 * conversación por reserva. Este comando copia ese apuntador a `pms_conversacion_enlace`, que
 * admite varios por hilo.
 *
 * ── Es una COPIA, y las columnas viejas siguen mandando ─────────────────────
 * Aditivo por completo: no toca `context_type`, ni `context_id`, ni `context_data`, ni borra ni
 * fusiona nada. Al terminar, cada conversación de alojamiento tiene exactamente **un** enlace
 * con los mismos datos que ya tenía dentro del JSON. Nada lo lee todavía —el motor de reglas
 * sigue programando por conversación—, así que se puede ejecutar en producción y volver atrás
 * borrando la tabla.
 *
 * Los hilos duplicados de una misma persona se fusionarán después, y entonces sus enlaces se
 * juntarán bajo una sola conversación: por eso el enlace nace ahora, para que la fusión tenga
 * dónde poner cada asunto en vez de tener que elegir uno.
 *
 * ── Qué se copia ────────────────────────────────────────────────────────────
 * Lo del ACTIVO y sólo eso: `vinculo`, `milestones`, `origin`, `agency`, `status_tag`. Los
 * `financials` e `items` del mismo JSON se quedan donde están —son una foto de la cuenta para
 * el motor de plantillas y su sitio natural es `PmsInformacionFinanciera`, no una copia más—.
 *
 * Uso:
 *   php bin/console app:conversaciones:enlazar --dry-run
 *   php bin/console app:conversaciones:enlazar
 */
#[AsCommand(
    name: 'app:conversaciones:enlazar',
    description: 'Crea el PmsConversacionEnlace de cada conversación de alojamiento que no lo tenga.'
)]
final class PoblarEnlacesCommand extends Command
{
    /** Cada cuántos enlaces se vuelca a la base: evita un flush de miles de objetos al final. */
    private const int LOTE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsHitosDeEstancia $hitos,
        private readonly PmsProveedorDeEnlaces $proveedor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $io = new SymfonyStyle($input, $output);

        $conversaciones = $this->em->getRepository(MessageConversation::class)
            ->findBy(['contextType' => PmsConversacionEnlace::CONTEXT_TYPE]);

        $io->text(sprintf('Conversaciones de alojamiento: %d', count($conversaciones)));

        $creados = 0;
        $yaTenian = 0;
        $canceladas = 0;
        $refrescados = 0;
        $huerfanas = [];

        foreach ($conversaciones as $conversacion) {
            if ($this->proveedor->paraConversacion($conversacion) !== []) {
                $yaTenian++;

                // No se salta: se le REFRESCAN los hitos. Un enlace poblado antes de que
                // existieran los tenía vacíos, y este comando es reejecutable a propósito — que
                // «ya existe» signifique «no lo miro» convierte cada mejora del cálculo en una
                // migración de datos a mano.
                if (!$dryRun) {
                    foreach ($this->proveedor->paraConversacion($conversacion) as $existente) {
                        $reservaExistente = $existente->getReserva();

                        if ($reservaExistente !== null) {
                            // Se repone también el mapa del contexto: los enlaces poblados por la
                            // primera versión de este comando tienen `start` sacado del agregado,
                            // y sólo reponiéndolo antes puede `setHitos()` corregirlo.
                            $existente->setMilestones($conversacion->getMapaDeHitos());
                            $existente->setHitos($this->hitos->para($reservaExistente));
                            $refrescados++;
                        }
                    }
                }

                continue;
            }

            $reserva = $this->em->getRepository(PmsReserva::class)->find($conversacion->getContextId());

            // Una conversación que apunta a una reserva borrada. No se inventa el enlace: se
            // deja constancia y se sigue. Es un dato roto que ya existía —la FK vieja es un
            // string sin integridad referencial—, y taparlo aquí sería esconder el problema
            // justo cuando por fin hay una FK de verdad que lo impediría en adelante.
            if ($reserva === null) {
                $huerfanas[] = (string) $conversacion->getId();
                continue;
            }

            $creados++;

            if ($dryRun) {
                continue;
            }

            // ── Las canceladas también se enlazan, MARCADAS ─────────────────
            // La primera versión las saltaba, por miedo a que el motor regenerara mensajes sobre
            // la basura de la base —106 de 310 conversaciones cuelgan de una reserva cancelada,
            // muchas duplicados de un bug de guardado—. El miedo era correcto y la solución era
            // la equivocada: perder el dato.
            //
            // Marcadas con su hito de cancelación, el motor las trata como asuntos muertos y no
            // encola ni un mensaje —la guarda está en `AgendaDeAsunto::estaMuerta()`, no aquí—,
            // pero el hilo conserva la historia completa de esa persona: qué reservó y qué se
            // canceló. Y es lo único que permite plantearse después si el aviso de cancelación
            // vuelve: sin enlace no hay hito que leer.
            $cancelada = $reserva->isCancelada();

            $enlace = new PmsConversacionEnlace($conversacion, $reserva);
            $enlace->setVinculo($cancelada ? VinculoComercial::Terminado : ($conversacion->getContextVinculo() ?? $enlace->getVinculo()));
            $enlace->setOrigen($conversacion->getContextOrigin());
            $enlace->setAgencia($conversacion->getContextAgency());
            $enlace->setStatusTag($conversacion->getContextStatusTag());

            // Los hitos se DERIVAN de los tramos, no se copian del JSON de la conversación. Ese
            // JSON trae `start`/`end` sacados del mínimo y el máximo de la reserva, y copiarlo
            // habría heredado el defecto que este trabajo persigue: una estancia partida
            // aplastada en una sola ventana. `setHitos()` mantiene además el mapa plano en
            // sintonía, para que las reglas de hoy sigan encontrando `start` y `end`.
            // El mapa del contexto primero —trae `created_at` y `expected_arrival`, que no salen
            // de los tramos— y los hitos derivados encima, que reescriben `start` y `end` con las
            // fechas reales de la estancia en vez del mínimo y el máximo agregados.
            $enlace->setMilestones($conversacion->getMapaDeHitos());
            $enlace->setHitos($this->hitos->para($reserva));

            if ($cancelada) {
                $enlace->marcarCancelado($conversacion->getMapaDeHitos()->obtener(ConversationMilestoneInterface::CANCELLED));
                $canceladas++;
            }

            $this->em->persist($enlace);

            if ($creados % self::LOTE === 0) {
                $this->em->flush();
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->table(
            ['Enlaces nuevos', 'Ya tenían (hitos refrescados)', 'De ellos, marcados cancelados', 'Reserva inexistente'],
            [[$creados, sprintf('%d (%d)', $yaTenian, $refrescados), $canceladas, count($huerfanas)]]
        );

        if ($huerfanas !== []) {
            $io->warning(sprintf(
                'Estas %d conversaciones apuntan a una reserva borrada y se quedan sin enlace: %s',
                count($huerfanas),
                implode(', ', array_slice($huerfanas, 0, 10)) . (count($huerfanas) > 10 ? '…' : '')
            ));
        }

        $dryRun
            ? $io->warning(sprintf('DRY RUN: se crearían %d enlaces.', $creados))
            : $io->success(sprintf('%d enlaces creados.', $creados));

        return Command::SUCCESS;
    }

}
