<?php
declare(strict_types=1);

namespace App\Pms\Factory;

use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use DateTimeImmutable;

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
            // ¿Es este el link principal (el que tiene el ID)?
            if ($targetBookId !== null && $link->getBeds24BookId() === $targetBookId) {
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

                if ($virtualCode && isset($groupedSurvivors[$virtualCode])) {
                    // ✅ MATCH EXACTO: Reutilizamos el link espejo
                    $linkToUse = $groupedSurvivors[$virtualCode];
                    unset($groupedSurvivors[$virtualCode]); // Consumido
                }
                // 2. Fallback FIFO
                elseif (!empty($genericSurvivors)) {
                    $linkToUse = array_shift($genericSurvivors);
                }
                // 3. Nuevo
                else {
                    $linkToUse = new PmsEventoBeds24Link();
                    $linkToUse->setEvento($evento);
                    $evento->addBeds24Link($linkToUse);
                }

                $linkToUse->setEsPrincipal(false);
                $linkToUse->setBeds24BookId(null);
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
            $unused->setBeds24BookId(null);
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