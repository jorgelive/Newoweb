<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Api\Provider\Pms\PmsReservaPaxProvider;
use App\Pms\ApiPlatform\Dto\PmsReservaCrearInput;
use App\Pms\ApiPlatform\State\PmsReservaCrearProcessor;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Maestro\MaestroContacto;
use App\Entity\Maestro\MaestroPais;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\LocatorTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Repository\PmsReservaRepository;
use App\Security\Roles;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PmsReservaRepository::class)]
#[ORM\Table(name: 'pms_reserva')]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/client/pax/pms/pms_reserva/{localizador}',
            uriVariables: [
                'localizador' => new Link(
                    fromClass: PmsReserva::class,
                    identifiers: ['localizador']
                ),
            ],
            normalizationContext: ['groups' => ['pax_reserva:read']],
            name: 'pax_get_reserva',
            // Añade el resumen del estado de cuenta (total/adelanto/saldo) del huésped.
            provider: PmsReservaPaxProvider::class,
        ),
        // ====================================================================
        // App util (Vue) — edición de reservas desde el calendario SPA.
        // No reemplaza el flujo de EasyAdmin, es un acceso adicional.
        // ====================================================================
        new Get(
            uriTemplate: '/pms/pms_reservas/{id}',
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
            normalizationContext: ['groups' => ['pms_reserva:read', 'timestamp:read']],
        ),
        new Patch(
            uriTemplate: '/pms/pms_reservas/{id}',
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar reservas.',
            normalizationContext: ['groups' => ['pms_reserva:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['pms_reserva:write']],
        ),
        // "Reserva completa" (titular + estancia inicial) creada desde el calendario
        // SPA en un único paso atómico. Ver PmsReservaCrearProcessor: solo puede
        // crear reservas de canal DIRECTO, nunca OTA.
        new Post(
            uriTemplate: '/pms/pms_reservas',
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear reservas.',
            input: PmsReservaCrearInput::class,
            normalizationContext: ['groups' => ['pms_reserva:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['pms_reserva_crear:write']],
            processor: PmsReservaCrearProcessor::class,
        ),
        // Borra la reserva y, en cascada, todas sus estancias. El permiso solo abre
        // la puerta: PmsReservaDeleteListener::preRemove veta la operación si alguna
        // de sus estancias no es isSafeToDelete() (OTA, Beds24, sync en curso).
        new Delete(
            uriTemplate: '/pms/pms_reservas/{id}',
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar reservas.',
        ),
    ],
)]
#[ORM\HasLifecycleCallbacks]
class PmsReserva
{
    use IdTrait;
    use LocatorTrait;
    use TimestampTrait;

    #[ORM\Column(type: 'bigint', unique: true, nullable: true)]
    private ?string $beds24MasterId = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $beds24BookIdPrincipal = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Assert\NotBlank(message: 'El nombre del cliente es obligatorio.')]
    #[Assert\Length(max: 180)]
    private ?string $nombreCliente = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    private ?string $apellidoCliente = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $telefono = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $telefono2 = null;

    /**
     * El operador marca cuál de los dos números es el bueno para contactar.
     *
     * Existe porque hoy los dos son móviles: la distinción `telefono`/`telefono2`
     * ya no dice cuál usar, solo en qué orden llegaron. Sin esto, TODO el sistema
     * (WhatsApp, plantillas, vCard) llamaba siempre al primero, aunque el
     * operador supiera que el bueno era el segundo.
     *
     * **No se consulta directamente: se usa `getTelefonoContacto()`.** El flag
     * solo importa cuando los dos números están rellenos, y esa resolución vive
     * en un único sitio a propósito — antes el `telefono ?? telefono2` estaba
     * copiado en siete.
     */
    #[ORM\Column(name: 'telefono2_es_principal', type: 'boolean', options: ['default' => false])]
    #[Groups(['pms_reserva:read', 'pms_reserva:write'])]
    private bool $telefono2EsPrincipal = false;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    #[Assert\Email(message: 'El formato del email no es válido.')]
    #[Assert\Length(max: 150)]
    private ?string $emailCliente = null;

    #[ORM\ManyToOne(targetEntity: PmsEstablecimiento::class, inversedBy: 'reservas')]
    #[ORM\JoinColumn(name: 'establecimiento_id', referencedColumnName: 'id', nullable: false)]
    private ?PmsEstablecimiento $establecimiento = null;

    #[ORM\ManyToOne(targetEntity: PmsChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: true)]
    private ?PmsChannel $channel = null;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroMoneda $moneda = null;

    /**
     * La PERSONA que hay detrás de esta reserva, cuando se sabe cuál es.
     *
     * ── Todavía no manda, y por eso es nullable ─────────────────────────────
     * ⚠️ Las columnas de cliente de aquí al lado —`nombreCliente`, `telefono`, `emailCliente`…—
     * **siguen siendo la verdad**. Esto es el enganche que permitirá, más adelante, dejar de
     * teclear los mismos datos en tres módulos: la misma persona reserva una casita, contrata
     * un tour y escribe por WhatsApp, y hoy está escrita tres veces con nombres de columna
     * distintos. Corregirle el teléfono en la reserva no lo corrige en su expediente de tours,
     * y nadie se entera hasta que un mensaje sale al número viejo.
     *
     * Es nullable porque se puebla a posteriori ({@see \App\Message\Command\PoblarContactosCommand}
     * y su hermano de enganche) y porque una reserva que llega de una OTA puede no traer
     * teléfono con el que reconocer a nadie. Un `null` aquí no rompe nada: significa «todavía no
     * se ha cruzado», no «esta reserva no tiene cliente».
     *
     * Ver docs/Mensajeria.md §20.
     */
    #[ORM\ManyToOne(targetEntity: MaestroContacto::class)]
    #[ORM\JoinColumn(name: 'contacto_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MaestroContacto $contacto = null;

    #[ORM\ManyToOne(targetEntity: MaestroPais::class, inversedBy: 'reservas')]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroPais $pais = null;

    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: 'El idioma es obligatorio.')]
    private ?MaestroIdioma $idioma = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero(message: 'La cantidad de adultos no puede ser negativa.')]
    private ?int $cantidadAdultos = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero(message: 'La cantidad de niños no puede ser negativa.')]
    private ?int $cantidadNinos = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'El monto total no puede ser negativo.')]
    private ?string $montoTotal = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'La comisión no puede ser negativa.')]
    private ?string $comisionTotal = null;

    #[ORM\Column(type: 'date', nullable: true)]
    #[Assert\NotNull(message: 'La fecha de llegada es obligatoria.')]
    private ?DateTimeInterface $fechaLlegada = null;

    #[ORM\Column(type: 'date', nullable: true)]
    #[Assert\NotNull(message: 'La fecha de salida es obligatoria.')]
    #[Assert\GreaterThan(propertyPath: 'fechaLlegada', message: 'La fecha de salida debe ser posterior a la fecha de llegada.')]
    private ?DateTimeInterface $fechaSalida = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $datosLocked = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nota = null;

    /** @var Collection<int, PmsEventoCalendario> */
    #[ORM\OneToMany(mappedBy: 'reserva', targetEntity: PmsEventoCalendario::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $eventosCalendario;

    /** @var Collection<int, PmsReservaHuesped> */
    #[ORM\OneToMany(mappedBy: 'reserva', targetEntity: PmsReservaHuesped::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $huespedes;

    /**
     * Cabecera financiera (1:1). Existe SOLO para que el borrado cascadee.
     *
     * Sin este lado inverso, Doctrine ignoraba `pms_informacion_financiera` al borrar la
     * reserva y MySQL abortaba con «1451 Cannot delete or update a parent row». Como toda
     * reserva tiene su cabecera, el borrado fallaba siempre, sin importar el estado ni el
     * canal. La cascada de aquí arrastra además sus cargos y pagos, que ya la tenían
     * declarada en `PmsInformacionFinanciera`.
     *
     * Sin `#[Groups]` a propósito: no viaja en ninguna serialización. El detalle financiero
     * se sirve por su propio endpoint (`pms_finanzas:read`) y el resumen del huésped lo
     * arma `PmsReservaPaxProvider` (ver `$resumenFinancieroCliente`).
     */
    #[ORM\OneToOne(mappedBy: 'reserva', targetEntity: PmsInformacionFinanciera::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?PmsInformacionFinanciera $informacionFinanciera = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $canalesAggregate = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $unidadesAggregate = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $referenciaCanalAggregate = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $horaLlegadaCanalAggregate = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $primeraFechaReservaCanal = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $ultimaFechaModificacionCanal = null;

    public function __construct()
    {
        $this->eventosCalendario = new ArrayCollection();
        $this->huespedes = new ArrayCollection();
        $this->initializeLocator();
        $this->id = Uuid::v7();
    }

    #[Groups(['pax_reserva:read', 'pms_reserva:read'])]
    public function getLocalizador(): ?string { return $this->localizador; }

    #[Groups(['pax_reserva:read'])]
    public function getNombreCompleto(): ?string { return $this->getNombreApellido(); }

    #[Groups(['pax_reserva:read'])]
    public function getNumeroNoches(): int { return $this->getNoches(); }

    #[Groups(['pax_reserva:read'])]
    public function getPaxTotal(): int { return ($this->cantidadAdultos ?? 0) + ($this->cantidadNinos ?? 0); }

    #[Groups(['pax_reserva:read'])]
    public function getNombreHotel(): string {
        $evento = $this->eventosCalendario->first();
        if ($evento && $evento->getPmsUnidad()) {
            return $evento->getPmsUnidad()->getEstablecimiento()?->getNombreComercial() ?? 'Hotel por confirmar';
        }
        return 'Pendiente de asignación';
    }

    #[Groups(['pax_reserva:read'])]
    public function getNombreHabitacion(): string {
        if ($this->eventosCalendario->isEmpty()) return 'Pendiente';
        $nombres = [];
        foreach ($this->eventosCalendario as $evento) {
            $unidad = $evento->getPmsUnidad();
            if ($unidad && $unidad->getNombre()) $nombres[] = $unidad->getNombre();
        }
        if (empty($nombres)) return 'Habitación estándar';
        return implode(', ', array_unique($nombres));
    }

    public function getEstablecimiento(): ?PmsEstablecimiento { return $this->establecimiento; }
    public function setEstablecimiento(?PmsEstablecimiento $val): self { $this->establecimiento = $val; return $this; }

    public function getNombreApellido(): ?string {
        $full = trim(($this->nombreCliente ?? '') . ' ' . ($this->apellidoCliente ?? ''));
        return $full !== '' ? $full : null;
    }

    public function getNoches(): int {
        if (!$this->fechaLlegada || !$this->fechaSalida) return 0;
        return (int) $this->fechaLlegada->diff($this->fechaSalida)->days;
    }

    #[Groups(['pms_reserva:read'])]
    public function getUrlBeds24(): ?string {
        $bookId = $this->beds24MasterId ?: $this->beds24BookIdPrincipal;
        if (!$bookId) {
            foreach ($this->eventosCalendario as $evento) {
                foreach ($evento->getBeds24Links() as $link) {
                    if ($link->getBeds24BookId()) {
                        $bookId = (string) $link->getBeds24BookId();
                        break 2;
                    }
                }
            }
        }
        return $bookId ? sprintf('https://beds24.com/control2.php?pagetype=bookingedit&bookid=%s', $bookId) : null;
    }

    /**
     * Una reserva solo es borrable si TODAS sus estancias lo son
     * (misma regla que aplica PmsReservaDeleteListener::preRemove).
     */
    #[Groups(['pms_reserva:read'])]
    public function isSafeToDelete(): bool
    {
        return null === $this->getMotivoNoBorrable();
    }

    /**
     * Motivo legible por el que la reserva NO se puede eliminar, o null si sí se puede.
     * Devuelve el de la primera estancia que bloquea la operación.
     */
    #[Groups(['pms_reserva:read'])]
    public function getMotivoNoBorrable(): ?string
    {
        foreach ($this->eventosCalendario as $evento) {
            $motivo = $evento->getMotivoNoBorrable();
            if (null !== $motivo) {
                return $motivo;
            }
        }

        return null;
    }

    /**
     * Peor estado de sincronización de sus estancias: si una está `pending`, la reserva
     * entera lo está. Serializado por el mismo motivo que `PmsEventoCalendario::getSyncStatus()`:
     * el borrado de la reserva completa tiene que esperar a que TODAS sus cancelaciones
     * hayan llegado a Beds24.
     */
    #[Groups(['pms_reserva:read'])]
    public function getSyncStatusAggregate(): string {
        $allSynced = true; $hasError = false;
        if ($this->eventosCalendario->isEmpty()) return 'local';
        foreach ($this->eventosCalendario as $evento) {
            $status = $evento->getSyncStatus();
            if ($status === 'error') { $hasError = true; break; }
            if ($status !== 'synced' && $status !== 'local') $allSynced = false;
        }
        return $hasError ? 'error' : (!$allSynced ? 'pending' : 'synced');
    }

    #[Groups(['pax_reserva:read', 'pms_reserva:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getBeds24MasterId(): ?string { return $this->beds24MasterId; }
    public function setBeds24MasterId(?string $val): self { $this->beds24MasterId = $val; return $this; }

    public function getBeds24BookIdPrincipal(): ?string { return $this->beds24BookIdPrincipal; }
    public function setBeds24BookIdPrincipal(?string $val): self { $this->beds24BookIdPrincipal = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read', 'pms_reserva:write'])]
    public function getNombreCliente(): ?string { return $this->nombreCliente; }
    public function setNombreCliente(?string $val): self { $this->nombreCliente = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read', 'pms_reserva:write'])]
    public function getApellidoCliente(): ?string { return $this->apellidoCliente; }
    public function setApellidoCliente(?string $val): self { $this->apellidoCliente = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read', 'pms_reserva:write'])]
    public function getTelefono(): ?string { return $this->telefono; }
    public function setTelefono(?string $val): self { $this->telefono = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read', 'pms_reserva:write'])]
    public function getTelefono2(): ?string { return $this->telefono2; }

    public function isTelefono2EsPrincipal(): bool { return $this->telefono2EsPrincipal; }
    public function setTelefono2EsPrincipal(bool $val): self { $this->telefono2EsPrincipal = $val; return $this; }

    /**
     * EL número al que hay que escribir. Fuente única de verdad.
     *
     * Todo lo que contacta al huésped pasa por aquí: enlace de WhatsApp, texto
     * de las plantillas (`{{ guest_phone }}`), vCard y los botones de la SPA.
     * Antes cada uno resolvía `telefono ?? telefono2` por su cuenta —siete
     * copias— y marcar un número como principal habría exigido acordarse de los
     * siete.
     *
     * El flag solo decide el ORDEN de preferencia; si el preferido está vacío se
     * cae al otro. Así, marcar como principal un `telefono2` que luego se borra
     * no deja a la reserva sin forma de contacto.
     *
     * Espejo TS: `telefonoContactoDe()` en `util/src/types/pmsReservaModel.ts`,
     * que el drawer necesita para reflejar el formulario SIN GUARDAR. Si cambia
     * la regla, se tocan los dos.
     */
    #[Groups(['pms_reserva:read'])]
    public function getTelefonoContacto(): ?string
    {
        $preferido = $this->telefono2EsPrincipal ? $this->telefono2 : $this->telefono;
        $alterno   = $this->telefono2EsPrincipal ? $this->telefono : $this->telefono2;

        $preferido = trim((string) $preferido);
        $alterno = trim((string) $alterno);

        return $preferido !== '' ? $preferido : ($alterno !== '' ? $alterno : null);
    }
    public function setTelefono2(?string $val): self { $this->telefono2 = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read', 'pms_reserva:write'])]
    public function getEmailCliente(): ?string { return $this->emailCliente; }
    public function setEmailCliente(?string $val): self { $this->emailCliente = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read'])]
    public function getChannel(): ?PmsChannel { return $this->channel; }
    public function setChannel(?PmsChannel $val): self { $this->channel = $val; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $val): self { $this->moneda = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read', 'pms_reserva:write'])]
    public function getContacto(): ?MaestroContacto { return $this->contacto; }
    public function setContacto(?MaestroContacto $contacto): self { $this->contacto = $contacto; return $this; }

    // Mismos grupos que `getIdioma()`, su vecino en el formulario. Sin ellos el país no
    // viajaba en ninguna dirección: el drawer de reservas leía `undefined` y lo que
    // mandaba al guardar se descartaba en silencio, así que el selector no hacía nada.
    #[Groups(['pax_reserva:read', 'pms_reserva:read', 'pms_reserva:write'])]
    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $val): self { $this->pais = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read', 'pms_reserva:write'])]
    public function getIdioma(): ?MaestroIdioma { return $this->idioma; }
    public function setIdioma(?MaestroIdioma $val): self { $this->idioma = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read'])]
    public function getCantidadAdultos(): ?int { return $this->cantidadAdultos; }
    public function setCantidadAdultos(?int $val): self { $this->cantidadAdultos = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read'])]
    public function getCantidadNinos(): ?int { return $this->cantidadNinos; }
    public function setCantidadNinos(?int $val): self { $this->cantidadNinos = $val; return $this; }

    #[Groups(['pms_reserva:read'])]
    public function getMontoTotal(): ?string { return $this->montoTotal; }
    public function setMontoTotal(?string $val): self { $this->montoTotal = $val; return $this; }

    #[Groups(['pms_reserva:read'])]
    public function getComisionTotal(): ?string { return $this->comisionTotal; }
    public function setComisionTotal(?string $val): self { $this->comisionTotal = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read'])]
    public function getFechaLlegada(): ?DateTimeInterface { return $this->fechaLlegada; }
    public function setFechaLlegada(?DateTimeInterface $val): self { $this->fechaLlegada = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pms_reserva:read'])]
    public function getFechaSalida(): ?DateTimeInterface { return $this->fechaSalida; }
    public function setFechaSalida(?DateTimeInterface $val): self { $this->fechaSalida = $val; return $this; }

    #[Groups(['pms_reserva:read', 'pms_reserva:write'])]
    public function isDatosLocked(): bool { return $this->datosLocked; }
    public function setDatosLocked(bool $val): self { $this->datosLocked = $val; return $this; }

    #[Groups(['pms_reserva:read', 'pms_reserva:write'])]
    public function getNota(): ?string { return $this->nota; }
    public function setNota(?string $val): self { $this->nota = $val; return $this; }

    public function getCanalesAggregate(): ?string { return $this->canalesAggregate; }
    public function setCanalesAggregate(?string $val): self { $this->canalesAggregate = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getUnidadesAggregate(): ?string { return $this->unidadesAggregate; }
    public function setUnidadesAggregate(?string $val): self { $this->unidadesAggregate = $val; return $this; }
    #[Groups(['pms_reserva:read'])]
    public function getReferenciaCanalAggregate(): ?string { return $this->referenciaCanalAggregate; }
    public function setReferenciaCanalAggregate(?string $val): self { $this->referenciaCanalAggregate = $val; return $this; }

    public function getHoraLlegadaCanalAggregate(): ?string { return $this->horaLlegadaCanalAggregate; }
    public function setHoraLlegadaCanalAggregate(?string $val): self { $this->horaLlegadaCanalAggregate = $val; return $this; }

    public function getPrimeraFechaReservaCanal(): ?DateTimeInterface { return $this->primeraFechaReservaCanal; }
    public function setPrimeraFechaReservaCanal(?DateTimeInterface $val): self { $this->primeraFechaReservaCanal = $val; return $this; }

    public function getUltimaFechaModificacionCanal(): ?DateTimeInterface { return $this->ultimaFechaModificacionCanal; }
    public function setUltimaFechaModificacionCanal(?DateTimeInterface $val): self { $this->ultimaFechaModificacionCanal = $val; return $this; }

    /**
     * Solo trae `id` (ver Groups en PmsEventoCalendario::getId()): el frontend usa esta
     * lista para saber qué estancias tiene la reserva y luego pide el detalle completo
     * de cada una por GET /pms_evento_calendarios/{id} (mismo endpoint que ya usa para
     * editar un evento individual).
     *
     * @return Collection<int, PmsEventoCalendario>
     */
    #[Groups(['pms_reserva:read'])]
    public function getEventosCalendario(): Collection { return $this->eventosCalendario; }
    public function addEventosCalendario(PmsEventoCalendario $evento): self {
        if (!$this->eventosCalendario->contains($evento)) {
            $this->eventosCalendario->add($evento);
            $evento->setReserva($this);
        }
        return $this;
    }

    /**
     * Devuelve solo los eventos que deben ser visibles en la Guía del Huésped (PAX).
     * Filtra por estados confirmados, asegura que la guía no esté explícitamente deshabilitada
     * y los ordena cronológicamente por la fecha de ingreso (inicio).
     *
     * ¿Por qué existe?: Este método provee la colección exacta de eventos/reservaciones
     * que un huésped debe visualizar en su extranet (PAX), garantizando un formato de lista
     * ordenada y estructurada que la API y el frontend (Vue) puedan consumir directamente.
     *
     * El `@return` tipado NO es decorativo: el tipo nativo es `array` a secas, así que es lo
     * único de lo que API Platform puede deducir qué hay dentro. Cuando esta línea estuvo mal
     * formada —con un asterisco extra delante, que convierte el tag en texto suelto— el schema
     * salía como `string[]` y el frontend tenía que declarar los tipos a mano.
     *
     * @return array<int, PmsEventoCalendario> Arreglo indexado secuencialmente de eventos activos.
     */
    #[Groups(['pax_reserva:read'])]
    public function getEventosActivosGuia(): array
    {
        $estadosPermitidos = PmsEventoEstado::MOSTRAR_EVENTO_GUIA;

        $filtrados = $this->eventosCalendario->filter(function(PmsEventoCalendario $evento) use ($estadosPermitidos) {
            // Validamos estado
            $estadoOk = in_array($evento->getEstado()?->getId(), $estadosPermitidos, true);

            // Validamos que no esté deshabilitado (ajusta el nombre del campo según tu BD)
            $guiaHabilitada = !($evento->isGuiaDisabled());

            return $estadoOk && $guiaHabilitada;
        });

        // IMPORTANTE: .getValues() resetea los índices para que en JSON sea [{}, {}] y no {"1": {}, "2": {}}
        $eventosArray = $filtrados->getValues();

        // Ordenamos los eventos cronológicamente por la fecha de ingreso
        usort($eventosArray, function (PmsEventoCalendario $a, PmsEventoCalendario $b) {
            return $a->getInicio() <=> $b->getInicio();
        });

        return $eventosArray;
    }

    /**
     * ¿Está cancelada esta reserva por completo?
     *
     * La reserva **no tiene estado propio**: lo llevan sus eventos. Está cancelada cuando no le
     * queda ningún tramo vivo — y eso hay que preguntarlo antes de escribirle al huésped o de
     * apuntarle nada, porque el resto de datos (nombre, fechas, casita, chat) siguen ahí
     * intactos y no delatan que la reserva está muerta.
     *
     * **Fuente única de la regla.** La usan `BuscarReservaSkill::paraDistinguir()`,
     * `LocalizarConversacionSkill` y `EnviarMensajeHuespedSkill`; escribirla en cada sitio era
     * garantizar que un día dejaran de coincidir. Ver docs/Mensajeria.md §11.
     *
     * Los `bloqueo` y `extension` no cuentan: no son tramos del huésped sino el efecto de una
     * entrada temprana o una salida tardía, y una reserva cancelada puede conservarlos.
     */
    public function isCancelada(): bool
    {
        $tramos = 0;

        foreach ($this->eventosCalendario as $evento) {
            $estado = $evento->getEstado()?->getId();

            if (in_array($estado, [PmsEventoEstado::CODIGO_BLOQUEO, PmsEventoEstado::CODIGO_EXTENSION], true)) {
                continue;
            }

            ++$tramos;

            if ($estado !== PmsEventoEstado::CODIGO_CANCELADA) {
                return false;
            }
        }

        // Sin tramos no está cancelada: está incompleta, que es otra cosa y no toca decidirla aquí.
        return $tramos > 0;
    }

    public function removeEventosCalendario(PmsEventoCalendario $evento): self {
        if ($this->eventosCalendario->removeElement($evento)) {
            if ($evento->getReserva() === $this) $evento->setReserva(null);
        }
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════════
    // VISTA PÚBLICA (pax) — propiedad virtual, no persistida
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Resumen del estado de cuenta para el huésped: total, adelanto y saldo, en la
     * moneda de la cabecera. Lo llena PmsReservaPaxProvider desde
     * PmsInformacionFinanciera. Ojo: `$informacionFinanciera` existe sólo para cascadear
     * el borrado y no se serializa; este resumen sigue siendo la única vía hacia pax.
     *
     * Es un RESUMEN a propósito: el detalle de cargos/pagos (`pms_finanzas:read`) es del
     * panel interno y no viaja al cliente. Null cuando no hay cabecera o el total es 0
     * — la vista simplemente no pinta la tarjeta.
     *
     * @var array{moneda: string, simbolo: ?string, total: string, pagado: string, saldo: string}|null
     */
    private ?array $resumenFinancieroCliente = null;

    /**
     * @return array<string, mixed>|null
     */
    #[Groups(['pax_reserva:read'])]
    #[SerializedName('resumenFinanciero')]
    public function getResumenFinancieroCliente(): ?array { return $this->resumenFinancieroCliente; }
    /**
     * @param array<string, mixed>|null $resumen
     */
    public function setResumenFinancieroCliente(?array $resumen): self { $this->resumenFinancieroCliente = $resumen; return $this; }

    public function getInformacionFinanciera(): ?PmsInformacionFinanciera { return $this->informacionFinanciera; }

    public function setInformacionFinanciera(?PmsInformacionFinanciera $info): self
    {
        // Mantener el lado propietario sincronizado: es el que escribe `reserva_id`.
        if ($info !== null && $info->getReserva() !== $this) {
            $info->setReserva($this);
        }
        $this->informacionFinanciera = $info;
        return $this;
    }

    /** @return Collection<int, PmsReservaHuesped> */
    public function getHuespedes(): Collection { return $this->huespedes; }
    public function addHuesped(PmsReservaHuesped $huesped): self {
        if (!$this->huespedes->contains($huesped)) {
            $this->huespedes->add($huesped);
            $huesped->setReserva($this);
        }
        return $this;
    }
    public function removeHuesped(PmsReservaHuesped $huesped): self {
        if ($this->huespedes->removeElement($huesped)) {
            if ($huesped->getReserva() === $this) $huesped->setReserva(null);
        }
        return $this;
    }

    public function __toString(): string {
        return sprintf('%s - %s (%s)',
            $this->getNombreApellido() ?? 'Huésped Desconocido',
            $this->referenciaCanalAggregate ?? 'Sin Ref.',
            $this->getLocalizador() ?? 'Sin Loc.'
        );
    }

    /**
     * 1. EL BUSCADOR BASE: Recorre los links y devuelve el Establecimiento Virtual principal.
     */
    public function getEstablecimientoVirtualPrincipal(): ?PmsEstablecimientoVirtual
    {
        foreach ($this->eventosCalendario as $evento) {
            foreach ($evento->getBeds24Links() as $link) {
                if ($link->isEsPrincipal()) {
                    // Devuelve el objeto completo
                    return $link->getUnidadBeds24Map()?->getVirtualEstablecimiento();
                }
            }
        }
        return null;
    }

    /**
     * REGLA DE NEGOCIO: Determina si la reserva está COMPLETAMENTE cancelada.
     * Verdadero SOLO si tiene eventos y TODOS están en estado cancelado.
     */
    public function isTotalmenteCancelada(): bool
    {
        if ($this->eventosCalendario->isEmpty()) {
            return false; // Si está vacía, es un draft/borrador, no está cancelada
        }

        foreach ($this->eventosCalendario as $evento) {
            if ($evento->getEstado()?->getId() !== PmsEventoEstado::CODIGO_CANCELADA) {
                return false; // Si hay al menos 1 evento vivo, la reserva sigue activa
            }
        }

        return true;
    }

    /**
     * 2. EL ARMADOR DE URL MULTI-CANAL
     */
    #[Groups(['pms_reserva:read'])]
    public function getUrlCanalExtranet(): ?string
    {
        $canalId = $this->getChannel()?->getId(); // Asumiendo que el ID del canal es la constante ('booking', 'airbnb')

        // 1. Si es Directo (o nulo), no hay enlace a extranet
        if (!$canalId || $canalId === PmsChannel::CODIGO_DIRECTO) {
            return null;
        }

        // 2. Extraemos la referencia del canal (El "localizador" de la OTA)
        $referencia = $this->referenciaCanalAggregate;
        if (!$referencia) {
            foreach ($this->eventosCalendario as $evento) {
                if ($evento->getReferenciaCanal()) {
                    $referencia = $evento->getReferenciaCanal();
                    break;
                }
            }
        }

        if (!$referencia) {
            return null;
        }

        $referenciaLimpia = trim(explode(',', $referencia)[0]);

        // 3. ARMADO DE URL SEGÚN EL CANAL
        if ($canalId === PmsChannel::CODIGO_AIRBNB) {
            // En Airbnb el link directo es con el código de reserva (ej: HMX...)
            return sprintf('https://www.airbnb.com/hosting/reservations/details/%s', $referenciaLimpia);
        }

        if ($canalId === PmsChannel::CODIGO_BOOKING) {
            // En Booking necesitamos el Hotel ID que está en el Listing Virtual
            $virtual = $this->getEstablecimientoVirtualPrincipal();
            if (!$virtual || !$virtual->getCodigoExterno()) {
                return null;
            }

            return sprintf(
                'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/booking.html?hotel_id=%s&res_id=%s',
                trim($virtual->getCodigoExterno()),
                $referenciaLimpia
            );
        }

        // Si es VRBO u otro, por ahora devolvemos null (puedes agregar más if después)
        return null;
    }

    /**
     * Getter virtual para EasyAdmin (Trazabilidad)
     */
    public function getTrazabilidadEventos(): ?string
    {
        return null;
    }

}