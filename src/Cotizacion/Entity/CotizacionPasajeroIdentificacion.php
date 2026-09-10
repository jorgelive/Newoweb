<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Cotizacion\Enum\ValidacionIdentificacionEnum;
use App\Entity\Maestro\MaestroPais;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Enum\DocumentoTipoEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un documento que identifica a un pasajero: su DNI, su pasaporte, su carné.
 *
 * ## Por qué es una fila y no dos columnas
 *
 * `CotizacionFilepasajero` tenía **un** `tipodocumento` y **un** `numerodocumento`, sin
 * vencimiento. Un padrón real de grupo desmiente las dos cosas: en el de Punta Cana 2026, 130 de
 * 133 personas llevan **DNI y pasaporte a la vez**, con vencimientos distintos —el DNI de alguien
 * caduca en 2026 y su pasaporte en 2031—, y **100 de los 133 son menores**, así que además
 * necesitan autorización notarial para salir del país.
 *
 * Con columnas, eso son ocho campos y la mitad nulos para los adultos; y el día del carné de
 * extranjería o la visa, una migración.
 *
 * ## Qué NO es
 *
 * ⚠️ No confundir con {@see CotizacionFilearchivo}, que son **adjuntos** del expediente —boletos,
 * facturas, confirmaciones— y se llamaba `CotizacionFiledocumento` precisamente hasta que ese
 * nombre hizo que alguien le metiera dentro un `vencimiento` «para alertar de pasaportes
 * vencidos». Ver `docs/Cotizaciones.md` §6.k.
 *
 * Aquí no hay archivo: **es un dato**. Se consulta por vencimiento, no se descarga.
 *
 * El **escaneo** sí cabe en `CotizacionFilearchivo` —que es para archivos, cualquiera— colgando
 * del pasajero. Las dos cosas conviven sin pisarse porque tienen vidas distintas: el número se
 * guarda mientras el expediente exista y la foto se borra al mes del retorno del grupo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_pasajero_identificacion')]
