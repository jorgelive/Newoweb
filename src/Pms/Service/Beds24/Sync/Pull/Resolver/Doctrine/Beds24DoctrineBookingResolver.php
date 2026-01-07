<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Sync\Pull\Resolver\Doctrine;

use App\Entity\MaestroIdioma;
use App\Entity\MaestroPais;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Service\Beds24\Sync\Pull\Resolver\Beds24BookingResolverInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class Beds24DoctrineBookingResolver implements Beds24BookingResolverInterface
{
    /**
     * Cache in-memory para evitar duplicados dentro del MISMO flush/transaction.
     * Doctrine no “ve” entidades nuevas no-flusheadas cuando haces findOneBy(),
     * por eso sin esto puedes crear dos reservas con el mismo masterId y romper el UNIQUE.
     *
     * @var array<string, PmsReserva>
     */
    private static array $reservaByMasterId = [];

    /**
     * @var array<string, PmsReserva>
     */
    private static array $reservaByBookIdPrincipal = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    private function normalizeIdString(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $s = trim((string) $id);
        return $s === '' ? null : $s;
    }

    private function cacheReserva(PmsReserva $reserva): void
    {
        $master = $reserva->getBeds24MasterId();
        if ($master !== null && $master !== '') {
            self::$reservaByMasterId[$master] = $reserva;
        }

        $principal = $reserva->getBeds24BookIdPrincipal();
        if ($principal !== null && $principal !== '') {
            self::$reservaByBookIdPrincipal[$principal] = $reserva;
        }
    }

    private function findReservaInIdentityMap(?string $effectiveMasterIdStr, ?string $masterIdStr, ?string $bookIdStr): ?PmsReserva
    {
        $uow = $this->em->getUnitOfWork();
        $identityMap = $uow->getIdentityMap();

        if (!isset($identityMap[PmsReserva::class])) {
            return null;
        }

        foreach ($identityMap[PmsReserva::class] as $managed) {
            if (!$managed instanceof PmsReserva) {
                continue;
            }

            $m = $managed->getBeds24MasterId();
            if ($m !== null && $m !== '') {
                if ($effectiveMasterIdStr !== null && $m === $effectiveMasterIdStr) {
                    return $managed;
                }
                if ($masterIdStr !== null && $m === $masterIdStr) {
                    return $managed;
                }
            }

            $p = $managed->getBeds24BookIdPrincipal();
            if ($p !== null && $p !== '') {
                if ($bookIdStr !== null && $p === $bookIdStr) {
                    return $managed;
                }
                if ($masterIdStr !== null && $p === $masterIdStr) {
                    return $managed;
                }
            }
        }

        return null;
    }

    public function upsert(Beds24Config $config, Beds24BookingDto $booking): void
    {
        $map = $this->resolveMap($config, $booking);
        if (!$map) {
            // TODO: loggear / guardar en tabla de errores para revisar mapeos faltantes
            return;
        }

        // Detectar si este booking ya existe como link y si es mirror.
        // Mirrors NO deben tocar cabeceras (PmsReserva). Solo actualizan su propio link/evento.
        $existingLink = null;
        $isMirror = false;

        if ($booking->id) {
            /** @var PmsEventoBeds24Link|null $existingLink */
            $existingLink = $this->em->getRepository(PmsEventoBeds24Link::class)
                ->findOneBy(['beds24BookId' => (string) $booking->id]);

            // Si el link existe y es mirror, este payload NO debe crear/actualizar reserva.
            $isMirror = (bool) ($existingLink?->isMirror() ?? false);
        }

        $reserva = null;
        if (!$isMirror) {
            $reserva = $this->upsertReserva($booking);
        }

        $this->upsertEvento($booking, $map, $reserva, $existingLink, $isMirror);


        // Nota: NO hacemos flush() aquí.
        // El flush/transaction boundary lo controla el orquestador (o el caller) para asegurar atomicidad.
    }

    private function resolveMap(Beds24Config $config, Beds24BookingDto $booking): ?PmsUnidadBeds24Map
    {
        $propertyId = $booking->propertyId;
        $roomId = $booking->roomId;

        if (!$propertyId || !$roomId) {
            return null;
        }

        return $this->em->getRepository(PmsUnidadBeds24Map::class)->findOneBy([
            'beds24Config' => $config,
            'beds24PropertyId' => (int) $propertyId,
            'beds24RoomId' => (int) $roomId,
        ]);
    }

    private function upsertReserva(Beds24BookingDto $booking): PmsReserva
    {
        $repo = $this->em->getRepository(PmsReserva::class);

        $beds24BookId = $booking->id;        // id del booking actual (puede ser master o child)
        $beds24MasterId = $booking->masterId; // masterId del booking actual (puede venir null en el master row)

        $bookIdStr = $this->normalizeIdString($beds24BookId);
        $masterIdStr = $this->normalizeIdString($beds24MasterId);

        // --------------------------------------------------------------------
        // Regla Beds24 real:
        // - Si viene masterId => este booking es "child" dentro de una reserva multi-room.
        // - Si NO viene masterId => este booking es el "master", y en la UI Beds24 se ve como si
        //   su masterId fuera su propio id (aunque el API devuelva null).
        //
        // Tu regla de consistencia:
        // - effectiveMasterId = masterId si existe, sino id
        //
        // IMPORTANTE: antes de crear una reserva, SIEMPRE intentamos resolver por effectiveMasterId.
        // Esto evita duplicados cuando llega primero un child y luego el master (o viceversa).
        // --------------------------------------------------------------------
        $effectiveMasterIdStr = $masterIdStr ?: $bookIdStr;

        /** @var PmsReserva|null $reserva */
        $reserva = null;

        // 0) Cache in-memory (clave para evitar duplicados antes del flush)
        if ($effectiveMasterIdStr !== null && isset(self::$reservaByMasterId[$effectiveMasterIdStr])) {
            $reserva = self::$reservaByMasterId[$effectiveMasterIdStr];
        }

        if (!$reserva && $masterIdStr !== null && isset(self::$reservaByMasterId[$masterIdStr])) {
            $reserva = self::$reservaByMasterId[$masterIdStr];
        }

        if (!$reserva && $bookIdStr !== null && isset(self::$reservaByBookIdPrincipal[$bookIdStr])) {
            $reserva = self::$reservaByBookIdPrincipal[$bookIdStr];
        }

        // 0b) IdentityMap / UnitOfWork (incluye entidades MANAGED creadas en esta misma transacción)
        if (!$reserva) {
            $reserva = $this->findReservaInIdentityMap($effectiveMasterIdStr, $masterIdStr, $bookIdStr);
            if ($reserva) {
                $this->cacheReserva($reserva);
            }
        }

        // 1) Identidad principal: master efectivo (DB)
        if (!$reserva && $effectiveMasterIdStr !== null) {
            $reserva = $repo->findOneBy(['beds24MasterId' => $effectiveMasterIdStr]);
            if ($reserva) {
                $this->cacheReserva($reserva);
            }
        }

        // 2) Fallback histórico: por si en data vieja el masterId no estaba poblado
        if (!$reserva && $masterIdStr !== null) {
            $reserva = $repo->findOneBy(['beds24BookIdPrincipal' => $masterIdStr]);
            if ($reserva) {
                $this->cacheReserva($reserva);
            }
        }

        // 3) Fallback general: por el id actual
        if (!$reserva && $bookIdStr !== null) {
            $reserva = $repo->findOneBy(['beds24BookIdPrincipal' => $bookIdStr]);
            if ($reserva) {
                $this->cacheReserva($reserva);
            }
        }

        $isNew = false;

        if (!$reserva) {
            $reserva = new PmsReserva();
            $this->em->persist($reserva);
            $isNew = true;
        }

        // Guardar en cache incluso antes de setear IDs (evita duplicados en loops)
        // Nota: la cache se refresca al final cuando ya seteamos master/principal.
        if ($reserva !== null) {
            // cache provisional por effectiveMasterId si existe
            if ($effectiveMasterIdStr !== null) {
                self::$reservaByMasterId[$effectiveMasterIdStr] = $reserva;
            }

            if ($bookIdStr !== null) {
                self::$reservaByBookIdPrincipal[$bookIdStr] = $reserva;
            }
        }

        // --------------------------------------------------------------------
        // Identificadores B24 (consistentes con tu regla)
        // - Siempre guardamos beds24MasterId = effectiveMasterId
        // - beds24BookIdPrincipal:
        //    * si llegó primero un child (tiene masterId), guardamos su id como principal temporal.
        //    * cuando llegue el master (masterId null), actualizamos principal al id del master.
        // --------------------------------------------------------------------
        if ($effectiveMasterIdStr !== null) {
            $reserva->setBeds24MasterId($effectiveMasterIdStr);
        } else {
            $reserva->setBeds24MasterId(null);
        }

        // Si este payload es el "master row" (masterId null), el principal DEBE ser su propio id.
        // Si es child, dejamos el principal como el primero que llegó (si aún no hay), y luego
        // el master lo corregirá cuando aparezca.
        if ($masterIdStr === null) {
            $reserva->setBeds24BookIdPrincipal($bookIdStr);
        } else {
            // Child: solo setear principal si aún está vacío.
            // (Así no pisas el principal cuando el master ya llegó y lo seteo)
            if ($bookIdStr !== null && ($reserva->getBeds24BookIdPrincipal() === null || $reserva->getBeds24BookIdPrincipal() === '')) {
                $reserva->setBeds24BookIdPrincipal($bookIdStr);
            }
        }

        // Refrescar cache con valores definitivos (master/principal)
        $this->cacheReserva($reserva);

        $reserva->setReferenciaCanal($booking->apiReference);

        // Canal (Beds24 payload `channel` → PmsChannel)
        // IMPORTANT:
        // - Solo se asigna en CREACIÓN (primer pull).
        // - Si luego hacemos sync de mirrors/replicas (p.ej. para bloquear unidades),
        //   el payload puede venir con channel=airbnb/booking y NO debe pisar el canal real
        //   de la reserva ya existente en el PMS.
        if ($isNew) {
            $canal = $this->resolveChannel($booking);
            if ($canal) {
                $reserva->setChannel($canal);
            }
        }

        // Datos personales (solo se setean si NO están bloqueados)
        $datosLocked = (bool) ($reserva->isDatosLocked() ?? false);

        if (!$datosLocked) {
            $reserva->setNombreCliente($booking->firstName);
            $reserva->setApellidoCliente($booking->lastName);
            $reserva->setEmailCliente($booking->email);
            $reserva->setTelefono($booking->phone);
            $reserva->setTelefono2($booking->mobile);
            $reserva->setNota($booking->notes);

            // País (ISO2 → MaestroPais, fallback Perú)
            $reserva->setPais($this->resolvePais($booking));

            // Idioma (lang → MaestroIdioma.codigo, fallback 'en')
            $reserva->setIdioma($this->resolveIdioma($booking));
        } else {
            // Seguridad: idioma es NOT NULL; si por data vieja viene null, lo reponemos.
            if (method_exists($reserva, 'getIdioma') && $reserva->getIdioma() === null) {
                $reserva->setIdioma($this->resolveIdioma($booking));
            }
        }

        $reserva->setComentariosHuesped($booking->comments);
        $reserva->setHoraLlegadaCanal($booking->arrivalTime);

        // En el primer ingreso por sincronización, bloqueamos datos personales automáticamente
        if ($isNew) {
            $reserva->setDatosLocked(true);
        }

        // Fechas
        // Nota: las fechas de llegada/salida de la reserva se calculan/actualizan por listener
        // a partir de los eventos del calendario (inicio/fin). Por eso no las seteamos aquí.

        $reserva->setFechaReservaCanal($booking->bookingTime);
        $reserva->setFechaModificacionCanal($booking->modifiedTime);

        // Totales reserva
        // Nota: el monto total, la comisión total, la cantidad de adultos y niños de la reserva se calcula por listener como suma de montos de eventos.
        // Por eso no lo seteamos aquí.

        return $reserva;
    }

    private function upsertEvento(
        Beds24BookingDto $booking,
        PmsUnidadBeds24Map $map,
        ?PmsReserva $reserva,
        ?PmsEventoBeds24Link $existingLink = null,
        bool $isMirror = false
    ): void
    {
        $beds24BookId = $booking->id;
        if (!$beds24BookId) {
            return;
        }

        // 1) Intentamos resolver el evento a través del Link (bookId es la clave estable)
        if ($existingLink === null) {
            /** @var PmsEventoBeds24Link|null $existingLink */
            $existingLink = $this->em->getRepository(PmsEventoBeds24Link::class)
                ->findOneBy(['beds24BookId' => (string) $beds24BookId]);
        }

        /** @var PmsEventoCalendario|null $evento */
        $evento = $existingLink?->getEvento();
        $isInsert = ($evento === null);

        if (!$evento) {
            $evento = new PmsEventoCalendario();
            $this->em->persist($evento);
        }

        // IMPORTANT: tu DB NO permite NULL en pms_unidad_id
        $evento->setPmsUnidad($map->getPmsUnidad());

        // Mirror links no deben modificar cabecera (reserva) ni re-asignar la reserva.
        // Solo el link/evento espejo se actualiza.
        if (!$isMirror && $reserva !== null) {
            $evento->setReserva($reserva);
        }

        // Fechas (en evento son datetime NOT NULL)
        $establecimiento = $map->getPmsUnidad()?->getEstablecimiento();

        $horaCheckIn = $establecimiento?->getHoraCheckIn();
        $horaCheckOut = $establecimiento?->getHoraCheckOut();

        $evento->setInicio(
            $this->buildEventoDateTime($booking->arrival ?? null, $horaCheckIn)
        );

        $evento->setFin(
            $this->buildEventoDateTime($booking->departure ?? null, $horaCheckOut)
        );

        // Estado Beds24 crudo (solo lectura en el PMS)
        $evento->setEstadoBeds24($booking->status ?? null);
        $evento->setSubestadoBeds24($booking->subStatus ?? null);

        // Estado normalizado PMS (mapeo por codigoBeds24)
        $evento->setEstado(
            $this->resolveEstado($booking)
        );

        // Estado de pago: SOLO en inserción. Luego el PMS manda (ediciones manuales).
        if ($isInsert) {
            $evento->setEstadoPago($this->resolveEstadoPagoInicial($booking));
        }

        // Pax por habitación
        $evento->setCantidadAdultos($booking->numAdult ?? 0);
        $evento->setCantidadNinos($booking->numChild ?? 0);

        // Monto y comisión por habitación (Beds24)
        $evento->setMonto(
            $this->normalizeDecimal($booking->price ?? null)
        );

        $evento->setComision(
            $this->normalizeDecimal($booking->commission ?? null)
        );

        $evento->setRateDescription(
            $booking->rateDescription ?? null
        );

        // Cache rápido (opcional)
        $titulo = trim((string) ($booking->firstName ?? '') . ' ' . (string) ($booking->lastName ?? ''));
        $evento->setTituloCache($titulo !== '' ? $titulo : null);
        // Para mirrors evitamos propagar el canal del payload hacia caches locales.
        // (Ej: mirrors pueden venir con channel=airbnb/booking aunque sea una reserva directa real)
        if (!$isMirror) {
            $evento->setOrigenCache($booking->channel ?? null);
        }

        // 2) Upsert explícito del Link (evento + map + bookId)
        $this->upsertBeds24Link(
            evento: $evento,
            map: $map,
            beds24BookId: (string) $beds24BookId,
            originLink: null
        );
    }

    /**
     * @param PmsEventoBeds24Link|null $originLink
     * El origin link es null por defecto ya que es el link principal normalmente lo que llega al resolver sera de este caso.
     */
    private function upsertBeds24Link(
        PmsEventoCalendario $evento,
        PmsUnidadBeds24Map $map,
        string $beds24BookId,
        ?PmsEventoBeds24Link $originLink = null
    ): PmsEventoBeds24Link {
        $repo = $this->em->getRepository(PmsEventoBeds24Link::class);

        /** @var PmsEventoBeds24Link|null $link */
        $link = $repo->findOneBy(['beds24BookId' => $beds24BookId]);

        if (!$link) {
            $link = new PmsEventoBeds24Link();
            $this->em->persist($link);
        }

        // Relación fuerte
        $link->setEvento($evento);
        $link->setUnidadBeds24Map($map);

        // Identificador estable
        $link->setBeds24BookId($beds24BookId);

        // Si en algún flujo futuro quieres marcar mirrors, aquí queda listo
        if ($originLink !== null) {
            $link->setOriginLink($originLink);
        }

        // Auditoría simple para resync
        $link->setLastSeenAt(new DateTimeImmutable('now'));

        // Mantener consistencia del lado inverso (útil para UI / cascade)
        if (method_exists($evento, 'addBeds24Link')) {
            $evento->addBeds24Link($link);
        }

        return $link;
    }

    private function buildEventoDateTime(
        ?string $date,
        ?\DateTimeInterface $hora
    ): ?\DateTimeInterface {
        if (!$date) {
            return null;
        }

        try {
            // Fecha base YYYY-MM-DD
            $base = new DateTimeImmutable($date);

            if ($hora) {
                return $base->setTime(
                    (int) $hora->format('H'),
                    (int) $hora->format('i'),
                    (int) $hora->format('s')
                );
            }

            // Fallback seguro si el establecimiento no tiene hora configurada
            return $base->setTime(0, 0, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveChannel(Beds24BookingDto $booking): ?PmsChannel
    {
        $raw = $booking->channel ?? null;
        if ($raw === null) {
            return null;
        }

        $beds24ChannelId = strtolower(trim((string) $raw));
        if ($beds24ChannelId === '') {
            return null;
        }

        /** @var PmsChannel|null $canal */
        $canal = $this->em->getRepository(PmsChannel::class)
            ->findOneBy(['beds24ChannelId' => $beds24ChannelId]);

        // Fallback por compatibilidad si aún hay data vieja mapeada por codigo
        if (!$canal) {
            $canal = $this->em->getRepository(PmsChannel::class)
                ->findOneBy(['codigo' => $beds24ChannelId]);
        }

        // Último fallback: si no se pudo resolver el canal, asumimos "direct"
        if (!$canal) {
            $canal = $this->em->getRepository(PmsChannel::class)
                ->findOneBy(['beds24ChannelId' => 'direct']);

            if (!$canal) {
                $canal = $this->em->getRepository(PmsChannel::class)
                    ->findOneBy(['codigo' => 'direct']);
            }
        }

        return $canal;
    }

    private function resolvePais(Beds24BookingDto $booking): MaestroPais
    {
        $iso2 = strtoupper((string) ($booking->country2 ?? ''));

        if ($iso2 !== '') {
            $pais = $this->em->getRepository(MaestroPais::class)
                ->findOneBy(['iso2' => $iso2]);

            if ($pais) {
                return $pais;
            }
        }

        return $this->em->getRepository(MaestroPais::class)
            ->find(MaestroPais::DB_VALOR_PERU);
    }

    private function resolveIdioma(Beds24BookingDto $booking): MaestroIdioma
    {
        $codigo = strtolower((string) ($booking->lang ?? ''));

        if ($codigo !== '') {
            $idioma = $this->em->getRepository(MaestroIdioma::class)
                ->findOneBy(['codigo' => $codigo]);

            if ($idioma) {
                return $idioma;
            }
        }

        return $this->em->getRepository(MaestroIdioma::class)
            ->findOneBy(['codigo' => 'en']);
    }

    private function resolveEstado(Beds24BookingDto $booking): PmsEventoEstado
    {
        $repo = $this->em->getRepository(PmsEventoEstado::class);

        $status = (string) ($booking->status ?? '');
        $status = trim($status);

        if ($status !== '') {
            /** @var PmsEventoEstado|null $estado */
            $estado = $repo->findOneBy(['codigoBeds24' => $status]);
            if ($estado) {
                return $estado;
            }
        }

        /** @var PmsEventoEstado|null $fallback */
        $fallback = $repo->findOneBy(['codigoBeds24' => 'new']);
        if ($fallback) {
            return $fallback;
        }

        throw new \RuntimeException('No se pudo resolver PmsEventoEstado: no existe estado para status="'.$status.'" y tampoco existe el fallback codigoBeds24="new".');
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Escalares numéricos
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        // Strings (pueden venir con coma decimal)
        if (is_string($value)) {
            $v = trim($value);
            if ($v === '') {
                return null;
            }

            $v = str_replace(' ', '', $v);
            $v = str_replace(',', '.', $v);

            // Permitir signos y punto decimal
            if (!preg_match('/^-?\d+(?:\.\d+)?$/', $v)) {
                return null;
            }

            return number_format((float) $v, 2, '.', '');
        }

        // Arrays/estructuras: intentamos extraer el primer valor numérico conocido.
        if (is_array($value)) {
            foreach (['price', 'amount', 'value', 'total', 'gross', 'net'] as $key) {
                if (array_key_exists($key, $value)) {
                    $normalized = $this->normalizeDecimal($value[$key]);
                    if ($normalized !== null) {
                        return $normalized;
                    }
                }
            }

            foreach ($value as $item) {
                $normalized = $this->normalizeDecimal($item);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function resolveEstadoPagoInicial(Beds24BookingDto $booking): PmsEventoEstadoPago
    {
        $channel = strtolower(trim((string) ($booking->channel ?? '')));

        // Regla: SOLO en inserción.
        // - Airbnb: pago-total
        // - resto: no-pagado
        $codigo = ($channel === 'airbnb') ? 'pago-total' : 'no-pagado';

        $repo = $this->em->getRepository(PmsEventoEstadoPago::class);

        /** @var PmsEventoEstadoPago|null $estado */
        $estado = $repo->findOneBy(['codigo' => $codigo]);
        if ($estado) {
            return $estado;
        }

        /** @var PmsEventoEstadoPago|null $fallback */
        $fallback = $repo->findOneBy(['codigo' => 'no-pagado']);
        if ($fallback) {
            return $fallback;
        }

        throw new \RuntimeException('No se pudo resolver PmsEventoEstadoPago: no existe codigo="'.$codigo.'" y tampoco existe el fallback codigo="no-pagado".');
    }
}
