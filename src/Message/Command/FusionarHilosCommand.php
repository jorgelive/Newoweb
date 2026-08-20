<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageConversation;
use App\Message\Enum\IdentidadTipo;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Une los hilos duplicados de una misma persona.
 *
 * ## Qué arregla
 *
 * Había dos resolutores con claves distintas escribiendo en la misma tabla, y de ahí salieron
 * hilos repetidos por teléfono. Medido en producción: **un titular con 7 conversaciones creadas
 * el mismo día** por reservar 7 casitas, otro con 4, varios con 2. *«Cuando el agente le
 * atiende, ve una y el historial de las otras seis no existe para él.»*
 *
 * Los nombres lo confirman: «Adrián Tolaba» / «Adrián Tolsba», «Jimenez Susana» / «Susana
 * Jiménez Bustamante». La misma persona con erratas y variantes.
 *
 * ## Los mensajes no se pisan
 *
 * Son filas con su fecha: reasignarlas produce **una sola línea de tiempo continua**. No hay
 * nada que decidir ahí. Lo que sí choca es la cabecera, y cada campo tiene su regla —ver
 * `fusionarCabecera()`—.
 *
 * ## El hilo con quien tiene el teléfono
 *
 * Se agrupa por teléfono **sin mirar el nombre**, y es deliberado: la conversación es con quien
 * sostiene el móvil. Si una agencia reserva para cinco viajeros desde el mismo número, el hilo
 * es de la agencia y las cinco reservas son cinco asuntos suyos — que es exactamente el modelo.
 * Filtrar por «nombres parecidos» partiría ese caso sin ganar nada.
 *
 * ## ⚠️ Los hilos `staff` quedan fuera, de momento
 *
 * No por principio —una persona que además trabaja aquí sigue siendo una persona, y no hay forma
 * determinista de saber si su WhatsApp viene por su reserva o por su trabajo— sino porque
 * `EscalarAlEquipoSkill` busca los avisos recientes filtrando por `contextType = 'staff'`. Al
 * fusionar, esos mensajes dejarían de encontrarse y **el enfriamiento fallaría abierto: la
 * guardia volvería a sonar entera, de noche y sin causa evidente**. Son 2 hilos; se unen cuando
 * ese filtro deje de depender del `contextType`.
 *
 * ## Por qué NO escribe salvo que se lo pidan
 *
 * El resto de comandos del repo usan `--dry-run` como opción. Éste lo lleva al revés —simula
 * salvo `--aplicar`— porque mueve miles de mensajes y **no tiene vuelta atrás razonable**. Con
 * la convención normal, un despiste al teclear lo ejecuta.
 */
