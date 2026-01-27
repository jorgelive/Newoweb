<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Persister;

use App\Entity\MaestroIdioma;
use App\Oweb\Entity\MaestroPais;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidadBeds24Map;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persister alojado en Exchange. Absorbe la lógica completa de sincronización.
 * Maneja Reservas, Eventos, Links y ahora el flag crítico de seguridad isOta.
 */
final class Beds24BookingPersister
{
    /** @var array<string, PmsReserva> Cache para manejo de duplicados en batch */
    private static array $reservaByMasterId = [];

    /** @var array<string, PmsReserva> */
    private static array $reservaByBookIdPrincipal = [];

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * Limpia la cache estática.
     */
    public static function resetCache(): void
    {
        self::$reservaByMasterId = [];
        self::$reservaByBookIdPrincipal = [];
    }

    public function upsert(Beds24Config $config, Beds24BookingDto $booking): void
    {
        // 1. Resolver Mapeo
        $map = $this->resolveMap($config, $booking);
        if (!$map) {
            throw new \RuntimeException(sprintf(
                'CRÍTICO: No existe mapeo de unidad para Beds24 PropertyID: "%s", RoomID: "%s".',
                $booking->propertyId,
                $booking->roomId
            ));
        }

        // 2. Chequeo de Links existentes
        $existingLink = null;
        $isMirror = false;

        if ($booking->id) {
            $existingLink = $this->em->getRepository(PmsEventoBeds24Link::class)
                ->findOneBy(['beds24BookId' => (string) $booking->id]);
            $isMirror = (bool) ($existingLink?->isMirror() ?? false);
        }

        // 3. Procesar Reserva (Cabecera)
        $reserva = null;
        if (!$isMirror) {
            $reserva = $this->upsertReserva($booking);
        }

        // 4. Procesar Evento (Detalle)
        $this->upsertEvento($booking, $map, $reserva, $existingLink, $isMirror);
    }

    private function upsertReserva(Beds24BookingDto $booking): PmsReserva
    {
        $bookIdStr = (string) $booking->id;
        $masterIdStr = $booking->masterId ? (string) $booking->masterId : null;
        $effectiveMasterIdStr = $masterIdStr ?: $bookIdStr;

        $reserva = $this->resolveReservaFromLayers($effectiveMasterIdStr, $masterIdStr, $bookIdStr);

        $isNew = false;
        if (!$reserva) {
            $reserva = new PmsReserva();
            $this->em->persist($reserva);
            $isNew = true;
        }

        if ($effectiveMasterIdStr) {
            self::$reservaByMasterId[$effectiveMasterIdStr] = $reserva;
        }

        $reserva->setBeds24MasterId($effectiveMasterIdStr);

        if ($masterIdStr === null) {
            $reserva->setBeds24BookIdPrincipal($bookIdStr);
        } elseif (empty($reserva->getBeds24BookIdPrincipal())) {
            $reserva->setBeds24BookIdPrincipal($bookIdStr);
        }

        $reserva->setReferenciaCanal($booking->apiReference);

        if ($isNew) {
            $canal = $this->resolveChannel($booking);
            if ($canal) $reserva->setChannel($canal);
        }

        if (!$reserva->isDatosLocked()) {
            $reserva->setNombreCliente($booking->firstName);
            $reserva->setApellidoCliente($booking->lastName);
            $reserva->setEmailCliente($booking->email);
            $reserva->setTelefono($booking->phone);
            $reserva->setTelefono2($booking->mobile);
            $reserva->setNota($booking->notes);
            $reserva->setPais($this->resolvePais($booking));
            $reserva->setIdioma($this->resolveIdioma($booking));
        }

        $reserva->setComentariosHuesped($booking->comments);
        $reserva->setHoraLlegadaCanal($booking->arrivalTime);

        if ($isNew) {
            $reserva->setDatosLocked(true);
        }

        $reserva->setFechaReservaCanal($booking->bookingTime);
        $reserva->setFechaModificacionCanal($booking->modifiedTime);

        return $reserva;
    }

    private function upsertEvento(Beds24BookingDto $booking, PmsUnidadBeds24Map $map, ?PmsReserva $reserva, ?PmsEventoBeds24Link $existingLink, bool $isMirror): void
    {
        $evento = $existingLink?->getEvento() ?? new PmsEventoCalendario();
        $isNewEvento = ($evento->getId() === null);

        if ($isNewEvento) {
            $this->em->persist($evento);
        }

        $evento->setPmsUnidad($map->getPmsUnidad());
        if (!$isMirror && $reserva) {
            $evento->setReserva($reserva);
        }

        // --- LÓGICA DE SEGURIDAD OTA ---
        // Se marca como OTA si es un evento nuevo y el canal NO es direct.
        // Este flag es persistente y no se cambia en futuras sincronizaciones.
        if ($isNewEvento && !$isMirror) {
            $channelCode = strtolower(trim((string)($booking->channel ?? '')));
            $isOta = ($channelCode !== 'direct' && $channelCode !== '');
            $evento->setIsOta($isOta);
        }

        $est = $map->getPmsUnidad()->getEstablecimiento();
        $evento->setInicio($this->buildEventoDateTime($booking->arrival, $est?->getHoraCheckIn()));
        $evento->setFin($this->buildEventoDateTime($booking->departure, $est?->getHoraCheckOut()));

        $evento->setEstadoBeds24($booking->status);
        $evento->setSubestadoBeds24($booking->subStatus);
        $evento->setEstado($this->resolveEstado($booking));

        if (!$existingLink) {
            $evento->setEstadoPago($this->resolveEstadoPagoInicial($booking));
        }

        $evento->setCantidadAdultos($booking->numAdult ?? 0);
        $evento->setCantidadNinos($booking->numChild ?? 0);
        $evento->setMonto($this->normalizeDecimal($booking->price));
        $evento->setComision($this->normalizeDecimal($booking->commission));
        $evento->setRateDescription($booking->rateDescription);

        $titulo = trim(($booking->firstName ?? '') . ' ' . ($booking->lastName ?? ''));
        $evento->setTituloCache($titulo ?: null);

        // Eliminado OrigenCache según solicitud, ya que isOta es el flag de verdad

        if (!$existingLink) {
            $existingLink = new PmsEventoBeds24Link();
            $existingLink->setBeds24BookId((string) $booking->id);
            $existingLink->setEvento($evento);
            $existingLink->setUnidadBeds24Map($map);
            $this->em->persist($existingLink);
        }
        $existingLink->setLastSeenAt(new DateTimeImmutable());
    }

