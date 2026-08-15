<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Maestro\MaestroIdioma;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Repository\PmsCargoFinancieroRepository;
use App\Security\Roles;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Un concepto financiero individual de una reserva Beds24 (invoiceItem).
 *
 * Espejo estructural de App\Message\Entity\Message: el hijo dentro de la "conversación"
 * financiera (PmsInformacionFinanciera). Cada fila corresponde 1:1 a un invoiceItem de
 * Beds24 (costo de alojamiento, limpieza, cargo por servicio, pagos, etc.).
 *
 * VERDAD HISTÓRICA: se guarda el invoiceItem tal cual llegó (subType/description/amount)
 * sin interpretarlo. La clasificación (alojamiento vs limpieza vs servicio) es el subTipo
 * de Beds24; no se transforma aquí.
 */
#[ApiResource(
    operations: [
        new Get(security: "is_granted('" . Roles::RESERVAS_SHOW . "')"),
        // Post crea un cargo MANUAL (beds24ItemId = null). Es el camino de las
        // reservas directas, que nunca reciben invoiceItems del canal.
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear cargos.',
        ),
        // `pms_cargo:patch` excluye moneda y tipoCambio: quedan fijos al registrar el
        // importe (el listener de coherencia bloquea el cambio de moneda de todas formas).
        new Patch(
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar cargos.',
            denormalizationContext: ['groups' => ['pms_cargo:patch']],
        ),
        // Delete SÓLO para cargos manuales: borrar uno de Beds24 no serviría de nada
        // (el siguiente pull lo recrearía) y desincronizaría el saldo mientras tanto.
        // Lo veta PmsInformacionFinancieraCoherenciaListener::assertCargoBorrable().
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar cargos.',
        ),
    ],
    routePrefix: '/pms',
    normalizationContext: ['groups' => ['pms_cargo:read', 'maestro:moneda:read']],
    denormalizationContext: ['groups' => ['pms_cargo:write']],
)]
#[ORM\Entity(repositoryClass: PmsCargoFinancieroRepository::class)]
#[ORM\Table(name: 'pms_cargo_financiero')]
#[ORM\UniqueConstraint(name: 'uq_cargo_info_item', columns: ['informacion_id', 'beds24_item_id'])]
#[ORM\HasLifecycleCallbacks]
class PmsCargoFinanciero
{
    use IdTrait;
    use TimestampTrait;

    /**
     * Sin este trait, `$descripcionCliente` NUNCA se traduce.
     *
     * No es decorativo: `AutoTranslationService::processEntity()` arranca preguntando por
     * `getEjecutarTraduccion()` —que llega justamente de aquí— y si el método no existe se
     * va sin mirar una sola propiedad. El atributo `#[AutoTranslate]` de abajo queda inerte,
     * y el fallo es silencioso: se guarda el español y no aparece ningún otro idioma.
     *
     * Esta entidad estuvo un tiempo así, siendo la única del proyecto con `#[AutoTranslate]`
     * sin el trait. Si añades el atributo a una entidad nueva, añade también el trait.
     */
    use AutoTranslateControlTrait;

    public const string TIPO_CARGO = 'charge';
    public const string TIPO_PAGO  = 'payment';

    #[ORM\ManyToOne(targetEntity: PmsInformacionFinanciera::class, inversedBy: 'cargos')]
    #[ORM\JoinColumn(name: 'informacion_id', referencedColumnName: 'id', nullable: false)]
    #[Groups(['pms_cargo:write'])]
    private ?PmsInformacionFinanciera $informacionFinanciera = null;

    /**
     * Estancia a la que se imputa el cargo, para reservas con varias casitas.
     *
     * Sólo lo usan los cargos MANUALES: los de Beds24 se atribuyen por `beds24BookingId`
     * (§11.6), que ya identifica su booking. Aquí no hay booking del canal, así que el
     * operador elige la estancia a mano. NULL = cargo de la reserva en conjunto (lo normal
     * cuando sólo hay una casita).
     */
    #[ORM\ManyToOne(targetEntity: PmsEventoCalendario::class)]
    #[ORM\JoinColumn(name: 'evento_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?PmsEventoCalendario $evento = null;