#[AsCommand(
    name: 'app:message:fusionar-hilos',
    description: 'Une los hilos duplicados de una misma persona. Simula salvo que se pase --aplicar.'
)]
final class FusionarHilosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('aplicar', null, InputOption::VALUE_NONE, 'Escribe de verdad. Sin esto sólo simula.')
            ->addOption('telefono', null, InputOption::VALUE_REQUIRED, 'Fusiona sólo este número.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $aplicar = (bool) $input->getOption('aplicar');
        $soloTelefono = $input->getOption('telefono');

        $grupos = $this->gruposDuplicados(is_string($soloTelefono) ? $soloTelefono : null);

        if ($grupos === []) {
            $io->success('No hay hilos duplicados que unir.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d persona(s) con hilos repetidos%s', count($grupos), $aplicar ? '' : ' — SIMULACRO'));

        $unidos = 0;
        $mensajesMovidos = 0;
        $saltados = [];

        foreach ($grupos as $telefono => $ids) {
            // Uno a uno y no con `IN (:ids)`: el id es un BINARY(16) y enlazar un array de
            // cadenas contra él no casa — el comando decía «20 grupos» y unificaba cero, sin
            // un solo error. Los grupos son de 2 a 7, así que el coste es irrelevante.
            $hilos = [];
            foreach ($ids as $id) {
                $hilo = $this->em->find(MessageConversation::class, Uuid::fromString($id));
                if ($hilo instanceof MessageConversation) {
                    $hilos[] = $hilo;
                }
            }

            // Manda la FECHA: sobrevive el más antiguo, que es el del historial largo.
            usort($hilos, static fn (MessageConversation $a, MessageConversation $b): int
                => ($a->getCreatedAt()?->getTimestamp() ?? 0) <=> ($b->getCreatedAt()?->getTimestamp() ?? 0));

            if (count($hilos) < 2) {
                continue;
            }

            if (($motivo = $this->motivoParaNoUnir($hilos)) !== null) {
                $saltados[] = [$telefono, count($hilos), $motivo];
                continue;
            }

            $superviviente = array_shift($hilos);
            $mensajes = $this->contarMensajes($hilos);

            $io->writeln(sprintf(
                "\n  <info>%s</info> · %d hilos → 1 · %d mensaje(s) se mueven",
                $telefono,
                count($hilos) + 1,
                $mensajes
            ));
            $io->writeln(sprintf('     sobrevive: %s  (%s)',
                $superviviente->getGuestName() ?? '—',
                $superviviente->getCreatedAt()?->format('d/m/Y') ?? '—'));

            foreach ($hilos as $absorbido) {
                $io->writeln(sprintf('     absorbe:   %s  (%s)',
                    $absorbido->getGuestName() ?? '—',
                    $absorbido->getCreatedAt()?->format('d/m/Y') ?? '—'));
            }

            if ($aplicar) {
                $this->fusionar($superviviente, $hilos);
            }

            ++$unidos;
            $mensajesMovidos += $mensajes;
        }

        if ($saltados !== []) {
            $io->section('Sin unir, para revisar a mano');
            $io->table(['Teléfono', 'Hilos', 'Motivo'], $saltados);
        }

        if ($aplicar) {
            $this->em->flush();
            $io->success(sprintf('%d persona(s) unificadas, %d mensaje(s) movidos.', $unidos, $mensajesMovidos));

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            '%d persona(s) se unificarían y %d mensaje(s) se moverían. Nada escrito — añade --aplicar.',
            $unidos,
            $mensajesMovidos
        ));

        return Command::SUCCESS;
    }

    /**
     * Hilos que comparten identidad de teléfono.
     *
     * Se agrupa por la tabla de identidades y no por `guest_phone`: es la que está normalizada,
     * así que `+51 984…` y `+51984…` caen juntos sin comparaciones a ojo.
     *
     * @return array<string, list<string>> teléfono => ids de conversación
     */
    private function gruposDuplicados(?string $soloTelefono): array
    {
        $sql = 'SELECT i.valor, LOWER(HEX(i.conversacion_id)) AS cid
                  FROM msg_identidad i
                 WHERE i.tipo = :tipo';
        $params = ['tipo' => IdentidadTipo::TELEFONO->value];

        if ($soloTelefono !== null) {
            $sql .= ' AND i.valor = :valor';
            $params['valor'] = IdentidadTipo::TELEFONO->normalizar($soloTelefono);
        }

        $grupos = [];
        foreach ($this->db->fetchAllAssociative($sql, $params) as $fila) {
            $grupos[(string) $fila['valor']][] = self::conGuiones((string) $fila['cid']);
        }

        // ⚠️ La tabla de identidades tiene UNA por hilo (el arrastre usó INSERT IGNORE), así que
        // los duplicados NO salen de ahí: hay que mirar también los `guest_phone` que quedaron
        // sin identidad justamente por chocar contra el índice único.
        $sql = 'SELECT REGEXP_REPLACE(c.guest_phone, \'[^0-9+]\', \'\') AS valor, LOWER(HEX(c.id)) AS cid
                  FROM msg_conversation c
                 WHERE c.guest_phone IS NOT NULL AND REGEXP_REPLACE(c.guest_phone, \'[^0-9+]\', \'\') <> \'\'';
        $params = [];

        if ($soloTelefono !== null) {
            $sql .= ' AND REGEXP_REPLACE(c.guest_phone, \'[^0-9+]\', \'\') = :valor';
            $params['valor'] = IdentidadTipo::TELEFONO->normalizar($soloTelefono);
        }

        foreach ($this->db->fetchAllAssociative($sql, $params) as $fila) {
            $valor = (string) $fila['valor'];
            $cid = self::conGuiones((string) $fila['cid']);

            if (!in_array($cid, $grupos[$valor] ?? [], true)) {
                $grupos[$valor][] = $cid;
            }
        }

        return array_filter($grupos, static fn (array $ids): bool => count($ids) > 1);
    }

    /**
     * `HEX()` devuelve 32 caracteres sin guiones y el tipo `uuid` de Doctrine espera la forma
     * canónica. Sin esto, el `IN (:ids)` no casa con nada y el comando dice «20 grupos» y
     * unifica cero — sin error, que es lo peor que podía hacer.
     */
    private static function conGuiones(string $hex): string
    {
        return strtolower(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        ));
    }

    /**
     * Por qué este grupo no se toca, o `null` si se puede unir.
     *
     * @param list<MessageConversation> $hilos
     */
    private function motivoParaNoUnir(array $hilos): ?string
    {
        foreach ($hilos as $hilo) {
            if ($hilo->getContextType() === 'staff') {
                return 'hay un hilo staff: rompería el enfriamiento del escalado';
            }
        }

        return null;
    }

    /**
     * Cuántos mensajes se moverían.
     *
     * @param list<MessageConversation> $hilos los que se absorben, sin el superviviente
     */
    private function contarMensajes(array $hilos): int
    {
        if ($hilos === []) {
            return 0;
        }

        $ids = array_map(static fn (MessageConversation $c): string => str_replace('-', '', (string) $c->getId()), $hilos);
        $marcadores = implode(',', array_fill(0, count($ids), 'UNHEX(?)'));

        return (int) $this->db->fetchOne(
            sprintf('SELECT COUNT(*) FROM msg_message WHERE conversation_id IN (%s)', $marcadores),
            $ids
        );
    }

    /**
     * Mueve todo al superviviente y archiva los absorbidos.
     *
     * ⚠️ **No se borra nada**, que es la regla del repo: un hilo absorbido queda archivado y con
     * una nota de dónde fue su contenido. Así un enlace del panel o una notificación que
     * apuntara a él sigue llevando a algo que explica qué pasó, en vez de a un 404.
     *
     * @param list<MessageConversation> $absorbidos
     */
    private function fusionar(MessageConversation $superviviente, array $absorbidos): void
    {
        $destino = (string) $superviviente->getId();

        foreach ($absorbidos as $absorbido) {
            $origen = (string) $absorbido->getId();

            $this->mover('msg_message', 'conversation_id', $origen, $destino);
            $this->mover('pms_conversacion_enlace', 'conversacion_id', $origen, $destino);
            $this->mover('cotizacion_conversacion_enlace', 'conversacion_id', $origen, $destino);
            $this->mover('msg_identidad', 'conversacion_id', $origen, $destino);

            $this->fusionarCabecera($superviviente, $absorbido);

            $absorbido->setStatus(MessageConversation::STATUS_ARCHIVED);
            $datos = $absorbido->getContextData() ?? [];
            $datos['fusionado_en'] = $destino;
            $absorbido->setContextData($datos);
        }
    }

    /**
     * `UPDATE IGNORE` porque las tablas destino tienen índices únicos —`(conversacion, reserva)`,
     * `(tipo, valor)`— y una fila que chocara dejaría al superviviente con la suya, que es la
     * correcta. Sin `IGNORE` la fusión entera se caería por un duplicado inofensivo.
     */
    private function mover(string $tabla, string $columna, string $origen, string $destino): void
    {
        $this->db->executeStatement(
            sprintf('UPDATE IGNORE %s SET %s = UNHEX(REPLACE(:destino, \'-\', \'\')) WHERE %s = UNHEX(REPLACE(:origen, \'-\', \'\'))', $tabla, $columna, $columna),
            ['destino' => $destino, 'origen' => $origen]
        );
    }

    /**
     * Las reglas de la cabecera, que es lo único que de verdad choca.
     *
     * - **nombre**: el más completo, no el más nuevo. «Susana Jiménez Bustamante» dice más que
     *   «Jimenez Susana», y la fecha ya decide quién sobrevive — no tiene por qué decidir todo.
     * - **idioma**: gana el fijado a mano; si ninguno, se queda el del superviviente.
     * - **estado**: abierto si alguno lo estaba. Cerrar es «no hay nada pendiente», y si en el
     *   otro hilo lo había, lo sigue habiendo.
     * - **WhatsApp desactivado**: si alguno lo estaba, se queda desactivado. Es una decisión de
     *   seguridad y va por el lado prudente.
     * - **ventana de 24 h**: la más lejana. Es del número, no del hilo.
     * - **resumen IA**: se borra. Resume una conversación que ya no es ésa; se regenera solo.
     */
    private function fusionarCabecera(MessageConversation $superviviente, MessageConversation $absorbido): void
    {
        $nombreA = trim((string) $superviviente->getGuestName());
        $nombreB = trim((string) $absorbido->getGuestName());
        if (mb_strlen($nombreB) > mb_strlen($nombreA)) {
            $superviviente->setGuestName($nombreB);
        }

        if (!$superviviente->isIdiomaFijado() && $absorbido->isIdiomaFijado()) {
            $superviviente->setIdioma($absorbido->getIdioma());
            $superviviente->setIdiomaFijado(true);
        }

        if ($absorbido->getStatus() === MessageConversation::STATUS_OPEN) {
            $superviviente->setStatus(MessageConversation::STATUS_OPEN);
        }

        if ($absorbido->isWhatsappDisabled()) {
            $superviviente->setWhatsappDisabled(true);
            $superviviente->setWhatsappDisabledReason($absorbido->getWhatsappDisabledReason());
        }

        $ventanaB = $absorbido->getWhatsappSessionValidUntil();
        $ventanaA = $superviviente->getWhatsappSessionValidUntil();
        if ($ventanaB !== null && ($ventanaA === null || $ventanaB > $ventanaA)) {
            $superviviente->setWhatsappSessionValidUntil($ventanaB);
        }

        $superviviente->setResumenIa(null);
    }
}
