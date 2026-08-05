<?php
declare(strict_types=1);

namespace App\Pms\Factory;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
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
 * - Si NO encuentra coincidencia, RESCATA el principal y ELIMINA los secundarios.
 */
class PmsEventoCalendarioFactory
{

    public function __construct(
        private readonly EntityManagerInterface $em
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
     * Lógica central de hidratación (Smart Move).
     */
    private function internalHydrate(PmsEventoCalendario $evento, ?string $externalBookId, ?int $externalRoomId): void
    {
        $unidad = $evento->getPmsUnidad();
        if (!$unidad instanceof PmsUnidad) {
            return;
        }

        // 0) Maps activos de la NUEVA unidad
        $newMaps = $this->getActiveMaps($unidad);

        // ⚠️ CASO CRÍTICO: Si la nueva unidad no tiene mapas, no hacemos nada.
        // Los links se quedan apuntando a la unidad vieja (inconsistente pero mejor que borrar IDs).
        if ($newMaps === []) {
            return;
        }

        // 1) Detectar contexto
        $cameFromWebhook = ($externalBookId !== null && $externalRoomId !== null);

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
        if ($targetBookId === null) {
            foreach ($evento->getBeds24Links() as $link) {
                if ($link->getBeds24BookId() !== null) {
                    $targetBookId = $link->getBeds24BookId();
                    if ($link->isEsPrincipal()) break;
                }
            }
        }

        // B. Clasificar links existentes
        foreach ($evento->getBeds24Links() as $link) {
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
                // ✅ RESCATE DEL PRINCIPAL:
                // Si teníamos un link principal ("SAPHY"), pero la nueva unidad no tiene "SAPHY",
                // y el nuevo mapa principal es "INTI", aquí forzamos que el link "SAPHY"
                // se convierta en el link del mapa "INTI".
                // ASÍ NO SE PIERDE EL ID DE LA RESERVA.
                if ($principalSurvivor) {
                    $linkToUse = $principalSurvivor;
                } else {
                    $linkToUse = new PmsEventoBeds24Link();
                    $linkToUse->setEvento($evento);
                    $evento->addBeds24Link($linkToUse);
                }

                $linkToUse->hacerPrincipal();

                if ($targetBookId !== null) {
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

        // Doble chequeo de seguridad para el principal
        if ($principalSurvivor && !in_array($principalSurvivor, $usedLinks, true)) {
            // Esto teóricamente no debería pasar gracias a la lógica de arriba,
            // pero si pasara, lo forzamos a borrarse para evitar inconsistencias graves.
            // (En realidad, el código de arriba garantiza que el principal siempre se usa).
            $leftovers[] = $principalSurvivor;
        }

        foreach ($leftovers as $unused) {
            // No limpiar beds24BookId aquí: el PushQueueListener lo lee durante onFlush
            // para construir el DELETE hacia Beds24. Con orphanRemoval=true, Doctrine
            // programa el DELETE de la fila completa — el ID ya no hace falta después.
            $evento->removeBeds24Link($unused);
        }
    }

    private function getActiveMaps(PmsUnidad $unidad): array
    {
        $active = [];
        foreach ($unidad->getBeds24Maps() as $map) {
            if ($map instanceof PmsUnidadBeds24Map && $map->isActivo()) {
                $active[] = $map;
            }
        }
        return $active;
    }
}