<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroPais;
use App\Exchange\Entity\Beds24Config;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Pms\Service\Phone\PhoneSanitizer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Persister para PULL/Webhooks de Beds24.
 * * Correcciones aplicadas:
 * * ✅ Return explícito (array) en lugar de void para trazabilidad.
 * * ✅ Cacheo de negativos ("misses") para evitar N+1 en datos inexistentes.
 * * ✅ Eliminación de estado estático (static) para evitar contaminación entre jobs.
 * * ✅ Normalización estricta de IDs al inicio.
 * * ✅ Validación fuerte de Maestros (Pais/Idioma) para evitar nulls silenciosos.
 * * ✅ Inyección obligatoria de PmsEstablecimiento para evitar reservas huérfanas.
 * * ✅ Implementación de ResetInterface para vaciado automático de memoria en Workers asíncronos.
 * * ✅ Inyección de PhoneSanitizer para limpiar datos antes del UoW de Doctrine.
 */
final class BookingPullPersister implements ResetInterface
{
    /** @var array<string, PmsReserva|false> Cache local por ciclo de ejecución */
    private array $reservaByMasterId = [];

    /** @var array<string, PmsEventoBeds24Link|false> */
    private array $cacheLinks = [];

    private array $cacheMaps = [];
    private array $cachePaises = [];
    private array $cacheIdiomas = [];
    private array $cacheCanales = [];
    private array $cacheEstados = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsEventoCalendarioFactory $eventoFactory,
        private readonly PhoneSanitizer $phoneSanitizer
    ) {}

    /**
     * Limpia la memoria interna de la clase.
     * * ¿Por qué existe? Al ejecutarse en Workers asíncronos de larga duración (Messenger),
     * el EntityManager se limpia (clear) periódicamente. Si no vaciamos esta caché local,
     * la clase intentará persistir entidades que Doctrine considera "nuevas/desvinculadas",
     * provocando errores fatales de "cascade persist".
     * * Al implementar ResetInterface, Symfony Messenger llama a este método automáticamente
     * entre la ejecución de cada mensaje.
     */
    public function reset(): void
    {
        $this->reservaByMasterId = [];
        $this->cacheLinks = [];
        $this->cacheMaps = [];
        $this->cachePaises = [];
        $this->cacheIdiomas = [];
        $this->cacheCanales = [];
        $this->cacheEstados = [];
    }

    /**
     * Procesa un DTO proveniente de Beds24, encargándose de orquestar la creación o actualización
     * de la Reserva madre y los eventos de calendario individuales (hijas) asociados.
     * * ¿Por qué existe? Centraliza la lógica de negocio de cómo una reserva de canal OTA o Directa
     * se traduce a la estructura jerárquica de la base de datos (Reserva -> Eventos -> Links),
     * resolviendo dependencias complejas como el establecimiento, país, idioma y mapeos de unidades.
     * * @param Beds24Config $config Configuración del canal actual.
     * @param Beds24BookingDto $booking Los datos crudos normalizados en un DTO.
     * * @return array{status: string, action: string, message: string}
     * status: 'success' | 'skipped'
     * action: 'created' | 'updated' | 'ignored'
     * * @throws RuntimeException Si los datos críticos como el mapeo de unidad o maestros son inválidos.
     */
    public function upsert(Beds24Config $config, Beds24BookingDto $booking): array
    {
        // 1. Normalización Canónica del ID (Obs #2)
        $bookingIdStr = $this->normalizeBeds24Id($booking->id);
        if ($bookingIdStr === null) {
            throw new RuntimeException("El Booking DTO no tiene un ID válido.");
        }

        // 2. Mapeo de Unidad
        $map = $this->resolveMap(dto: $booking);
        if (!$map) {
            throw new RuntimeException("No existe mapeo PMS para RoomID Beds24: {$booking->roomId} ");
        }

        $establecimiento = $this->resolveEstablecimiento(config: $config, map: $map);

        // 3. Resolver Link existente (Memoria o BD)
        $existingLink = $this->resolveLink($bookingIdStr);

        // Determinación de Autoridad (Obs #4)
        // Si existe, respetamos la BD. Si es nuevo, asumimos true SOLO si no es una sub-reserva explícita.
        // Pero para simplificar y mantener la regla: "Ante la duda, si es nuevo, es principal hasta que se demuestre lo contrario por lógica de grupo".
        $isLinkPrincipal = $existingLink ? $existingLink->isEsPrincipal() : true;

        // 4. DETECCIÓN DE JERARQUÍA
        $masterIdReal = $this->resolveMasterIdReal($booking);

        $isSubReserva = false;
        if ($masterIdReal !== null) {
            $isSubReserva = $masterIdReal !== $bookingIdStr;
        }

        // 5. GESTIÓN DE LA PMS RESERVA
        $reservaAction = 'none';

        if ($isSubReserva) {
            // CASO HIJA: Reutilizar reserva padre
            $reserva = $this->resolveReservaFromMasterLink($masterIdReal);

            // Fallback: Stub
            if (!$reserva) {
                // ✅ CAMBIO APLICADO: Pasamos el establecimiento para evitar Stub huérfano
                $reserva = $this->resolveOrCreateStubReserva(
                    masterIdStr: $masterIdReal,
                    establecimiento: $establecimiento,
                    booking: $booking
                );
                $reservaAction = 'created_stub';
            } else {
                $reservaAction = 'linked_to_master';
            }
        } else {
            // CASO MADRE / INDIVIDUAL / SOMBRA

            // Extraer reserva del link si existe (Obs #5: Prioridad al Link)
            $reservaDeLink = null;
            if ($existingLink) {
                $reservaDeLink = $existingLink->getEvento()?->getReserva();
            }

            // REGLA CRÍTICA: Ignorar "Shadow/Virtual Rooms" que no son principales y no tienen reserva.
            // (Obs #5: Early return con mensaje explícito)
            if ($existingLink && !$isLinkPrincipal && !$reservaDeLink) {
                return [
                    'status' => 'skipped',
                    'action' => 'ignored',
                    'message' => "Link secundario (ID: $bookingIdStr) sin reserva asociada. Se ignora para evitar duplicados."
                ];
            }

            $reserva = $this->upsertReservaFull(
                booking: $booking,
                isPrincipal: $isLinkPrincipal,
                establecimiento: $establecimiento,
                reservaExistente: $reservaDeLink
            );

            // Determinamos si se creó o actualizó observando si tiene ID (aunque el persist lo asigna después, el objeto en memoria es nuevo)
            // Una forma simple es verificar si estaba en el cache antes.
            $reservaAction = $reserva->getId() ? 'updated' : 'created';
        }

        // 6. GESTIÓN DEL EVENTO
        $eventoResult = $this->upsertEvento(
            booking: $booking,
            map: $map,
            reserva: $reserva,
            existingLink: $existingLink,
            // (Obs #2) Pasamos el ID normalizado
            bookIdStr: $bookingIdStr
        );

        return [
            'status' => 'success',
            'action' => ($reservaAction === 'created' || $eventoResult === 'created') ? 'created' : 'updated',
            'message' => "Reserva: $reservaAction, Evento: $eventoResult. (ID: $bookingIdStr)"
        ];
    }

    private function resolveEstablecimiento(Beds24Config $config, PmsUnidadBeds24Map $map): PmsEstablecimiento {
        $establecimiento = $map->getPmsUnidad()->getEstablecimiento();

        if (!$establecimiento) {
            throw new \RuntimeException('La unidad mapeada no tiene un establecimiento asignado.');
        }

        $idStr = (string) $establecimiento->getId();

        foreach ($config->getEstablecimientos() as $establecimientoEnConfig) {
            if ((string) $establecimientoEnConfig->getId() === $idStr) {
                return $establecimiento;
            }
        }

        throw new \RuntimeException(sprintf(
            'Inconsistencia: El establecimiento "%s" no pertenece a la config Beds24 actual.',
            $establecimiento->getNombreComercial()
        ));
    }

    private function resolveLink(string $bookId): ?PmsEventoBeds24Link
    {
        // (Obs #3) Cacheo de "Misses" usando array_key_exists
        if (array_key_exists($bookId, $this->cacheLinks)) {
            $val = $this->cacheLinks[$bookId];
            return $val === false ? null : $val;
        }

        $link = $this->em->getRepository(PmsEventoBeds24Link::class)
            ->findOneBy(['beds24BookId' => $bookId]);

        // Guardamos el objeto o FALSE si no existe
        $this->cacheLinks[$bookId] = $link ?? false;

        return $link;
    }

    /**
     * Normaliza el ID asegurando string y evitando nulos/ceros.
     * (Obs #2)
     */
    private function normalizeBeds24Id(mixed $v): ?string
    {
        if ($v === null) return null;
        if (is_numeric($v)) {
            $i = (int) $v;
            return $i > 0 ? (string) $i : null;
        }
        $s = trim((string) $v);
        // Validar que no sea cadena vacía o "0"
        return ($s !== '' && $s !== '0') ? $s : null;
    }

    private function resolveMasterIdReal(Beds24BookingDto $booking): ?string
    {
        if (!empty($booking->bookingGroup) && is_array($booking->bookingGroup) && array_key_exists('master', $booking->bookingGroup)) {
            $m = $this->normalizeBeds24Id($booking->bookingGroup['master']);
            if ($m !== null) return $m;
        }
        return $this->normalizeBeds24Id($booking->masterId);
    }

    private function resolveReservaFromMasterLink(string $masterIdStr): ?PmsReserva
    {
        if ($masterIdStr === '') return null;

        // (Obs #7) Uso de cache de instancia, no static
        if (array_key_exists($masterIdStr, $this->reservaByMasterId)) {
            $val = $this->reservaByMasterId[$masterIdStr];
            return $val === false ? null : $val;
        }

        $masterLink = $this->resolveLink($masterIdStr);
        if (!$masterLink) {
            $this->reservaByMasterId[$masterIdStr] = false;
            return null;
        }

        $evento = $masterLink->getEvento();
        $reserva = $evento?->getReserva();

        $this->reservaByMasterId[$masterIdStr] = $reserva ?? false;
        return $reserva;
    }

    private function upsertReservaFull(Beds24BookingDto $booking, bool $isPrincipal, PmsEstablecimiento $establecimiento, ?PmsReserva $reservaExistente = null): PmsReserva
    {
        $bookIdStr = $this->normalizeBeds24Id($booking->id);
        $masterReal = $this->resolveMasterIdReal($booking);
        $effectiveMasterId = $masterReal ?? $bookIdStr;

        $reserva = $reservaExistente ?? $this->resolveReservaFromLayers(effMaster: $effectiveMasterId, book: $bookIdStr);

        if (!$reserva) {
            // Doble check de seguridad (Obs #5)
            if (!$isPrincipal) {
                throw new RuntimeException("Intento ilegal de crear Reserva desde Link NO Principal (ID: $bookIdStr).");
            }

            $reserva = new PmsReserva();
            $reserva->setBeds24MasterId($effectiveMasterId);
            $reserva->setBeds24BookIdPrincipal($bookIdStr);
            $this->em->persist($reserva);
        } else {
            if ($isPrincipal) {
                $reserva->setBeds24MasterId($effectiveMasterId);
                $reserva->setBeds24BookIdPrincipal($bookIdStr);
            }
        }

        // Cache update
        $this->reservaByMasterId[$effectiveMasterId] = $reserva;

        // Ponemos Establecimiento
        $reserva->setEstablecimiento($establecimiento);

        // --- DATA SYNC ---

        $reserva->setNota($booking->notes);

        if ($booking->commission) {
            $reserva->setComisionTotal($this->normalizeDecimal($booking->commission));
        }

        // 🔥 FIX 1048: El idioma es obligatorio en la BD. Lo asignamos siempre si es nulo.
        if ($reserva->getIdioma() === null) {
            $reserva->setIdioma($this->resolveIdioma($booking));
        }

        // Limpieza de datos entrantes
        $firstName = trim((string) $booking->firstName);
        $lastName  = trim((string) $booking->lastName);
        $email     = trim((string) $booking->email);
        $phone     = trim((string) $booking->phone);
        $mobile    = trim((string) $booking->mobile);

        // ¿Vino algo? (Aunque sea solo el firstName de un Inquiry)
        $hasAnyData = $firstName !== '' || $lastName !== '' || $email !== '' || $phone !== '' || $mobile !== '';

        // 🔥 LA MAGIA DEL INQUIRY: Separamos tener *algo* de tener datos *fuertes*
        // Airbnb manda solo firstName en estado Request. No queremos sellar la reserva con eso.
        // Solo consideraremos que la info es fuerte si viene un Apellido o un medio de contacto.
        $hasStrongContactData = $lastName !== '' || $email !== '' || $phone !== '' || $mobile !== '';

        // Si el candado está abierto, guardamos todo lo que traiga
        if (!$reserva->isDatosLocked() && $hasAnyData && $isPrincipal) {

            $reserva->setNombreCliente($firstName !== '' ? $firstName : null);
            $reserva->setApellidoCliente($lastName !== '' ? $lastName : null);
            $reserva->setEmailCliente($email !== '' ? $email : null);

            $pais = $this->resolvePais($booking);
            $reserva->setPais($pais);
            $reserva->setIdioma($this->resolveIdioma($booking));

            $reserva->setTelefono($phone !== '' ? $this->phoneSanitizer->cleanPhoneNumber($phone, $pais->getId()) : null);
            $reserva->setTelefono2($mobile !== '' ? $this->phoneSanitizer->cleanPhoneNumber($mobile, $pais->getId()) : null);

            // SOLO bloqueamos (cerramos candado) si llegó información sólida
            if ($hasStrongContactData) {
                $reserva->setDatosLocked(true);
            }
        }

        return $reserva;
    }

    /**
     * ✅ CAMBIO APLICADO: Se inyecta el PmsEstablecimiento para amarrar correctamente la reserva stub.
     */
    private function resolveOrCreateStubReserva(string $masterIdStr, PmsEstablecimiento $establecimiento, Beds24BookingDto $booking): PmsReserva
    {
        $reserva = $this->resolveReservaFromLayers(effMaster: $masterIdStr, book: null);

        if (!$reserva) {
            $reserva = new PmsReserva();
            $reserva->setBeds24MasterId($masterIdStr);
            $reserva->setNombreCliente('Pendiente Sync');
            $reserva->setApellidoCliente('(Grupo)');
            $reserva->setEstablecimiento($establecimiento);
            $reserva->setIdioma($this->resolveIdioma($booking));

            $this->em->persist($reserva);
        }

        $this->reservaByMasterId[$masterIdStr] = $reserva;
        return $reserva;
    }

    /**
     * @return string 'created' | 'updated'
     */
    private function upsertEvento(
        Beds24BookingDto $booking,
        PmsUnidadBeds24Map $map,
        ?PmsReserva $reserva,
        ?PmsEventoBeds24Link $existingLink,
        string $bookIdStr
    ): string {
        $evento = null;
        $action = 'updated';

        $channelCode = strtolower(trim((string)($booking->channel ?? '')));
        $isOta = ($channelCode !== 'direct' && $channelCode !== '');

        if ($existingLink) {
            $evento = $existingLink->getEvento();
            $unidadActual = $evento->getPmsUnidad();
            $unidadNueva  = $map->getPmsUnidad();

            if ($unidadActual->getId() !== $unidadNueva->getId()) {
                $evento->setPmsUnidad($unidadNueva);
                $this->eventoFactory->rebuildLinks(
                    evento: $evento,
                    bookId: $bookIdStr,
                    roomId: (int) $booking->roomId
                );
            }
        } else {
            $action = 'created';
            $evento = $this->eventoFactory->createFromBeds24Import(
                unidad: $map->getPmsUnidad(),
                fechaInicio: $booking->arrival,
                fechaFin: $booking->departure,
                beds24BookId: $bookIdStr,
                beds24RoomId: (int) $booking->roomId,
                isOta: $isOta
            );
        }

        if ($reserva) {
            $evento->setReserva($reserva);
        }

        $est = $evento->getPmsUnidad()->getEstablecimiento();

        $evento->setInicio($this->eventoFactory->resolveFechaConHora(
            fechaYmd: $booking->arrival,
            establecimiento: $est,
            isCheckIn: true
        ));

        $evento->setFin($this->eventoFactory->resolveFechaConHora(
            fechaYmd: $booking->departure,
            establecimiento: $est,
            isCheckIn: false
        ));

        $evento->setEstadoBeds24($booking->status);
        $evento->setSubestadoBeds24($booking->subStatus);
        $evento->setRateDescription($booking->rateDescription);

        // 💡 FIX: Capturamos el estado calculado en una variable para poder pasarlo luego a EstadoPago
        $estadoReal = $this->resolveEstado($booking);
        $evento->setEstado($estadoReal);

        //ahora el channel el del evento
        $evento->setReferenciaCanal($booking->apiReference);
        $evento->setChannel($this->resolveChannel($booking));
        $evento->setHoraLlegadaCanal($booking->arrivalTime);
        $evento->setFechaReservaCanal($booking->bookingTime);
        $evento->setFechaModificacionCanal($booking->modifiedTime);
        $evento->setComentariosHuesped($booking->comments);


        if ($evento->getEstadoPago() === null) {
            // 💡 FIX: Pasamos el segundo argumento (estadoReal) requerido por la nueva firma del método
            $evento->setEstadoPago($this->resolveEstadoPagoInicial($booking, $estadoReal));
        }

        $evento->setCantidadAdultos($booking->numAdult ?? 1);
        $evento->setCantidadNinos($booking->numChild ?? 0);
        $evento->setMonto($this->normalizeDecimal($booking->price));
        $evento->setComision($this->normalizeDecimal($booking->commission));

        $titulo = trim(($booking->firstName ?? '') . ' ' . ($booking->lastName ?? ''));
        $evento->setTituloCache($titulo ?: null);

        // Actualizar LastSeen (Obs #9: Iteración inevitable si no hay repo method, pero controlada)
        // Optimizacion: Si acabamos de crear, sabemos cual es. Si es update, iteramos.
        foreach ($evento->getBeds24Links() as $l) {
            if ($l->getBeds24BookId() === $bookIdStr) {
                $l->setLastSeenAt(new DateTimeImmutable());
                // Actualizamos cache para evitar re-query en este mismo ciclo
                $this->cacheLinks[$bookIdStr] = $l;
                break;
            }
        }

        $this->em->persist($evento);
        return $action;
    }

    // =========================================================================
    // RESOLVERS & HELPERS
    // =========================================================================

    private function resolveReservaFromLayers(?string $effMaster, ?string $book): ?PmsReserva
    {
        if ($effMaster && array_key_exists($effMaster, $this->reservaByMasterId)) {
            $val = $this->reservaByMasterId[$effMaster];
            return $val === false ? null : $val;
        }

        $repo = $this->em->getRepository(PmsReserva::class);
        $reserva = null;
        if ($effMaster) $reserva = $repo->findOneBy(['beds24MasterId' => $effMaster]);
        if (!$reserva && $book) $reserva = $repo->findOneBy(['beds24BookIdPrincipal' => $book]);

        return $reserva;
    }

    private function resolveChannel(Beds24BookingDto $dto): PmsChannel
    {
        $nombreCanal = trim((string) ($dto->channel ?? ''));
        $cacheKey = strtolower($nombreCanal);
        if ($cacheKey === '') $cacheKey = 'default_directo';

        if (array_key_exists($cacheKey, $this->cacheCanales)) {
            return $this->cacheCanales[$cacheKey];
        }

        $repo = $this->em->getRepository(PmsChannel::class);
        $channel = null;

        if ($nombreCanal !== '') {
            $channel = $repo->find($nombreCanal);
            if (!$channel) $channel = $repo->findOneBy(['nombre' => $nombreCanal]);
            if (!$channel) $channel = $repo->findOneBy(['beds24ChannelId' => $nombreCanal]);
        }

        if (!$channel) $channel = $repo->find(PmsChannel::CODIGO_DIRECTO);

        if (!$channel) {
            throw new RuntimeException('CRÍTICO: No se encontró el canal por defecto (Directo). Base de datos incompleta.');
        }

        $this->cacheCanales[$cacheKey] = $channel;
        return $channel;
    }

    /**
     * Resuelve el estado de la reserva aplicando las reglas de negocio de Canales vs OTAs.
     * Respeta ÚNICAMENTE el estado "Abierto" (Inquiry) como pre-reserva intocable.
     * El resto (new, request) pasará a Confirmada si el canal es de pago total.
     */
    private function resolveEstado(Beds24BookingDto $dto): PmsEventoEstado
    {
        $statusApi = trim((string) ($dto->status ?? ''));
        $estadoBase = null;

        // =======================================================
        // PASO 1: OBTENER EL ESTADO BASE (Cacheado correctamente)
        // =======================================================
        if ($statusApi !== '') {
            if (isset($this->cacheEstados[$statusApi])) {
                $estadoBase = $this->cacheEstados[$statusApi];
            } else {
                $estadoBase = $this->em->getRepository(PmsEventoEstado::class)->findOneBy(['codigoBeds24' => $statusApi]);
                if ($estadoBase) {
                    // Lo guardamos en caché SEA CUAL SEA EL ESTADO
                    $this->cacheEstados[$statusApi] = $estadoBase;
                }
            }
        }

        // Fallback de seguridad si no vino status o no existe en BD
        if (!$estadoBase) {
            // Asumimos que la key 'SYS_PENDIENTE' es para nuestras búsquedas internas manuales
            $estadoBase = $this->em->find(PmsEventoEstado::class, PmsEventoEstado::CODIGO_PENDIENTE)
                ?? throw new RuntimeException('CRÍTICO: Maestro corrupto (falta PENDIENTE).');
        }

        // 1. Si Beds24 reporta que ESTÁ CANCELADA, respetamos eso sobre todo.
        if ((int)$statusApi === 0 || $estadoBase->getId() === PmsEventoEstado::CODIGO_CANCELADA) {
            return $estadoBase;
        }

        // 2. PROTECCIÓN ESTRICTA DE PRE-RESERVAS (INQUIRY):
        // El único estado que NO se toca ni se auto-confirma es ABIERTO.
        if ($estadoBase->getId() === PmsEventoEstado::CODIGO_ABIERTO) {
            return $estadoBase;
        }

        // 3. REGLA DE NEGOCIO OTA (Pago Total):
        // Si llegó hasta aquí (es new, request, etc.) y es canal tipo Airbnb, forzamos Confirmada.
        $channelCode = strtolower(trim((string) ($dto->channel ?? '')));
        if (in_array($channelCode, PmsChannel::CANAL_PAGO_TOTAL, true)) {
            return $this->em->find(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CONFIRMADA)
                ?? throw new RuntimeException('CRÍTICO: Maestro corrupto (falta CONFIRMADA).');
        }

        // Si no es OTA de pago total, devuelve el estado natural mapeado (Pendiente, Requerimiento, etc.)
        return $estadoBase;
    }

    /**
     * Define el estado de pago basándose en el Canal y en el Estado Final calculado.
     */
    private function resolveEstadoPagoInicial(Beds24BookingDto $dto, PmsEventoEstado $estadoReal): PmsEventoEstadoPago
    {
        // 1. PROTECCIÓN DE PRE-RESERVAS PARA EL PAGO (INQUIRY):
        // Si el evento quedó estrictamente como ABIERTO, aseguramos que nazca SIN PAGO.
        if ($estadoReal->getId() === PmsEventoEstado::CODIGO_ABIERTO) {
            return $this->em->find(PmsEventoEstadoPago::class, PmsEventoEstadoPago::ID_SIN_PAGO)
                ?? throw new RuntimeException('CRÍTICO: Maestro PmsEventoEstadoPago corrupto (Sin Pago).');
        }

        // 2. REGLA DE PAGO TOTAL:
        // Si ya no es Inquiry (puede haber sido Request o New que se auto-confirmó arriba),
        // verificamos si el canal garantiza el cobro (ej. Airbnb).
        $channelCode = strtolower(trim((string) ($dto->channel ?? '')));
        $isPagoTotal = in_array($channelCode, PmsChannel::CANAL_PAGO_TOTAL, true);

        $targetId = $isPagoTotal ? PmsEventoEstadoPago::ID_PAGO_TOTAL : PmsEventoEstadoPago::ID_SIN_PAGO;

        return $this->em->find(PmsEventoEstadoPago::class, $targetId)
            ?? $this->em->find(PmsEventoEstadoPago::class, PmsEventoEstadoPago::ID_SIN_PAGO)
            ?? throw new RuntimeException('CRÍTICO: Maestro PmsEventoEstadoPago corrupto.');
    }
    private function resolveMap(Beds24BookingDto $dto): ?PmsUnidadBeds24Map
    {
        $key = (string) $dto->propertyId . '_' . (string) $dto->roomId;
        if (array_key_exists($key, $this->cacheMaps)) {
            $val = $this->cacheMaps[$key];
            return $val === false ? null : $val;
        }

        $map = $this->em->getRepository(PmsUnidadBeds24Map::class)->findOneBy([
            'beds24PropertyId' => (string) $dto->propertyId,
            'beds24RoomId' => (int) $dto->roomId,
        ]);

        $this->cacheMaps[$key] = $map ?? false;
        return $map;
    }

    private function resolvePais(Beds24BookingDto $dto): MaestroPais
    {
        $iso2 = strtoupper((string) ($dto->country2 ?? ''));
        if ($iso2 === '') $iso2 = MaestroPais::DEFAULT_PAIS;

        if (array_key_exists($iso2, $this->cachePaises)) return $this->cachePaises[$iso2];

        $pais = $this->em->find(MaestroPais::class, $iso2);

        // (Obs #1) Fallback estricto
        if (!$pais) {
            $pais = $this->em->find(MaestroPais::class, MaestroPais::DEFAULT_PAIS);
            if (!$pais) {
                throw new RuntimeException("CRÍTICO: No existe el País solicitado '$iso2' ni el Default '" . MaestroPais::DEFAULT_PAIS . "'.");
            }
        }

        $this->cachePaises[$iso2] = $pais;
        return $pais;
    }

    private function resolveIdioma(Beds24BookingDto $dto): MaestroIdioma
    {
        // 1. Intentamos leer el idioma directo de la OTA
        $code = strtolower(trim((string) ($dto->lang ?? '')));

        // 🔥 NUEVO MAGIA: Si el idioma está vacío, inferimos desde el país
        if ($code === '') {
            $countryCode = strtoupper(trim((string) ($dto->country ?? ''))); // Beds24 suele mandar ISO2 o ISO3

            if ($countryCode !== '') {
                // Buscamos el país. (Usa tu método resolvePais si lo tienes, o el EM directo)
                $pais = $this->em->find(MaestroPais::class, $countryCode);

                if ($pais && $pais->getIdiomaDefault()) {
                    // ¡Bingo! El país nos chismeó qué idioma hablan
                    $code = $pais->getIdiomaDefault()->getId();
                }
            }
        }

        // Fallback absoluto si la OTA no mandó ni idioma ni país (o el país no tenía idioma configurado)
        if ($code === '') {
            $code = MaestroIdioma::DEFAULT_IDIOMA;
        }

        // 2. ¿Ya lo resolvimos antes en este ciclo? (Memoria RAM)
        if (array_key_exists($code, $this->cacheIdiomas)) {
            return $this->cacheIdiomas[$code];
        }

        // 3. Buscar el idioma en la base de datos
        $idioma = $this->em->find(MaestroIdioma::class, $code);

        // 4. REGLA DE NEGOCIO: Si no existe en la base de datos -> Fallback a Inglés
        // ELIMINADO: La restricción de prioridad ($idioma->getPrioridad() <= 0).
        // MOTIVO TÉCNICO: Permitir que idiomas "exóticos" (prioridad 0) se guarden en la reserva y
        // conversación para que Google Translate funcione correctamente con texto libre.
        if (!$idioma) {
            $idiomaDefault = $this->em->find(MaestroIdioma::class, MaestroIdioma::DEFAULT_IDIOMA);

            if (!$idiomaDefault) {
                throw new RuntimeException("CRÍTICO: No existe el Idioma Default '" . MaestroIdioma::DEFAULT_IDIOMA . "' en la base de datos.");
            }

            $idioma = $idiomaDefault;
        }

        // 5. Cacheamos la decisión.
        // Ejemplo: Si el huésped era de FR (Francia), pero no mandó idioma.
        // Detectamos FR -> sacamos idioma 'fr'.
        // Guardamos en caché que 'fr' = Objeto MaestroIdioma(Francés).
        $this->cacheIdiomas[$code] = $idioma;

        return $idioma;
    }

    private function normalizeDecimal(mixed $val): string
    {
        // (Obs #8) Retorno seguro '0.00' si es inválido, pero con manejo robusto de tipos
        if ($val === null || $val === '') return '0.00';

        if (is_numeric($val)) return number_format((float) $val, 2, '.', '');

        if (is_string($val)) {
            // Eliminar espacios y convertir coma a punto
            $v = str_replace([',', ' '], ['.', ''], trim($val));
            return is_numeric($v) ? number_format((float) $v, 2, '.', '') : '0.00';
        }

        return '0.00';
    }
}