<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Persister;

use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroPais;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Persister para PULL/Webhooks de Beds24.
 * * ✅ Lógica unificada de Grupos con Cache en Memoria.
 * * ✅ Named Arguments PRAGMÁTICOS (Solo donde aportan claridad).
 */
final class Beds24BookingPersister
{
    /** @var array<string, PmsReserva> Runtime cache para batch processing */
    private static array $reservaByMasterId = [];

    /** @var array<string, PmsEventoBeds24Link> */
    private array $cacheLinks = [];

    private array $cacheMaps = [];
    private array $cachePaises = [];
    private array $cacheIdiomas = [];
    private array $cacheCanales = [];
    private array $cacheEstados = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsEventoCalendarioFactory $eventoFactory
    ) {}

    public function resetCache(): void
    {
        self::$reservaByMasterId = [];
        $this->cacheLinks = [];
        $this->cacheMaps = [];
        $this->cachePaises = [];
        $this->cacheIdiomas = [];
        $this->cacheCanales = [];
        $this->cacheEstados = [];
    }

    public function upsert(Beds24Config $config, Beds24BookingDto $booking): void
    {
        // 2 params: Named args ayudan
        $map = $this->resolveMap(cfg: $config, dto: $booking);

        if (!$map) {
            throw new RuntimeException("No existe mapeo para RoomID: {$booking->roomId}");
        }

        // 1 param simple: Posicional es más limpio
        $bookingIdStr = $this->normalizeBeds24Id($booking->id);

        // Resolver link (Memoria o BD)
        $existingLink = $this->resolveLink($bookingIdStr);

        // 1. DETECCIÓN DE JERARQUÍA
        $masterIdReal = $this->resolveMasterIdReal($booking);

        $isSubReserva = false;
        if ($bookingIdStr !== null && $masterIdReal !== null) {
            $isSubReserva = $masterIdReal !== $bookingIdStr;
        }

        // 2. GESTIÓN DE LA PMS RESERVA
        if ($isSubReserva) {
            // CASO HIJA: Reutilizar reserva padre
            $reserva = $this->resolveReservaFromMasterLink($masterIdReal);

            // Fallback: Stub
            if (!$reserva) {
                $reserva = $this->resolveOrCreateStubReserva($masterIdReal);
            }
        } else {
            // CASO MADRE: Autoridad total
            $reserva = $this->upsertReservaFull($booking);
        }

        // 3. GESTIÓN DEL EVENTO
        // ✅ Aquí SÍ son vitales los Named Arguments (muchos params + booleanos)
        $this->upsertEvento(
            booking: $booking,
            map: $map,
            reserva: $reserva,
            existingLink: $existingLink,
            isSubReserva: $isSubReserva
        );
    }

    private function resolveLink(?string $bookId): ?PmsEventoBeds24Link
    {
        if ($bookId === null) return null;
        if (isset($this->cacheLinks[$bookId])) return $this->cacheLinks[$bookId];

        $link = $this->em->getRepository(PmsEventoBeds24Link::class)
            ->findOneBy(['beds24BookId' => $bookId]);

        if ($link) $this->cacheLinks[$bookId] = $link;

        return $link;
    }

    private function normalizeBeds24Id(mixed $v): ?string
    {
        if ($v === null) return null;
        if (is_numeric($v)) {
            $i = (int) $v;
            return $i > 0 ? (string) $i : null;
        }
        $s = trim((string) $v);
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
        if (isset(self::$reservaByMasterId[$masterIdStr])) return self::$reservaByMasterId[$masterIdStr];

        $masterLink = $this->resolveLink($masterIdStr);
        if (!$masterLink) return null;

        $evento = $masterLink->getEvento();
        if (!$evento) return null;

        $reserva = $evento->getReserva();
        if (!$reserva) return null;

        self::$reservaByMasterId[$masterIdStr] = $reserva;
        return $reserva;
    }

    private function upsertReservaFull(Beds24BookingDto $booking): PmsReserva
    {
        $bookIdStr = $this->normalizeBeds24Id($booking->id);
        if ($bookIdStr === null) {
            throw new RuntimeException('Beds24BookingDto sin id válido.');
        }

        $masterReal = $this->resolveMasterIdReal($booking);
        $effectiveMasterId = $masterReal ?? $bookIdStr;

        // 2 params, puede ser confuso el orden -> Named Args OK
        $reserva = $this->resolveReservaFromLayers(effMaster: $effectiveMasterId, book: $bookIdStr);

        if (!$reserva) {
            $reserva = new PmsReserva();
            $reserva->setBeds24MasterId($effectiveMasterId);
            $reserva->setBeds24BookIdPrincipal($bookIdStr);
            $this->em->persist($reserva);
        } else {
            $reserva->setBeds24MasterId($effectiveMasterId);
            $reserva->setBeds24BookIdPrincipal($bookIdStr);
        }

        self::$reservaByMasterId[$effectiveMasterId] = $reserva;

        // --- ACTUALIZACIÓN DE DATOS ---
        $reserva->setReferenciaCanal($booking->apiReference);
        $reserva->setChannel($this->resolveChannel($booking));
        $reserva->setNota($booking->notes);
        $reserva->setHoraLlegadaCanal($booking->arrivalTime);
        $reserva->setFechaReservaCanal($booking->bookingTime);
        $reserva->setFechaModificacionCanal($booking->modifiedTime);

        if ($booking->commission) {
            $reserva->setComisionTotal($this->normalizeDecimal($booking->commission));
        }

        $hasRealData =
            trim((string) $booking->firstName) !== '' ||
            trim((string) $booking->lastName) !== '' ||
            trim((string) $booking->email) !== '' ||
            trim((string) $booking->phone) !== '';

        if (!$reserva->isDatosLocked() && $hasRealData) {
            $reserva->setNombreCliente($booking->firstName);
            $reserva->setApellidoCliente($booking->lastName);
            $reserva->setEmailCliente($booking->email);
            $reserva->setTelefono($booking->phone);
            $reserva->setTelefono2($booking->mobile);
            $reserva->setPais($this->resolvePais($booking));
            $reserva->setIdioma($this->resolveIdioma($booking));
            $reserva->setDatosLocked(true);
        }

        $reserva->setComentariosHuesped($booking->comments);

        return $reserva;
    }

    private function resolveOrCreateStubReserva(string $masterIdStr): PmsReserva
    {
        if ($masterIdStr === '') throw new RuntimeException('CRÍTICO: masterIdStr vacío.');

        $reserva = $this->resolveReservaFromLayers(effMaster: $masterIdStr, book: null);

        if (!$reserva) {
            $reserva = new PmsReserva();
            $reserva->setBeds24MasterId($masterIdStr);
            $reserva->setNombreCliente('Pendiente Sync');
            $reserva->setApellidoCliente('(Grupo)');
            $reserva->setChannel($this->em->getReference(PmsChannel::class, PmsChannel::CODIGO_DIRECTO));
            $this->em->persist($reserva);
            self::$reservaByMasterId[$masterIdStr] = $reserva;
        } else {
            self::$reservaByMasterId[$masterIdStr] = $reserva;
        }

        return $reserva;
    }

    private function upsertEvento(
        Beds24BookingDto $booking,
        PmsUnidadBeds24Map $map,
        ?PmsReserva $reserva,
        ?PmsEventoBeds24Link $existingLink,
        bool $isSubReserva
    ): void {
        $evento = null;
        $currentBookId = (string) $booking->id;

        // 1. Calculamos el flag OTA
        $channelCode = strtolower(trim((string)($booking->channel ?? '')));
        $isOta = ($channelCode !== 'direct' && $channelCode !== '');

        if ($existingLink) {
            $evento = $existingLink->getEvento();
            $unidadActual = $evento->getPmsUnidad();
            $unidadNueva  = $map->getPmsUnidad();

            if ($unidadActual->getId() !== $unidadNueva->getId()) {
                $evento->setPmsUnidad($unidadNueva);
                // ✅ Named arguments aquí son útiles (3 params)
                $this->eventoFactory->rebuildLinks(
                    evento: $evento,
                    bookId: $currentBookId,
                    roomId: (int) $booking->roomId
                );
            }
        } else {
            // ✅ Named arguments aquí son IMPRESCINDIBLES (muchos params + booleanos)
            $evento = $this->eventoFactory->createFromBeds24Import(
                unidad: $map->getPmsUnidad(),
                fechaInicio: $booking->arrival,
                fechaFin: $booking->departure,
                beds24BookId: $currentBookId,
                beds24RoomId: (int) $booking->roomId,
                isOta: $isOta
            );
        }

        if ($reserva) {
            $evento->setReserva($reserva);
        }

        $est = $evento->getPmsUnidad()->getEstablecimiento();

        // ✅ Named arguments aquí son vitales para el booleano 'isCheckIn'
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

        $evento->setEstado($this->resolveEstado($booking));

        if ($evento->getEstadoPago() === null) {
            $evento->setEstadoPago($this->resolveEstadoPagoInicial($booking));
        }

        $evento->setCantidadAdultos($booking->numAdult ?? 1);
        $evento->setCantidadNinos($booking->numChild ?? 0);
        $evento->setMonto($this->normalizeDecimal($booking->price));
        $evento->setComision($this->normalizeDecimal($booking->commission));

        $titulo = trim(($booking->firstName ?? '') . ' ' . ($booking->lastName ?? ''));
        $evento->setTituloCache($titulo ?: null);

        foreach ($evento->getBeds24Links() as $l) {
            if ($l->getBeds24BookId() === $currentBookId) {
                $l->setLastSeenAt(new DateTimeImmutable());
                $this->cacheLinks[$currentBookId] = $l;
                break;
            }
        }

        $this->em->persist($evento);
    }

    // =========================================================================
    // RESOLVERS & HELPERS
    // =========================================================================

    private function resolveReservaFromLayers(?string $effMaster, ?string $book): ?PmsReserva
    {
        if ($effMaster && isset(self::$reservaByMasterId[$effMaster])) {
            return self::$reservaByMasterId[$effMaster];
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

        if (isset($this->cacheCanales[$cacheKey])) {
            return $this->cacheCanales[$cacheKey];
        }

        $repo = $this->em->getRepository(PmsChannel::class);
        $channel = null;

        if ($nombreCanal !== '') {
            $channel = $repo->find($nombreCanal);
            if (!$channel) {
                $channel = $repo->findOneBy(['nombre' => $nombreCanal]);
            }
            if (!$channel) {
                $channel = $repo->findOneBy(['beds24ChannelId' => $nombreCanal]);
            }
        }

        if (!$channel) {
            $channel = $repo->find(PmsChannel::CODIGO_DIRECTO);
        }

        if (!$channel) {
            throw new RuntimeException('CRÍTICO: No se encontró el canal por defecto (Directo).');
        }

        $this->cacheCanales[$cacheKey] = $channel;
        return $channel;
    }

    private function resolveEstado(Beds24BookingDto $dto): PmsEventoEstado
    {
        $statusApi = trim((string) ($dto->status ?? ''));
        if ($statusApi !== '') {
            if (isset($this->cacheEstados[$statusApi])) return $this->cacheEstados[$statusApi];

            $estado = $this->em->getRepository(PmsEventoEstado::class)->findOneBy(['codigoBeds24' => $statusApi]);
            if ($estado) {
                $this->cacheEstados[$statusApi] = $estado;
                return $estado;
            }
        }
        return $this->em->find(PmsEventoEstado::class, PmsEventoEstado::CODIGO_PENDIENTE)
            ?? throw new RuntimeException('CRÍTICO: Maestro PmsEventoEstado corrupto.');
    }

    private function resolveEstadoPagoInicial(Beds24BookingDto $dto): PmsEventoEstadoPago
    {
        $channelCode = strtolower(trim((string) ($dto->channel ?? '')));
        $isPagoTotal = in_array($channelCode, PmsChannel::CANAL_PAGO_TOTAL, true);
        $targetId = $isPagoTotal ? PmsEventoEstadoPago::ID_PAGO_TOTAL : PmsEventoEstadoPago::ID_SIN_PAGO;

        return $this->em->find(PmsEventoEstadoPago::class, $targetId)
            ?? $this->em->find(PmsEventoEstadoPago::class, PmsEventoEstadoPago::ID_SIN_PAGO)
            ?? throw new RuntimeException('CRÍTICO: Maestro PmsEventoEstadoPago corrupto.');
    }

    private function resolveMap(Beds24Config $cfg, Beds24BookingDto $dto): ?PmsUnidadBeds24Map
    {
        $key = (string) $dto->propertyId . '_' . (string) $dto->roomId;
        if (isset($this->cacheMaps[$key])) return $this->cacheMaps[$key];
        $map = $this->em->getRepository(PmsUnidadBeds24Map::class)->findOneBy([
            'beds24Config' => $cfg,
            'beds24PropertyId' => (string) $dto->propertyId,
            'beds24RoomId' => (int) $dto->roomId,
        ]);
        if ($map) $this->cacheMaps[$key] = $map;
        return $map;
    }

    private function resolvePais(Beds24BookingDto $dto): MaestroPais
    {
        $iso2 = strtoupper((string) ($dto->country2 ?? ''));
        if ($iso2 === '') $iso2 = MaestroPais::DEFAULT_PAIS;

        if (isset($this->cachePaises[$iso2])) return $this->cachePaises[$iso2];
        $pais = $this->em->find(MaestroPais::class, $iso2) ?? $this->em->find(MaestroPais::class, MaestroPais::DEFAULT_PAIS);
        $this->cachePaises[$iso2] = $pais;
        return $pais;
    }

    private function resolveIdioma(Beds24BookingDto $dto): MaestroIdioma
    {
        $code = strtolower((string) ($dto->lang ?? ''));
        if ($code === '') $code = MaestroIdioma::DEFAULT_IDIOMA;
        if (isset($this->cacheIdiomas[$code])) return $this->cacheIdiomas[$code];
        $idioma = $this->em->find(MaestroIdioma::class, $code) ?? $this->em->find(MaestroIdioma::class, MaestroIdioma::DEFAULT_IDIOMA);
        $this->cacheIdiomas[$code] = $idioma;
        return $idioma;
    }

    private function normalizeDecimal(mixed $val): ?string
    {
        if (is_numeric($val)) return number_format((float) $val, 2, '.', '');
        if (is_string($val)) {
            $v = str_replace([',', ' '], ['.', ''], trim($val));
            return is_numeric($v) ? number_format((float) $v, 2, '.', '') : null;
        }
        return '0.00';
    }
}