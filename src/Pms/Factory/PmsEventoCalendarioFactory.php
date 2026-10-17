<?php
declare(strict_types=1);

namespace App\Pms\Factory;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Service\Reserva\NochesExtraDeEstancia;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PmsEventoCalendarioFactory.
 *
 * Autoridad central para:
 * - Crear eventos (UI / Import).
 * - Construir la estructura de links Beds24 del evento.
 *
 * ✅ ESTRATEGIA "SMART MOVE" (Establecimiento Virtual):
 * - Al cambiar de unidad física, busca coincidencias por código de Establecimiento Virtual (ej: "SAPHY").
 * - Si encuentra coincidencia, MUEVE el link (UPDATE).
 * - Si NO la encuentra, el principal se rescata SÓLO dentro de su mismo virtual; si no puede, lo
 *   releva el espejo de ese virtual. Lo que sobra se suelta y se RETIRA en Beds24 (cancelada).
 */
class PmsEventoCalendarioFactory
{

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NochesExtraDeEstancia $nochesExtra = new NochesExtraDeEstancia(),
    ) {}
    /**
     * Calcula la fecha exacta aplicando las horas de check-in/out del establecimiento.
     */
    public function resolveFechaConHora(?string $fechaYmd, ?PmsEstablecimiento $establecimiento, bool $isCheckIn): ?DateTimeImmutable
    {
        if (!$fechaYmd) {
            return null;
        }

        try {
            $base = new DateTimeImmutable($fechaYmd);
            $hora = $isCheckIn ? $establecimiento?->getHoraCheckIn() : $establecimiento?->getHoraCheckOut();

            return $hora
                ? $base->setTime((int) $hora->format('H'), (int) $hora->format('i'))
                : $base->setTime($isCheckIn ? 14 : 10, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    public function createForUi(): PmsEventoCalendario
    {
        $evento = new PmsEventoCalendario();

        $inicio = new DateTimeImmutable('tomorrow 14:00');
        $fin    = $inicio->modify('+2 days')->setTime(10, 0);

        $evento->setInicio($inicio);
        $evento->setFin($fin);
        $evento->setCantidadAdultos(2);
        $evento->setCantidadNinos(0);
        $evento->setMonto('0.00');
        $evento->setComision('0.00');
        $evento->setIsOta(false);

        $canalDirecto = $this->em->getReference(PmsChannel::class, PmsChannel::CODIGO_DIRECTO);
        if ($canalDirecto) {
            $evento->setChannel($canalDirecto);
        }

        return $evento;
    }

    public function createFromBeds24Import(
        PmsUnidad $unidad,
        ?string $fechaInicio,
        ?string $fechaFin,
        string $beds24BookId,
        int $beds24RoomId,
        bool $isOta
    ): PmsEventoCalendario {
        $evento = new PmsEventoCalendario();
        $est = $unidad->getEstablecimiento();

        $evento->setPmsUnidad($unidad);
        $evento->setInicio($this->resolveFechaConHora($fechaInicio, $est, true));
        $evento->setFin($this->resolveFechaConHora($fechaFin, $est, false));

        $evento->setCantidadAdultos(1);
        $evento->setMonto('0.00');
        $evento->setIsOta($isOta);

        $this->internalHydrate($evento, $beds24BookId, $beds24RoomId);

        return $evento;
    }

    public function hydrateLinksForUi(PmsEventoCalendario $evento): void
    {
        $this->internalHydrate($evento, null, null);
    }

    public function rebuildLinks(PmsEventoCalendario $evento, ?string $bookId = null, ?int $roomId = null): void
    {
        $this->internalHydrate($evento, $bookId, $roomId);
    }

    /**
     * @deprecated usa rebuildLinks()
     */
    public function regenerateLinks(PmsEventoCalendario $evento, ?string $bookId = null, ?int $roomId = null): void
    {
        $this->rebuildLinks($evento, $bookId, $roomId);
    }

    /**
     * Todos los links del evento: los de la estancia y los de sus noches extra.
     *
     * Las dos piezas van juntas porque las dos dependen de la casita: al mover la estancia, la
     * `black` de su entrada temprana tiene que irse con ella. Cada una toca sólo los suyos.
     */
    private function internalHydrate(PmsEventoCalendario $evento, ?string $externalBookId, ?int $externalRoomId): void
    {
        $this->hidratarLinksDeEstancia($evento, $externalBookId, $externalRoomId);
        $this->nochesExtra->sincronizar($evento);
    }

    /**
     * Lógica central de hidratación (Smart Move).
     */
    private function hidratarLinksDeEstancia(PmsEventoCalendario $evento, ?string $externalBookId, ?int $externalRoomId): void
    {
        $unidad = $evento->getPmsUnidad();
        if (!$unidad instanceof PmsUnidad) {
            return;
        }

        // 0) Maps activos de la NUEVA unidad
        $newMaps = $unidad->getBeds24MapsActivos();

        // 1) Detectar contexto
        $cameFromWebhook = ($externalBookId !== null && $externalRoomId !== null);

        // ⚠️ La nueva unidad no está en Beds24.
        //
        // Desde un webhook no se toca nada: el mapa por el que llegó ES de esta unidad, así que
        // sólo puede pasar con un mapeo desactivado, y eso no es motivo para soltar reservas.
        //
        // Desde la UI es un MOVIMIENTO a una casita que no se vende fuera (es la única vez que se
        // rehidrata una estancia con links: al cambiar de casita). Hasta el 17/10/2026 aquí se
        // volvía sin más, «inconsistente pero mejor que borrar IDs», y los links seguían en la
        // casita vieja: sus reservas la bloqueaban en Beds24 para siempre. Se sueltan, y al irse
        // el listener de la cola las RETIRA allí —las cancela, no las borra: ver
        // `Beds24BookingsPushQueueCreator::enqueueRetirada()`—. Los extra los lleva su servicio.
        if ($newMaps === []) {
            if (!$cameFromWebhook) {
                foreach ($evento->getLinksDeEstancia() as $huerfano) {
                    $evento->removeBeds24Link($huerfano);
                }
            }

            return;
        }

        // ---------------------------------------------------------------------
        // 2) CATALOGAR SUPERVIVIENTES (Links actuales)
        // ---------------------------------------------------------------------
        $targetBookId = $externalBookId;
        $principalSurvivor = null;

        // Indexamos los espejos por el CÓDIGO de su Establecimiento Virtual (Ej: 'INTI', 'SAPHY')
        $groupedSurvivors = [];
        $genericSurvivors = [];

        $rescuedLastSeenAt = null;

        // A. Buscar ID objetivo si no viene de fuera (UI)
        // 🔥 Sólo los links de la ESTANCIA. Los de un horario extra (rol `extra_*`) no son ni
        // principal ni espejo: si entraran aquí, el que no encontrara pareja acabaría en los
        // sobrantes, y un sobrante se borra con su DELETE a Beds24 — la noche extra quedaría
        // libre sin que nadie la hubiera desmarcado. Los gestiona su propio servicio.
        $linksDeEstancia = $evento->getLinksDeEstancia();

        if ($targetBookId === null) {
            foreach ($linksDeEstancia as $link) {
                if ($link->getBeds24BookId() !== null) {
                    $targetBookId = $link->getBeds24BookId();
                    if ($link->isEsPrincipal()) break;
                }
            }
        }

        // B. Clasificar links existentes
        foreach ($linksDeEstancia as $link) {
            // ¿Es este el link principal? Normalmente se identifica por llevar el ID
            // objetivo, pero una estancia recién creada todavía no tiene ninguno: hasta que
            // el Push la sella (§6.3) TODOS sus links valen `null`. Sin el segundo criterio
            // —el flag `esPrincipal`— ningún link se reconocía como superviviente, así que
            // el principal se borraba y se creaba otro en su lugar; ese vaivén dentro del
            // mismo flush reventaba con «A managed+dirty entity Link … can not be scheduled
            // for insertion» e impedía mover de casita cualquier reserva sin sincronizar.
            $esElPrincipal = $targetBookId !== null
                ? $link->getBeds24BookId() === $targetBookId
                : $link->isEsPrincipal();

            if ($esElPrincipal && $principalSurvivor === null) {
                $principalSurvivor = $link;
                $rescuedLastSeenAt = $link->getLastSeenAt();
            } else {
                // Es un espejo candidato a reciclaje.
                $virtualCode = $link->getUnidadBeds24Map()?->getVirtualEstablecimiento()?->getCodigo();

                if ($virtualCode) {
                    $groupedSurvivors[$virtualCode] = $link;
                } else {
                    $genericSurvivors[] = $link;
                }
            }
        }

        // ---------------------------------------------------------------------
        // 3) DETERMINAR MAPA PRINCIPAL (En la NUEVA unidad)
        // ---------------------------------------------------------------------
        $principalMap = null;

        if ($cameFromWebhook) {
            // Webhook: Match exacto por RoomID de Beds24
            foreach ($newMaps as $m) {
                if ($m->getBeds24RoomId() === $externalRoomId) {
                    $principalMap = $m;
                    break;
                }
            }
            // Fallback
            if ($principalMap === null) {
                foreach ($newMaps as $m) {
                    if ($m->getVirtualEstablecimiento()?->isEsPrincipal()) {
                        $principalMap = $m;
                        break;
                    }
                }
            }
        } else {
            // UI: Buscamos el Virtual marcado como PRINCIPAL en la nueva unidad
            foreach ($newMaps as $m) {
                if ($m->getVirtualEstablecimiento()?->isEsPrincipal()) {
                    $principalMap = $m;
                    break;
                }
            }

            // Fallback UI
            if ($principalMap === null) {
                foreach ($newMaps as $m) {
                    if ($m->isEsPrincipal()) {
                        $principalMap = $m;
                        break;
                    }
                }
            }
        }

        // Fallback Final: Si no hay principal definido, usamos el primero.
        if ($principalMap === null) {
            $principalMap = $newMaps[0];
        }

        // ---------------------------------------------------------------------
        // 4) RECONSTRUCCIÓN (Mover y Asignar)
        // ---------------------------------------------------------------------
        $usedLinks = [];

        foreach ($newMaps as $map) {
            $isPrincipalMap = ($map === $principalMap);
            $linkToUse = null;

            if ($isPrincipalMap) {
                // ✅ RESCATE DEL PRINCIPAL: el link principal viaja al mapa principal de la casita
                // nueva y se lleva su reserva de Beds24 —un UPDATE que la cambia de habitación—.
                //
                // 🚫 **Salvo que eso la haga cruzar de establecimiento virtual.** (17/10/2026) En
                // Beds24 una reserva es de una propiedad: la de SAPHY no se puede mover a una
                // habitación de INTI. Antes se rescataba igual —el comentario decía «así no se
                // pierde el ID»—, el push fallaba y la reserva de SAPHY se quedaba viva bloqueando
                // la casita vieja, mientras el espejo que sí estaba en INTI se tiraba. Los espejos
                // ya respetaban esta regla; el principal no.
                //
                // Ahora, si no puede viajar, el principal se suelta (y se retira en Beds24) y su
                // papel lo toma el ESPEJO que ya había en ese virtual, con su reserva: el push lo
                // rellena con los datos de la estancia. Si no hay espejo allí, nace uno nuevo.
                //
                // Desde un webhook no se aplica: el id y la habitación los acaba de decir Beds24.
                // Y una estancia sin id todavía puede ir donde sea: no hay nada que cruzar.
                $principalViaja = $principalSurvivor !== null && (
                    $cameFromWebhook
                    || $principalSurvivor->getBeds24BookId() === null
                    || self::mismoVirtual($principalSurvivor, $map)
                );

                $relevo = null;
                if ($principalViaja) {
                    $linkToUse = $principalSurvivor;
                } else {
                    $codigo = $map->getVirtualEstablecimiento()?->getCodigo();
                    if ($principalSurvivor !== null && $codigo !== null && isset($groupedSurvivors[$codigo])) {
                        $relevo = $groupedSurvivors[$codigo];
                        unset($groupedSurvivors[$codigo]);
                    }

                    $linkToUse = $relevo;
                }

                if ($linkToUse === null) {
                    $linkToUse = new PmsEventoBeds24Link();
                    $linkToUse->setEvento($evento);
                    $evento->addBeds24Link($linkToUse);
                }

                $linkToUse->hacerPrincipal();

                if ($principalSurvivor !== null && !$principalViaja) {
                    // Conserva la reserva del relevo, o ninguna si es nuevo: el id del principal
                    // viejo es de otra propiedad.
                    $linkToUse->setBeds24BookId($relevo?->getBeds24BookId());
                } elseif ($targetBookId !== null) {
                    $linkToUse->setBeds24BookId($targetBookId);
                    if ($cameFromWebhook) {
                        $linkToUse->setLastSeenAt(new DateTimeImmutable());
                    } elseif ($rescuedLastSeenAt !== null) {
                        $linkToUse->setLastSeenAt($rescuedLastSeenAt);
                    }
                } else {
                    $linkToUse->setBeds24BookId(null);
                }

            } else {
                // --- GESTIÓN DE ESPEJOS (MIRRORS) ---

                // 1. Intentamos match por CÓDIGO VIRTUAL (Ej: 'INTI' -> 'INTI')
                $virtualCode = $map->getVirtualEstablecimiento()?->getCodigo();

                // ¿Este espejo conserva su reserva en Beds24 o le toca una nueva?
                $mueveDentroDelMismoVirtual = false;

                if ($virtualCode && isset($groupedSurvivors[$virtualCode])) {
                    // ✅ MATCH EXACTO: mismo establecimiento virtual, sólo cambia el room.
                    // Es un MOVIMIENTO, no una reserva distinta: se conserva el
                    // beds24BookId igual que hace el principal unas líneas más arriba, y el
                    // Push lo traduce en un UPDATE que mueve la reserva de habitación.
                    $linkToUse = $groupedSurvivors[$virtualCode];
                    unset($groupedSurvivors[$virtualCode]); // Consumido
                    $mueveDentroDelMismoVirtual = true;
                }
                // 2. Fallback FIFO — sólo sobre links SIN id remoto.
                //    Reciclar aquí un link que ya tiene beds24BookId lo mandaría a otro
                //    establecimiento virtual, y como abajo se le limpia el ID, esa reserva
                //    quedaría viva en Beds24 sin que ningún link la reclame: noches
                //    bloqueadas para siempre y sin DELETE que las libere. Los que traen ID
                //    se dejan en los sobrantes, que sí se borran y disparan su DELETE (§5).
                else {
                    foreach ($genericSurvivors as $i => $candidato) {
                        if ($candidato->getBeds24BookId() === null) {
                            $linkToUse = $candidato;
                            unset($genericSurvivors[$i]);
                            $genericSurvivors = array_values($genericSurvivors);
                            break;
                        }
                    }
                }

                // 3. Nuevo
                if ($linkToUse === null) {
                    $linkToUse = new PmsEventoBeds24Link();
                    $linkToUse->setEvento($evento);
                    $evento->addBeds24Link($linkToUse);
                }

                $linkToUse->setEsPrincipal(false);

                // Un espejo nuevo nace sin ID: Beds24 aún no conoce esa reserva en el
                // listing espejo y el Push se lo sella al crearla (§6.3). Pero si sólo se
                // está moviendo de room dentro de su mismo virtual, borrar el ID sería
                // perderle la pista a una reserva que sigue existiendo.
                if (!$mueveDentroDelMismoVirtual) {
                    $linkToUse->setBeds24BookId(null);
                }
            }

            $linkToUse->markActive();
            // ✅ AQUÍ OCURRE EL MOVIMIENTO FÍSICO EN BD
            $linkToUse->setUnidadBeds24Map($map);

            $usedLinks[] = $linkToUse;
        }

        // ---------------------------------------------------------------------
        // 5) LIMPIEZA DE SOBRANTES
        // ---------------------------------------------------------------------
        // Aquí caen los links espejos que no encontraron su "pareja" en la nueva unidad.
        $leftovers = array_merge(array_values($groupedSurvivors), $genericSurvivors);

        // El principal que no pudo viajar a otro establecimiento virtual (ver el rescate).
        if ($principalSurvivor && !in_array($principalSurvivor, $usedLinks, true)) {
            $leftovers[] = $principalSurvivor;

            // La reserva no puede seguir agrupándose con una que se va a cancelar: el push de la
            // nueva le mandaría ese `masterId`. Se vacían y el primer push los vuelve a sembrar
            // (`BookingsPushHandler::handleSuccess()`).
            $reserva = $evento->getReserva();
            $idViejo = $principalSurvivor->getBeds24BookId();
            if ($reserva !== null && $idViejo !== null) {
                if ($reserva->getBeds24MasterId() === $idViejo) {
                    $reserva->setBeds24MasterId(null);
                }
                if ($reserva->getBeds24BookIdPrincipal() === $idViejo) {
                    $reserva->setBeds24BookIdPrincipal(null);
                }
            }
        }

        foreach ($leftovers as $unused) {
            // No limpiar beds24BookId aquí: el PushQueueListener lo lee durante onFlush para
            // RETIRAR la reserva en Beds24 (cancelarla: un DELETE sobre una activa lo rechaza).
            // Con orphanRemoval=true, Doctrine borra la fila del link — el ID ya no hace falta.
            $evento->removeBeds24Link($unused);
        }
    }

    /**
     * ¿El link está en el mismo establecimiento virtual que el mapa? Si a alguno le falta el
     * código no se puede saber, y se le deja viajar como antes.
     */
    private static function mismoVirtual(PmsEventoBeds24Link $link, PmsUnidadBeds24Map $map): bool
    {
        $actual = $link->getUnidadBeds24Map()?->getVirtualEstablecimiento()?->getCodigo();
        $destino = $map->getVirtualEstablecimiento()?->getCodigo();

        return $actual === null || $destino === null || $actual === $destino;
    }
}
