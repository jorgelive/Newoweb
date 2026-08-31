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
use App\Service\Nombre\NombreSanitizer;
use App\Service\Phone\PhoneSanitizer;
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

    /** @var array<string, mixed> Caché de mapa de unidad por clave, para no repetir consultas en el lote. */
    private array $cacheMaps = [];
    /** @var array<string, mixed> Caché de país por clave, para no repetir consultas en el lote. */
    private array $cachePaises = [];
    /** @var array<string, mixed> Caché de idioma por clave, para no repetir consultas en el lote. */
    private array $cacheIdiomas = [];
    /** @var array<string, mixed> Caché de canal por clave, para no repetir consultas en el lote. */
    private array $cacheCanales = [];
    /** @var array<string, mixed> Caché de estado de evento por clave, para no repetir consultas en el lote. */
    private array $cacheEstados = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsEventoCalendarioFactory $eventoFactory,
        private readonly PhoneSanitizer $phoneSanitizer,
        private readonly NombreSanitizer $nombreSanitizer,
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
     *
     * @return array<string, mixed>
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

        // 3. Resolver Link existente — primero por UUID (custom1) para migración determinista
        $existingLink = $this->resolveLinkByPmsUuid($booking->custom1)
            ?? $this->resolveLink($bookingIdStr);

        // Determinación de Autoridad:
        // Si existe link en BD → respetamos su valor.
        // Si es nuevo → custom2 es autoritativo (escrito por nosotros en el Push previo).
        // Fallback: true (si no hay custom2 es una reserva que nunca hemos procesado).
        $isLinkPrincipal = $existingLink
            ? $existingLink->isEsPrincipal()
            : ($booking->custom2 !== 'MIRROR');

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
            bookIdStr: $bookingIdStr,
            // Un espejo no puede escribir los datos de la estancia que comparte (§6.4)
            isLinkPrincipal: $isLinkPrincipal
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

    private function resolveLinkByPmsUuid(?string $custom1): ?PmsEventoBeds24Link
    {
        if (empty($custom1) || !str_starts_with($custom1, 'PMS:')) {
            return null;
        }
        $uuidStr = substr($custom1, 4);
        try {
            $uuid = \Symfony\Component\Uid\Uuid::fromString($uuidStr);
        } catch (\Throwable) {
            return null;
        }
        return $this->em->getRepository(PmsEventoBeds24Link::class)->find($uuid);
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

            // 📣 El nombre se normaliza AQUÍ, en el mismo flush en que entra, y no en un
            // trabajo posterior. Dos motivos, y los dos son de carrera:
            //
            //  1. La bienvenida es una regla `created_at` con offset 0: `MessageRuleEngine` la
            //     programa en el `postFlush` de ESTE guardado, con `runAt = now`. Un trabajo
            //     asíncrono lanzado a la vez compite con el worker de envío, y a veces pierde.
            //  2. Mientras `datosLocked` siga abierto, CADA pull vuelve a escribir estos dos
            //     campos desde el payload. Un nombre arreglado por fuera se desharía solo en la
            //     siguiente pasada, sin que nadie lo notara.
            //
            // Normalizar en la fuente hace las dos preguntas irrelevantes. Ver
            // docs/PmsBeds24ReservasSync.md y NombreSanitizer, que sólo toca lo GRITADO.
            $reserva->setNombreCliente($firstName !== '' ? $this->nombreSanitizer->formatear($firstName) : null);
            $reserva->setApellidoCliente($lastName !== '' ? $this->nombreSanitizer->formatear($lastName) : null);
            $reserva->setEmailCliente($email !== '' ? $email : null);

            $pais = $this->resolvePais($booking);
            $reserva->setPais($pais);
            $reserva->setIdioma($this->resolveIdioma($booking));

            // Beds24 manda `phone` y `mobile`. Se guarda UNO —la semilla— y se prefiere el
            // móvil, que es por donde se escribe: `phone` suele ser el fijo del titular.
            //
            // El segundo número ya no vive aquí. Un número es de la PERSONA, y repetirlo por
            // reserva se contradice a la primera; si el huésped tiene dos, se añaden como
            // identidades suyas desde el panel — ahí sí se puede decir cuál es el bueno.
            $bruto = $mobile !== '' ? $mobile : $phone;
            $reserva->setTelefono($bruto !== '' ? $this->phoneSanitizer->cleanPhoneNumber($bruto, $pais->getId()) : null);

            // SOLO bloqueamos (cerramos candado) si llegó información sólida.
            //
            // ⚠️ **Y de esta línea depende el revisor de orden de nombre, aunque no lo diga.**
            // `OrdenDelNombre::mereceRevision()` exige nombre Y apellido; `hasStrongContactData`
            // es cierto en cuanto hay apellido. O sea que toda reserva que el revisor llegue a
            // tocar ya cerró su candado **en este mismo flush**, y por eso el intercambio que
            // haga sobrevive al pull siguiente.
            //
            // Si esto se endureciera —«que exija también email», que es una decisión
            // razonable—, quedarían reservas con apellido y candado abierto. Ahí se abre un
            // bucle: el pull escribe cruzado, el modelo lo endereza, el pull lo vuelve a
            // cruzar, el listener lo ve como cambio ajeno y vuelve a preguntar. Una llamada al
            // modelo por pull, para siempre, y en el log parecería actividad normal —
            // `OrdenDelNombre::esNuestroIntercambio()` NO lo corta: sólo reconoce nuestro
            // propio intercambio, no la reversión del canal.
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
    /**
     * @param bool $isLinkPrincipal ¿El booking entrante es el DUEÑO del evento, o un espejo?
     *
     * Los links espejo cuelgan del MISMO PmsEventoCalendario que el principal (§6.2), pero en
     * Beds24 son reservas distintas y —por diseño del Push (§7.2)— huecas: llegan con
     * `price: 0`, `commission: 0`, `firstName: "(M) …"` y `channel: "direct"`, porque esos
     * campos nunca se les envían. Si el Pull de un espejo escribiera los campos autoritativos,
     * machacaría los datos reales del evento compartido: el monto se iba a 0 y el canal de una
     * reserva de Booking.com pasaba a "directo" (ver §6.4 del doc).
     */
    private function upsertEvento(
        Beds24BookingDto $booking,
        PmsUnidadBeds24Map $map,
        ?PmsReserva $reserva,
        ?PmsEventoBeds24Link $existingLink,
        string $bookIdStr,
        bool $isLinkPrincipal
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

        // =====================================================================
        // DATOS AUTORITATIVOS DE LA ESTANCIA — sólo los escribe el link PRINCIPAL.
        //
        // Un espejo no tiene ninguna verdad que aportar sobre la estancia: sus datos son los
        // que nosotros le mandamos, recortados. Su Pull sólo debe refrescar el `lastSeenAt`
        // de su propio link (más abajo), que es lo que confirma que sigue vivo en Beds24.
        //
        // Excepción `$action === 'created'`: si el evento se acaba de crear no hay datos
        // previos que proteger y saltar el bloque dejaría una entidad incompleta (estado y
        // fechas son obligatorios). Es un caso anómalo —un espejo no debería estrenar
        // evento—, pero se prefiere una fila completa a una corrupta.
        // =====================================================================
        if ($isLinkPrincipal || $action === 'created') {
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
        }

        // Actualizar LastSeen (Obs #9: Iteración inevitable si no hay repo method, pero controlada)
        // Optimizacion: Si acabamos de crear, sabemos cual es. Si es update, iteramos.
        // Esto SÍ corre siempre: es justo lo que aporta el Pull de un espejo.
        foreach ($evento->getBeds24Links() as $l) {
            $matchById       = $l->getBeds24BookId() === $bookIdStr;
            $matchByIdentity = $existingLink !== null && $l === $existingLink;
            if (!$matchById && !$matchByIdentity) {
                continue;
            }
            // En migración el beds24BookId puede haber cambiado: lo actualizamos
            if ($matchByIdentity && !$matchById && $l->isEsPrincipal()) {
                $l->setBeds24BookId($bookIdStr);
            }
            $l->setLastSeenAt(new DateTimeImmutable());

            // El canal/referencia del link espejo se dejan en NULL a propósito: son de la OTA
            // real, y el espejo se creó vía API como "direct" (mismo criterio con el que la
            // migración Version20260731042857 pobló sólo los links principales).
            if ($isLinkPrincipal) {
                $l->setReferenciaCanal($booking->apiReference);
                $l->setChannel($this->resolveChannel($booking));
            }
            $this->cacheLinks[$bookIdStr] = $l;
            break;
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
     * Resuelve el estado de la estancia a partir del que reporta el canal.
     *
     * ⚠️ LA VERIFICACIÓN DEL EQUIPO ES PARA LOS CANALES QUE **NO** COBRAN. En Booking, un `new`
     * significa que la reserva todavía puede caerse, y ese estado intermedio ES el aviso de que
     * alguien tiene que mirarla: se guarda tal cual. En un canal de pago total —Airbnb, VRBO— el
     * dinero ya está cobrado y no hay nada que verificar antes de darla por buena: entra
     * CONFIRMADA.
     *
     * ⚠️⚠️ EL `(int)$statusApi === 0` DE LA RAMA 3 NO ES UN FALLO — NO LO «ARREGLES».
     * Viene de la API v1, donde el estado era numérico y `0` significaba cancelada. Con la v2 los
     * estados son texto (`cancelled`, `confirmed`, `new`…) y `(int)"new"` es `0`, así que esa rama
     * entra siempre y devuelve el estado mapeado tal cual. Para Booking es justo lo que se quiere.
     *
     * Las ramas van en este orden a propósito: primero los estados intocables (cancelada, inquiry,
     * bloqueo), después el canal que ya cobró, y el cast al final. Así la regla de Airbnb no
     * depende de ese cast, que es de la v1 y algún día cambiará.
     *
     * Detalle y motivo en `docs/PmsBeds24ReservasSync.md` §5.4.
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

        // 1. ESTADOS QUE NO SE TOCAN, venga del canal que venga.
        //
        // `cancelada` es terminal. `abierto` es un inquiry —una pre-reserva de Airbnb, sin venta
        // ni dinero— y §11.2.b depende de que se quede así para que no estrene línea financiera.
        // `bloqueo` no es una reserva. Ninguno de los tres puede promoverse a confirmada.
        if (in_array($estadoBase->getId(), [
            PmsEventoEstado::CODIGO_CANCELADA,
            PmsEventoEstado::CODIGO_ABIERTO,
            PmsEventoEstado::CODIGO_BLOQUEO,
        ], true)) {
            return $estadoBase;
        }

        // 2. CANALES QUE YA COBRARON (Airbnb, VRBO): la reserva entra CONFIRMADA.
        //
        // La verificación del equipo es para los canales que **no garantizan el pago** —Booking,
        // donde un `new` todavía puede caerse—. Cuando el canal ya cobró, no hay nada que
        // verificar antes de darla por buena: el dinero está.
        //
        // ⚠️ Esto reemplaza lo que decía §5.4 sobre Airbnb y VRBO. Aquella frase la escribí yo el
        // 18/08/2026 (857bd4fc) documentando la trampa del cast de abajo, y de paso declaré que
        // la regla alcanzaba también a los canales de pago total. **Eso era inferencia mía sobre
        // un comportamiento que era accidental**, no la regla del negocio. La regla es ésta.
        //
        // Medido el 29/08/2026 con la regla vieja: de once estancias futuras que Beds24 daba
        // como `new`, **cero** estaban confirmadas en local; de las ya pasadas —que el barrido
        // por rango de llegadas, que sólo va hacia delante, deja de tocar— once de trece sí.
        // No eran confirmaciones perdidas: eran confirmaciones que nunca ocurrieron, porque la
        // regla decía que el canal no confirma solo.
        $channelCode = strtolower(trim((string) ($dto->channel ?? '')));

        if (in_array($channelCode, PmsChannel::CANAL_PAGO_TOTAL, true)) {
            return $this->em->find(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CONFIRMADA)
                ?? throw new RuntimeException('CRÍTICO: Maestro corrupto (falta CONFIRMADA).');
        }

        // 3. El resto —Booking y los canales que no cobran— se queda como llegó.
        //
        // ⚠️⚠️ EL `(int)$statusApi === 0` NO ES UN FALLO — NO LO «ARREGLES». Viene de la API v1,
        // donde el estado era numérico y `0` era cancelada; con la v2 los estados son texto y
        // `(int)"new"` es `0`, así que esta rama entra siempre y devuelve el estado del canal tal
        // cual. Es justo lo que se quiere aquí: en Booking, `new` significa que la reserva aún
        // puede caerse, y ese estado intermedio ES el aviso de que alguien tiene que mirarla.
        if ((int)$statusApi === 0) {
            return $estadoBase;
        }

        // Inalcanzable con la v2. Se conserva por lo mismo que antes: el día que Beds24 cambie el
        // contrato del campo, que haya que decidir a conciencia y no descubrirlo por accidente.
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

    /**
     * El país de la reserva. Nunca `null`: también es el `defaultCountryIso` con el que se
     * sanean los dos teléfonos justo después.
     *
     * 🔥 **En Airbnb manda el TELÉFONO, no `country2`.** Comprobado sobre 1385 payloads reales
     * el 19/08/2026: cuando el huésped tiene su Airbnb en español, `country2` llega como `ES`
     * —el código de idioma `es` en mayúsculas, que por desgracia también es un país válido, así
     * que `find()` casa y no falla nada—. De 18 reservas de Airbnb marcadas `ES` con teléfono,
     * **16 tenían móvil peruano (+51), una colombiana y otra mexicana; ninguna española**. Con
     * `fr`→`FR` y `pt`→`PT` pasa igual; con `en` no colapsa y entonces sí llega el país bueno.
     *
     * Booking.com no tiene este problema: su `country2` cuadra con el prefijo siempre.
     *
     * El orden es teléfono → `country2` → `DEFAULT_PAIS`, y no teléfono → `DEFAULT_PAIS`, para
     * no tirar el dato bueno de los Airbnb en inglés. `paisDelNumero()` sólo concluye con
     * números que traen prefijo internacional, así que cuando calla es que de verdad no se sabe.
     *
     * Ver docs/PmsBeds24ReservasSync.md §3.3.
     */
    private function resolvePais(Beds24BookingDto $dto): MaestroPais
    {
        $iso2 = strtoupper(trim((string) ($dto->country2 ?? '')));

        if ($this->resolveChannel($dto)->getId() === PmsChannel::CODIGO_AIRBNB) {
            $delTelefono = $this->phoneSanitizer->paisDelNumero((string) ($dto->phone ?? ''))
                ?? $this->phoneSanitizer->paisDelNumero((string) ($dto->mobile ?? ''));

            if ($delTelefono !== null) {
                $iso2 = $delTelefono;
            }
        }

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

        // Si el idioma está vacío, se infiere desde el país.
        //
        // ⚠️ El campo es `country2`, no `country`. Aquí ponía `$dto->country ?? ''` — una
        // propiedad que NO EXISTE en el DTO—, así que el `??` devolvía siempre '' y esta
        // inferencia entera llevaba muerta desde que se escribió: ninguna reserva sin idioma
        // lo dedujo jamás de su país. El `country2` de Beds24 es ISO2 y los ids de
        // `MaestroPais` también lo son ('ES', 'PE'…), así que el `find()` casa directo.
        if ($code === '') {
            $countryCode = strtoupper(trim((string) ($dto->country2 ?? '')));

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