    /**
     * ID del invoiceItem en Beds24. Llave de dedupe dentro del padre.
     *
     * NULL = cargo **manual**, creado a mano por el operador (típico de una reserva
     * directa, que no recibe información financiera del canal). MySQL admite varios
     * NULL en el índice único, así que el dedupe de los cargos de Beds24 sigue intacto.
     */
    #[ORM\Column(name: 'beds24_item_id', type: 'string', length: 50, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $beds24ItemId = null;

    /**
     * ID del booking de Beds24 al que pertenece este cargo.
     * Importa: una reserva-grupo (master) agrupa varios bookings.
     */
    #[ORM\Column(name: 'beds24_booking_id', type: 'string', length: 50, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $beds24BookingId = null;

    /** ID de la factura en Beds24. Sólo lo trae el endpoint on-demand, NO el webhook. */
    #[ORM\Column(name: 'beds24_invoice_id', type: 'string', length: 50, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $beds24InvoiceId = null;

    /** ID del facturado (invoiceeId) en Beds24, si aplica. */
    #[ORM\Column(name: 'beds24_invoicee_id', type: 'string', length: 50, nullable: true)]
    private ?string $beds24InvoiceeId = null;

    /** type de Beds24: 'charge' | 'payment'. */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $tipo = null;

    /** subType de Beds24 (8 = alojamiento, 11 = limpieza/servicio, etc.). Verdad histórica cruda. */
    #[ORM\Column(name: 'sub_tipo', type: 'integer', nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?int $subTipo = null;

    /** Clasificación estandarizada y traducible derivada de subTipo/descripción. */
    #[ORM\Column(name: 'tipo_cargo', type: 'string', length: 20, enumType: PmsTipoCargo::class, nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?PmsTipoCargo $tipoCargo = null;

    /**
     * Espejo contable de un canal que cobra al huésped por nosotros (Airbnb, VRBO:
     * `PmsChannel::CANAL_PAGO_TOTAL`). Es la contraparte del `esAutomatico` de
     * PmsPagoFinanciero, y sirve para lo mismo: poder EXCLUIRLO del estado de
     * cuenta que ve el huésped sin adivinar por importe ni por subtipo.
     *
     * Por qué hay que excluirlo: en esos canales el importe que guardamos es lo
     * que la OTA nos remite, no lo que el huésped pagó (que incluye la comisión
     * de servicio de la OTA). Enseñárselo invita a una conversación incómoda
     * sobre por qué las cifras no cuadran.
     *
     * ⚠️ NO significa "lo generó el sistema". PmsCargosAutomaticosService también
     * genera cargos solo —los de reservas DIRECTAS, desde el tarifario— y esos
     * llevan el flag en false a propósito: el huésped los paga a nosotros y tiene
     * que verlos. Lo que marca este campo es "esto es contabilidad interna del
     * canal", no "esto no lo tecleó nadie".
     */
    #[ORM\Column(name: 'es_automatico', type: 'boolean', options: ['default' => false])]
    #[Groups(['pms_cargo:read'])]
    private bool $esAutomatico = false;

    /** Moneda del importe (resolver contra maestro; default USD si no llega). */
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write'])]
    private ?MaestroMoneda $moneda = null;

    /**
     * Tipo de cambio venta USD→PEN del día de registro (snapshot histórico).
     *
     * Está en `pms_cargo:patch` a propósito, al contrario que `moneda`: un cargo guardado SIN
     * tipo de cambio aporta 0 al saldo (§12.2), y si no se pudiera parchear habría que borrarlo
     * y rehacerlo para arreglarlo. El listener sólo permite rellenarlo cuando está vacío;
     * cambiar uno ya puesto sigue bloqueado (§12.4).
     */
    #[ORM\Column(name: 'tipo_cambio', type: 'decimal', precision: 10, scale: 3, nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?string $tipoCambio = null;

