<?php

declare(strict_types=1);

namespace App\Front\Comun\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Front\Comun\Enum\LibroReclamacionBienEnum;
use App\Front\Comun\Enum\LibroReclamacionTipoEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Una hoja del Libro de Reclamaciones virtual (D.S. 011-2011-PCM y modificatorias).
 *
 * Lo exige INDECOPI a todo el que vende por internet, y por eso vive en la web pública y no en
 * el panel. La estructura es la del formato oficial: consumidor, bien contratado, detalle y
 * pedido; la respuesta del proveedor se escribe después desde el panel.
 *
 * ⚠️ **No se borra.** Una hoja es un registro legal: si se atendió, se marca `atendida` con su
 * respuesta. El plazo legal de respuesta es de 15 días hábiles.
 *
 * Ver docs/WebPublica.md §5.
 */
#[ORM\Entity]
#[ORM\Table(name: 'front_libro_reclamacion')]
#[ORM\UniqueConstraint(name: 'uniq_front_libro_reclamacion_correlativo', columns: ['correlativo'])]
#[ORM\Index(name: 'idx_front_libro_reclamacion_fecha', columns: ['fecha'])]
#[ORM\HasLifecycleCallbacks]
class LibroReclamacion
{
    use IdTrait;
    use TimestampTrait;

    /** «2026-00001»: año y contador que reinicia cada año. Lo asigna `LibroReclamacionesService`. */
    #[ORM\Column(type: 'string', length: 20)]
    private string $correlativo = '';

    /** Hora de pared de Lima, la que figura en la hoja y en la copia del consumidor. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $fecha;

    /** Dominio desde el que se presentó: openperu.pe, centrocuscointi.com… */
    #[ORM\Column(type: 'string', length: 100)]
    private string $sitio = '';

    // ── 1. Consumidor ───────────────────────────────────────────────────────

    #[Assert\NotBlank(message: 'libro.error.obligatorio')]
    #[Assert\Length(max: 160)]
    #[ORM\Column(type: 'string', length: 160)]
    private string $consumidorNombre = '';

    #[Assert\NotBlank(message: 'libro.error.obligatorio')]
    #[Assert\Choice(choices: ['DNI', 'CE', 'PASAPORTE', 'RUC'])]
    #[ORM\Column(type: 'string', length: 12)]
    private string $consumidorDocumentoTipo = 'DNI';

    #[Assert\NotBlank(message: 'libro.error.obligatorio')]
    #[Assert\Length(max: 20)]
    #[ORM\Column(type: 'string', length: 20)]
    private string $consumidorDocumentoNumero = '';

    #[Assert\NotBlank(message: 'libro.error.obligatorio')]
    #[Assert\Length(max: 240)]
    #[ORM\Column(type: 'string', length: 240)]
    private string $consumidorDomicilio = '';

    #[Assert\Length(max: 40)]
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $consumidorTelefono = null;

    #[Assert\NotBlank(message: 'libro.error.obligatorio')]
    #[Assert\Email(message: 'libro.error.email')]
    #[Assert\Length(max: 160)]
    #[ORM\Column(type: 'string', length: 160)]
    private string $consumidorEmail = '';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $consumidorEsMenor = false;

    /** Padre, madre o apoderado: obligatorio si el consumidor es menor de edad. */
    #[Assert\Length(max: 160)]
    #[ORM\Column(type: 'string', length: 160, nullable: true)]
    private ?string $apoderadoNombre = null;

    // ── 2. Bien contratado ──────────────────────────────────────────────────

    #[ORM\Column(type: 'string', length: 12, enumType: LibroReclamacionBienEnum::class)]
    private LibroReclamacionBienEnum $bienTipo = LibroReclamacionBienEnum::SERVICIO;

    #[Assert\NotBlank(message: 'libro.error.obligatorio')]
    #[Assert\Length(max: 2000)]
    #[ORM\Column(type: 'text')]
    private string $bienDescripcion = '';

    /** Monto reclamado, si lo hay. En texto decimal, como todo el dinero del proyecto. */
    #[Assert\PositiveOrZero]
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $bienMonto = null;

    // ── 3. Detalle ──────────────────────────────────────────────────────────

    #[ORM\Column(type: 'string', length: 12, enumType: LibroReclamacionTipoEnum::class)]
    private LibroReclamacionTipoEnum $tipo = LibroReclamacionTipoEnum::RECLAMO;

    #[Assert\NotBlank(message: 'libro.error.obligatorio')]
    #[Assert\Length(max: 4000)]
    #[ORM\Column(type: 'text')]
    private string $detalle = '';

    #[Assert\NotBlank(message: 'libro.error.obligatorio')]
    #[Assert\Length(max: 2000)]
    #[ORM\Column(type: 'text')]
    private string $pedido = '';

    // ── 4. Respuesta del proveedor (panel) ──────────────────────────────────

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $respuesta = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fechaRespuesta = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $atendida = false;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ip = null;

    public function __construct()
    {
        $this->initializeId();
        $this->fecha = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->correlativo !== '' ? $this->correlativo : 'Hoja sin registrar';
    }