    // --- Métodos de resolución de Entidades ---

    private function resolveReservaFromLayers(?string $effMaster, ?string $master, ?string $book): ?PmsReserva {
        if ($effMaster && isset(self::$reservaByMasterId[$effMaster])) {
            return self::$reservaByMasterId[$effMaster];
        }
        $repo = $this->em->getRepository(PmsReserva::class);
        $reserva = $repo->findOneBy(['beds24MasterId' => $effMaster]);
        if (!$reserva && $book) {
            $reserva = $repo->findOneBy(['beds24BookIdPrincipal' => $book]);
        }
        return $reserva;
    }

    private function resolvePais(Beds24BookingDto $dto): MaestroPais {
        $iso2 = strtoupper((string)($dto->country2 ?? ''));
        $pais = $iso2 ? $this->em->getRepository(MaestroPais::class)->findOneBy(['iso2' => $iso2]) : null;
        return $pais ?: $this->em->getRepository(MaestroPais::class)->findOneBy([
            'iso2' => MaestroPais::ISO_PERU
        ]);
    }

    private function resolveIdioma(Beds24BookingDto $dto): MaestroIdioma {
        $code = strtolower((string)($dto->lang ?? ''));
        $idioma = $code ? $this->em->getRepository(MaestroIdioma::class)->findOneBy(['codigo' => $code]) : null;
        return $idioma ?: $this->em->getRepository(MaestroIdioma::class)->findOneBy(['codigo' => 'en']);
    }

    private function resolveChannel(Beds24BookingDto $dto): ?PmsChannel {
        $id = strtolower(trim((string)($dto->channel ?? 'direct')));
        $repo = $this->em->getRepository(PmsChannel::class);
        return $repo->findOneBy(['beds24ChannelId' => $id])
            ?? $repo->findOneBy(['codigo' => $id])
            ?? $repo->findOneBy(['beds24ChannelId' => 'direct']);
    }

    private function resolveEstado(Beds24BookingDto $dto): PmsEventoEstado {
        $status = trim((string)($dto->status ?? 'new'));
        $repo = $this->em->getRepository(PmsEventoEstado::class);
        return $repo->findOneBy(['codigoBeds24' => $status])
            ?? $repo->findOneBy(['codigoBeds24' => 'new']);
    }

    private function resolveEstadoPagoInicial(Beds24BookingDto $dto): PmsEventoEstadoPago {
        $cod = (strtolower((string)$dto->channel) === 'airbnb') ? 'pago-total' : 'no-pagado';
        $repo = $this->em->getRepository(PmsEventoEstadoPago::class);
        return $repo->findOneBy(['codigo' => $cod])
            ?? $repo->findOneBy(['codigo' => 'no-pagado']);
    }

    private function buildEventoDateTime(?string $date, ?\DateTimeInterface $hora): ?\DateTimeInterface {
        if (!$date) return null;
        try {
            $base = new DateTimeImmutable($date);
            return $hora ? $base->setTime((int)$hora->format('H'), (int)$hora->format('i')) : $base->setTime(0,0);
        } catch (\Throwable) { return null; }
    }

    private function normalizeDecimal(mixed $val): ?string {
        if (is_numeric($val)) return number_format((float)$val, 2, '.', '');
        if (is_string($val)) {
            $v = str_replace([',', ' '], ['.', ''], trim($val));
            return is_numeric($v) ? number_format((float)$v, 2, '.', '') : null;
        }
        if (is_array($val)) {
            foreach (['price', 'amount', 'value', 'total'] as $k) {
                if (isset($val[$k])) return $this->normalizeDecimal($val[$k]);
            }
        }
        return null;
    }

    private function resolveMap(Beds24Config $cfg, Beds24BookingDto $dto): ?PmsUnidadBeds24Map {
        return $this->em->getRepository(PmsUnidadBeds24Map::class)->findOneBy([
            'beds24Config' => $cfg,
            'beds24PropertyId' => (int)$dto->propertyId,
            'beds24RoomId' => (int)$dto->roomId,
        ]);
    }
}