    /**
     * Descripción INTERNA. La rellena Beds24 al importar la factura, así que trae lo que
     * venga del canal: códigos, nombres de tarifa, texto sin normalizar. No se le enseña
     * al huésped por eso mismo — para eso está `$descripcionCliente`.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?string $descripcion = null;

    /**
     * Descripción que SÍ ve el huésped, traducible (`I18nContent[]`).
     *
     * Existe porque `$descripcion` viene de Beds24 y no es presentable. Sin esto, un cargo
     * de tipo «Otros» llegaba al huésped como una cifra suelta sin explicación —un −0.20 de
     * ajuste de cuadre que nadie sabe interpretar—.
     *
     * Es un campo OPCIONAL y así debe quedarse: la inmensa mayoría de los cargos se explican
     * solos con su tipo (Alojamiento, Extras) y obligar a redactar cada uno sería trabajo
     * inútil. Solo se rellena cuando el importe necesita una explicación.
     *
     * Se traduce sola vía {@see \App\Attribute\AutoTranslate}: se escribe en español y el
     * resto de idiomas los rellena el traductor automático, igual que
     * `CotizacionCottarifa::$proveedorTituloSnapshot`.
     *
     * Nullable en base de datos —no `NOT NULL DEFAULT '[]'`— para que añadir la columna a una
     * tabla con miles de cargos no exija reescribirlos todos.
     */
    #[ORM\Column(name: 'descripcion_cliente', type: 'json', nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private ?array $descripcionCliente = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $estado = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $cantidad = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?string $monto = null;

    #[ORM\Column(name: 'total_linea', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    private ?string $totalLinea = null;

    #[ORM\Column(name: 'tasa_iva', type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?string $tasaIva = null;

    /** createdBy de Beds24 (id del usuario/canal que lo generó). */
    #[ORM\Column(name: 'creado_por_beds24', type: 'string', length: 50, nullable: true)]
    private ?string $creadoPorBeds24 = null;

    /** createTime de Beds24. */
    #[ORM\Column(name: 'fecha_creacion_beds24', type: 'datetime', nullable: true)]
    #[Groups(['pms_cargo:read'])]
    private ?DateTimeInterface $fechaCreacionBeds24 = null;

    /** invoiceDate de Beds24. Sólo lo trae el endpoint on-demand. */
    #[ORM\Column(name: 'fecha_factura', type: 'date', nullable: true)]
    private ?DateTimeInterface $fechaFactura = null;

    /** @param string|null $beds24ItemId NULL para un cargo manual (ver el campo). */
    public function __construct(?string $beds24ItemId = null)
    {
        $this->id = Uuid::v7();
        $this->beds24ItemId = $beds24ItemId;
    }

    /**
     * Invariantes de los cargos MANUALES (los de Beds24 llegan con todo resuelto):
     *
     * - `tipo = 'charge'`: el rollup de la cabecera sólo suma los `charge`
     *   (ver PmsInformacionFinancieraRecalculoService). Sin esto un cargo manual
     *   se guardaría pero no afectaría al saldo, que es justo lo que se espera de él.
     * - `fechaCreacionBeds24 = ahora`: la colección se ordena por esa fecha; sin
     *   valor los cargos manuales se apilarían al principio y sin fecha en la UI.
     */
    #[ORM\PrePersist]
    public function aplicarDefectosDeCargoManual(): void
    {
        if (!$this->isManual()) {
            return;
        }

        $this->tipo ??= self::TIPO_CARGO;
        $this->fechaCreacionBeds24 ??= new DateTimeImmutable();
    }

    /** ¿Lo creó un operador a mano (true) o llegó sincronizado desde Beds24 (false)? */
    #[Groups(['pms_cargo:read'])]
    public function isManual(): bool
    {
        return $this->beds24ItemId === null;
    }