    #[Assert\Callback]
    public function validarApoderado(ExecutionContextInterface $context): void
    {
        if ($this->consumidorEsMenor && trim((string) $this->apoderadoNombre) === '') {
            $context->buildViolation('libro.error.apoderado')
                ->atPath('apoderadoNombre')
                ->addViolation();
        }
    }

    /**
     * Fecha límite de respuesta: 15 días hábiles (lunes a viernes) desde la presentación.
     * No descuenta feriados: avisa antes, nunca después.
     */
    public function getVenceRespuesta(): \DateTimeImmutable
    {
        $dia = $this->fecha->setTime(0, 0);
        $habiles = 0;
        while ($habiles < 15) {
            $dia = $dia->modify('+1 day');
            if ((int) $dia->format('N') <= 5) {
                $habiles++;
            }
        }
        return $dia;
    }

    public function getCorrelativo(): string { return $this->correlativo; }
    public function setCorrelativo(string $correlativo): self { $this->correlativo = $correlativo; return $this; }

    public function getFecha(): \DateTimeImmutable { return $this->fecha; }
    public function setFecha(\DateTimeImmutable $fecha): self { $this->fecha = $fecha; return $this; }

    public function getSitio(): string { return $this->sitio; }
    public function setSitio(string $sitio): self { $this->sitio = $sitio; return $this; }

    public function getConsumidorNombre(): string { return $this->consumidorNombre; }
    public function setConsumidorNombre(?string $v): self { $this->consumidorNombre = trim((string) $v); return $this; }

    public function getConsumidorDocumentoTipo(): string { return $this->consumidorDocumentoTipo; }
    public function setConsumidorDocumentoTipo(?string $v): self { $this->consumidorDocumentoTipo = trim((string) $v); return $this; }

    public function getConsumidorDocumentoNumero(): string { return $this->consumidorDocumentoNumero; }
    public function setConsumidorDocumentoNumero(?string $v): self { $this->consumidorDocumentoNumero = trim((string) $v); return $this; }

    public function getConsumidorDomicilio(): string { return $this->consumidorDomicilio; }
    public function setConsumidorDomicilio(?string $v): self { $this->consumidorDomicilio = trim((string) $v); return $this; }

    public function getConsumidorTelefono(): ?string { return $this->consumidorTelefono; }
    public function setConsumidorTelefono(?string $v): self { $v = trim((string) $v); $this->consumidorTelefono = $v === '' ? null : $v; return $this; }

    public function getConsumidorEmail(): string { return $this->consumidorEmail; }
    public function setConsumidorEmail(?string $v): self { $this->consumidorEmail = trim((string) $v); return $this; }

    public function isConsumidorEsMenor(): bool { return $this->consumidorEsMenor; }
    public function setConsumidorEsMenor(bool $v): self { $this->consumidorEsMenor = $v; return $this; }

    public function getApoderadoNombre(): ?string { return $this->apoderadoNombre; }
    public function setApoderadoNombre(?string $v): self { $v = trim((string) $v); $this->apoderadoNombre = $v === '' ? null : $v; return $this; }

    public function getBienTipo(): LibroReclamacionBienEnum { return $this->bienTipo; }
    public function setBienTipo(LibroReclamacionBienEnum $v): self { $this->bienTipo = $v; return $this; }

    public function getBienDescripcion(): string { return $this->bienDescripcion; }
    public function setBienDescripcion(?string $v): self { $this->bienDescripcion = trim((string) $v); return $this; }

    public function getBienMonto(): ?string { return $this->bienMonto; }
    public function setBienMonto(?string $v): self { $v = trim((string) $v); $this->bienMonto = $v === '' ? null : str_replace(',', '.', $v); return $this; }

    public function getTipo(): LibroReclamacionTipoEnum { return $this->tipo; }
    public function setTipo(LibroReclamacionTipoEnum $v): self { $this->tipo = $v; return $this; }

    public function getDetalle(): string { return $this->detalle; }
    public function setDetalle(?string $v): self { $this->detalle = trim((string) $v); return $this; }

    public function getPedido(): string { return $this->pedido; }
    public function setPedido(?string $v): self { $this->pedido = trim((string) $v); return $this; }

    public function getRespuesta(): ?string { return $this->respuesta; }
    public function setRespuesta(?string $v): self
    {
        $v = trim((string) $v);
        $this->respuesta = $v === '' ? null : $v;
        // La fecha de respuesta la pone la primera respuesta, no el operador: es la que cuenta
        // para el plazo legal y no debe poder retocarse a mano.
        if ($this->respuesta !== null && $this->fechaRespuesta === null) {
            $this->fechaRespuesta = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getFechaRespuesta(): ?\DateTimeImmutable { return $this->fechaRespuesta; }

    public function isAtendida(): bool { return $this->atendida; }
    public function setAtendida(bool $v): self { $this->atendida = $v; return $this; }

    public function getIp(): ?string { return $this->ip; }
    public function setIp(?string $v): self { $this->ip = $v; return $this; }
}