#[ORM\UniqueConstraint(name: 'uniq_pasajero_identificacion_tipo', columns: ['pasajero_id', 'tipo'])]
#[ORM\HasLifecycleCallbacks]
class CotizacionPasajeroIdentificacion
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionFilepasajero::class, inversedBy: 'identificaciones')]
    #[ORM\JoinColumn(name: 'pasajero_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFilepasajero $pasajero = null;

    /**
     * ⚠️ Único por `(pasajero, tipo)`: nadie tiene dos pasaportes vigentes en el mismo expediente,
     * y esa restricción es la que hace que **reimportar el padrón corregido no duplique nada**.
     * Ese Excel se vuelve a subir varias veces antes de un viaje.
     */
    #[Assert\NotNull(message: 'Indica qué documento es.')]
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 20, enumType: DocumentoTipoEnum::class)]
    private ?DocumentoTipoEnum $tipo = null;

    #[Assert\NotBlank(message: 'El número del documento no puede quedar vacío.')]
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $numero = null;

    /**
     * Nulo significa «no lo sabemos», no «no caduca».
     *
     * ⚠️ La diferencia importa: en el padrón real había **22 personas sin esta fecha**, y sin ella
     * no se puede comprobar nada. Un listado que las cuente como vigentes miente; tienen que salir
     * como «sin comprobar», que es lo que son.
     */
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $vencimiento = null;

    /** Quién lo emitió. Nulo se lee como «el país del pasajero». */
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    #[ORM\JoinColumn(name: 'pais_emisor_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroPais $paisEmisor = null;

    public function __construct()
    {
        $this->initializeId();
    }

    public function __toString(): string
    {
        return sprintf('%s %s', $this->tipo->value ?? 'DOC', $this->numero ?? '—');
    }

    /**
     * El veredicto del control sobre ESTE número. Ver {@see ValidacionIdentificacionEnum}.
     *
     * 🔑 **Aquí y no en el archivo, y ése es el giro que ordena todo el proceso.** El documento
     * escaneado no es lo que se pone en duda —es el documento oficial de una persona—: lo que se
     * valida es **lo que alguien tecleó en el manifiesto**. Así el sello se ve donde se mira el
     * dato, al lado del número, y no en una lista de ficheros aparte.
     */
    #[Groups(['file:item:read'])]
    #[ORM\Column(name: 'estado_validacion', type: 'string', length: 20, enumType: ValidacionIdentificacionEnum::class, options: ['default' => 'no_validado'])]
    private ValidacionIdentificacionEnum $estadoValidacion = ValidacionIdentificacionEnum::NO_VALIDADO;

    /**
     * En qué campos no coincide, **estructurado**: `[{campo, documento, manifiesto}]`.
     *
     * ⚠️ **JSON y no texto, porque la pantalla lo pinta AL LADO de cada campo.** Una frase suelta
     * obligaría al front a adivinar de qué campo habla para saber dónde ponerla. Y guarda **los
     * dos valores**: en el caso más frecuente aquí —un dedazo en el año, `2026` por `2036`—,
     * verlos uno junto al otro *es* la resolución, sin abrir el escaneo.
     *
     * @var list<array{campo: string, documento: string, manifiesto: string}>
     */
    #[Groups(['file:item:read'])]
    #[ORM\Column(name: 'discrepancias', type: 'json')]
    private array $discrepancias = [];

    /**
     * Lo que no es de ningún campo: vencido, banda ilegible, sin nada contra qué cotejar.
     *
     * En prosa a propósito: es para leerlo, no para ramificar. Un catálogo de códigos aquí
     * envejecería mal —cada documento raro añade el suyo— y arrastraría traducciones para nada.
     *
     * @var list<string>
     */
    #[Groups(['file:item:read'])]
    #[ORM\Column(name: 'notas_validacion', type: 'json')]
    private array $notasValidacion = [];

    #[Groups(['file:item:read'])]
    #[ORM\Column(name: 'validado_en', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $validadoEn = null;

    /**
     * Con qué escaneo se validó.
     *
     * ⚠️ `SET NULL` al borrarlo, no `CASCADE`: si alguien borra el archivo, **el veredicto se
     * queda** —se emitió y es cierto que se emitió— pero pierde su respaldo, y eso tiene que
     * poder verse. Un `CASCADE` borraría la validación en silencio y el número volvería a
     * aparecer como validado sin nada detrás.
     */
    #[ORM\ManyToOne(targetEntity: CotizacionFilearchivo::class)]
    #[ORM\JoinColumn(name: 'validado_con_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?CotizacionFilearchivo $validadoCon = null;

    /**
     * ¿Se creó COPIANDO un escaneo, en vez de haberla tecleado alguien?
     *
     * 🔥 **Sin esto, una ficha creada desde el documento se valida contra sí misma.** El panel de
     * sueltos escribe número y nombre desde la lectura; en la siguiente tanda, `Cotejo` ve un
     * manifiesto «con datos» que coincide al 100 % —porque salieron de ahí— y la sella
     * `VALIDADO_OCR`. Un sello verde puesto por el propio dato que había que comprobar.
     *
     * La guarda de `Cotejo` («sin nada guardado no se valida solo») protege sólo la ruta en
     * memoria: en cuanto la ficha persiste, deja de existir. Esta bandera la sobrevive.
     *
     * ⚠️ Se apaga en cuanto una persona toca el dato: a partir de ahí ya no es una copia del
     * escaneo, es lo que alguien afirma.
     */
    #[ORM\Column(name: 'copiada_del_escaneo', type: 'boolean', options: ['default' => false])]
    private bool $copiadaDelEscaneo = false;

    public function isCopiadaDelEscaneo(): bool { return $this->copiadaDelEscaneo; }

    public function marcarCopiadaDelEscaneo(): self
    {
        $this->copiadaDelEscaneo = true;

        return $this;
    }

    public function getEstadoValidacion(): ValidacionIdentificacionEnum { return $this->estadoValidacion; }

    /** @return list<array{campo: string, documento: string, manifiesto: string}> */
    public function getDiscrepancias(): array { return $this->discrepancias; }

    /** @return list<string> */
    public function getNotasValidacion(): array { return $this->notasValidacion; }

    public function getValidadoEn(): ?DateTimeImmutable { return $this->validadoEn; }

    public function getValidadoCon(): ?CotizacionFilearchivo { return $this->validadoCon; }

    /**
     * ¿Se puede saltar en la siguiente tanda? Es lo que la hace **idempotente**: lo resuelto no se
     * vuelve a leer, así que una segunda pasada sólo cuesta lo que falta.
     */
    public function estaResuelta(): bool
    {
        // 🔥 **Un veredicto sin su escaneo NO cuenta como resuelto.** `validado_con_id` es
        // `SET NULL` al borrar el archivo —el veredicto se emitió y eso es cierto— pero entonces
        // se queda apoyado en nada: el pasajero re-sube su documento desde `pax`, el viejo se
        // borra, y el número sigue en verde mientras **el escaneo nuevo no se lee jamás**, porque
        // la tanda salta lo resuelto. Exigir el respaldo lo devuelve a la cola solo.
        return $this->estadoValidacion->estaResuelto() && $this->validadoCon !== null;
    }

    /**
     * Devuelve el número a la cola: lo que había validado ya no vale.
     *
     * 🔥 **Se llama desde los setters de los campos que se cotejan.** Sin esto, corregir mal un
     * número ya validado lo dejaba **en verde para siempre**: la tanda salta lo resuelto, así que
     * nada volvía a mirarlo. Un sello que sobrevive al dato que sellaba es peor que no tenerlo.
     */
    private function invalidarVeredicto(): void
    {
        if ($this->estadoValidacion === ValidacionIdentificacionEnum::NO_VALIDADO) {
            return;
        }

        $this->estadoValidacion = ValidacionIdentificacionEnum::NO_VALIDADO;
        // Alguien tocó el dato: ya no es una copia del escaneo, es lo que esa persona afirma.
        $this->copiadaDelEscaneo = false;
        $this->discrepancias = [];
        $this->notasValidacion = ['el dato cambió después de validarse: hay que volver a cotejarlo'];
        $this->validadoEn = null;
        $this->validadoCon = null;
    }

    /**
     * El resultado entero, siempre junto: con setters sueltos, un día alguien pone el estado y
     * olvida las discrepancias, y queda un «observado» que no dice de qué.
     *
     * @param list<array{campo: string, documento: string, manifiesto: string}> $discrepancias
     * @param list<string> $notas
     */
    public function registrarValidacion(
        ValidacionIdentificacionEnum $estado,
        array $discrepancias,
        array $notas,
        ?CotizacionFilearchivo $con = null,
    ): self {
        $this->estadoValidacion = $estado;
        $this->discrepancias = $discrepancias;
        $this->notasValidacion = $notas;
        $this->validadoCon = $con;
        $this->validadoEn = new DateTimeImmutable();

        return $this;
    }

    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['file:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;

        return $this;
    }

    public function getPasajero(): ?CotizacionFilepasajero { return $this->pasajero; }
    public function setPasajero(?CotizacionFilepasajero $v): self { $this->pasajero = $v; return $this; }

    public function getTipo(): ?DocumentoTipoEnum { return $this->tipo; }
    public function setTipo(?DocumentoTipoEnum $v): self
    {
        if ($this->tipo !== $v) { $this->invalidarVeredicto(); }
        $this->tipo = $v;

        return $this;
    }

    public function getNumero(): ?string { return $this->numero; }

    public function setNumero(?string $v): self
    {
        // Sin espacios ni guiones sueltos: un número copiado de un Excel trae de todo, y dos
        // formas del mismo número son dos personas distintas para cualquier cruce posterior.
        $limpio = $v !== null ? (trim($v) ?: null) : null;

        if ($this->numero !== $limpio) { $this->invalidarVeredicto(); }
        $this->numero = $limpio;

        return $this;
    }

    public function getVencimiento(): ?DateTimeInterface { return $this->vencimiento; }
    public function setVencimiento(?DateTimeInterface $v): self
    {
        if ($this->vencimiento?->format('Y-m-d') !== $v?->format('Y-m-d')) { $this->invalidarVeredicto(); }
        $this->vencimiento = $v;

        return $this;
    }

    public function getPaisEmisor(): ?MaestroPais { return $this->paisEmisor; }
    public function setPaisEmisor(?MaestroPais $v): self { $this->paisEmisor = $v; return $this; }

    /**
     * ¿Sirve para viajar en esta fecha?
     *
     * `null` es **«no se sabe»** y no `true`: sin fecha cargada no se puede afirmar que esté
     * vigente, y contarlo como bueno es exactamente lo que dejó a once personas con el DNI
     * vencido dentro de un padrón que se daba por revisado.
     */
    public function estaVigenteEl(DateTimeInterface $fecha): ?bool
    {
        if ($this->vencimiento === null) {
            return null;
        }

        return $this->vencimiento->getTimestamp() >= $fecha->getTimestamp();
    }
}