    // =========================================================================
    // GETTERS Y SETTERS
    // =========================================================================

    #[Groups(['pms_cargo:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getInformacionFinanciera(): ?PmsInformacionFinanciera { return $this->informacionFinanciera; }
    public function setInformacionFinanciera(?PmsInformacionFinanciera $info): self { $this->informacionFinanciera = $info; return $this; }

    public function getEvento(): ?PmsEventoCalendario { return $this->evento; }
    public function setEvento(?PmsEventoCalendario $evento): self { $this->evento = $evento; return $this; }

    public function getBeds24ItemId(): ?string { return $this->beds24ItemId; }
    public function setBeds24ItemId(?string $id): self { $this->beds24ItemId = $id; return $this; }

    public function getBeds24BookingId(): ?string { return $this->beds24BookingId; }
    public function setBeds24BookingId(?string $id): self { $this->beds24BookingId = $id; return $this; }

    public function getBeds24InvoiceId(): ?string { return $this->beds24InvoiceId; }
    public function setBeds24InvoiceId(?string $id): self { $this->beds24InvoiceId = $id; return $this; }

    public function getBeds24InvoiceeId(): ?string { return $this->beds24InvoiceeId; }
    public function setBeds24InvoiceeId(?string $id): self { $this->beds24InvoiceeId = $id; return $this; }

    public function getTipo(): ?string { return $this->tipo; }
    public function setTipo(?string $tipo): self { $this->tipo = $tipo; return $this; }

    public function getSubTipo(): ?int { return $this->subTipo; }
    public function setSubTipo(?int $subTipo): self { $this->subTipo = $subTipo; return $this; }

    public function getTipoCargo(): ?PmsTipoCargo { return $this->tipoCargo; }
    public function setTipoCargo(?PmsTipoCargo $tipoCargo): self { $this->tipoCargo = $tipoCargo; return $this; }

    public function isEsAutomatico(): bool { return $this->esAutomatico; }
    public function setEsAutomatico(bool $esAutomatico): self { $this->esAutomatico = $esAutomatico; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    public function getTipoCambio(): ?string { return $this->tipoCambio; }
    public function setTipoCambio(?string $tipoCambio): self { $this->tipoCambio = $tipoCambio; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): self { $this->descripcion = $descripcion; return $this; }

    /** @return list<array{content: string, language: string}> Vacío si nadie la redactó. */
    public function getDescripcionCliente(): array { return $this->descripcionCliente ?? []; }

    /** @param array<int, array{content: string, language: string}>|null $descripcionCliente */
    public function setDescripcionCliente(?array $descripcionCliente): self { $this->descripcionCliente = $descripcionCliente; return $this; }

    /**
     * La descripción en ESPAÑOL, para editarla desde el panel con un campo de texto normal.
     *
     * El panel no tiene editor multiidioma —los campos `I18nContent[]` del proyecto se
     * escriben desde las apps Vue—, y montar uno para un campo opcional sería
     * desproporcionado. Se escribe el español, que es el `sourceLanguage` del
     * {@see \App\Attribute\AutoTranslate}, y el resto de idiomas los rellena el traductor.
     *
     * Vaciar el campo borra la descripción entera, traducciones incluidas: si el operador
     * quita el texto en español, las traducciones de ese texto ya no significan nada.
     */
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    public function getDescripcionClienteEs(): ?string
    {
        return MaestroIdioma::textoEn($this->getDescripcionCliente());
    }

    #[Groups(['pms_cargo:write', 'pms_cargo:patch'])]
    public function setDescripcionClienteEs(?string $texto): self
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            $this->descripcionCliente = null;

            return $this;
        }

        // Se conservan las traducciones de los demás idiomas y solo se reemplaza el español.
        //
        // ⚠️ Corregir el español NO las regenera solo: el traductor trabaja en «modo seguro»
        // (rellena los idiomas vacíos, respeta los que ya tienen texto). Para rehacerlas hay
        // que pedirlo con `sobreescribirTraduccion` —el botón del panel financiero de la SPA—,
        // igual que en el editor de cotizaciones.
        $otros = array_values(array_filter(
            $this->getDescripcionCliente(),
            static fn (array $c): bool => ($c['language'] ?? null) !== 'es'
        ));

        $this->descripcionCliente = array_merge([['content' => $texto, 'language' => 'es']], $otros);

        return $this;
    }

    /**
     * El flag de sobrescritura del trait, expuesto a la API.
     *
     * Se redeclara aquí —en vez de anotar el trait— porque los grupos de serialización son
     * de cada entidad: el trait lo comparten media docena y cada una tiene los suyos. Mismo
     * patrón que `CotizacionCottarifa::getSobreescribirTraduccion()`.
     *
     * Va también en `pms_cargo:read` para que la SPA pueda pintar el botón en su estado real;
     * el servicio lo apaga solo tras traducir, así que al recargar vuelve a false.
     */
    #[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]
    public function getSobreescribirTraduccion(): bool
    {
        return $this->sobreescribirTraduccion;
    }

    #[Groups(['pms_cargo:write', 'pms_cargo:patch'])]
    public function setSobreescribirTraduccion(bool $sobreescribirTraduccion): self
    {
        $this->sobreescribirTraduccion = $sobreescribirTraduccion;

        return $this;
    }

    /**
     * El texto para el huésped en el idioma pedido, o `null` si no hay descripción.
     *
     * El fallback es el mismo que usa el front (`maestroStore.traducir()`): idioma pedido →
     * inglés → español → lo primero que haya. Vive aquí para que el estado de cuenta del
     * agente y el resumen del panel no reimplementen cada uno su versión.
     */
    public function descripcionClienteEn(string $idioma): ?string
    {
        $contenidos = $this->getDescripcionCliente();

        foreach ([$idioma, 'en', 'es'] as $preferido) {
            $texto = MaestroIdioma::textoEn($contenidos, $preferido);

            if (null !== $texto) {
                return $texto;
            }
        }

        $primero = trim((string) ($contenidos[0]['content'] ?? ''));

        return $primero === '' ? null : $primero;
    }

    public function getEstado(): ?string { return $this->estado; }
    public function setEstado(?string $estado): self { $this->estado = $estado; return $this; }

    public function getCantidad(): ?string { return $this->cantidad; }
    public function setCantidad(?string $cantidad): self { $this->cantidad = $cantidad; return $this; }

    public function getMonto(): ?string { return $this->monto; }
    public function setMonto(?string $monto): self { $this->monto = $monto; return $this; }

    public function getTotalLinea(): ?string { return $this->totalLinea; }
    public function setTotalLinea(?string $totalLinea): self { $this->totalLinea = $totalLinea; return $this; }

    public function getTasaIva(): ?string { return $this->tasaIva; }
    public function setTasaIva(?string $tasaIva): self { $this->tasaIva = $tasaIva; return $this; }

    public function getCreadoPorBeds24(): ?string { return $this->creadoPorBeds24; }
    public function setCreadoPorBeds24(?string $creadoPor): self { $this->creadoPorBeds24 = $creadoPor; return $this; }

    public function getFechaCreacionBeds24(): ?DateTimeInterface { return $this->fechaCreacionBeds24; }
    public function setFechaCreacionBeds24(?DateTimeInterface $fecha): self { $this->fechaCreacionBeds24 = $fecha; return $this; }

    public function getFechaFactura(): ?DateTimeInterface { return $this->fechaFactura; }
    public function setFechaFactura(?DateTimeInterface $fecha): self { $this->fechaFactura = $fecha; return $this; }

    /** ¿Es un cargo (charge) frente a un pago (payment)? */
    public function esCargo(): bool
    {
        return $this->tipo === self::TIPO_CARGO;
    }
}
