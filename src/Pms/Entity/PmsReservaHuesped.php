<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Maestro\MaestroPais;
use App\Entity\Maestro\MaestroDocumentoTipo;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Trait\MediaTrait;
use App\Pms\Repository\PmsReservaHuespedRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Entidad PmsReservaHuesped.
 * Gestiona la información de huéspedes, documentos y firmas.
 * IDs: UUID (Negocio), String 2 (País/Documento).
 */
#[ORM\Entity(repositoryClass: PmsReservaHuespedRepository::class)]
#[ORM\Table(name: 'pms_reserva_huesped')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class PmsReservaHuesped
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    /** Trait para tokens de seguridad en archivos y helpers de medios */
    use MediaTrait;

    /* ======================================================
     * RELACIONES DE NEGOCIO (UUID - BINARY 16)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: PmsReserva::class, inversedBy: 'huespedes')]
    #[ORM\JoinColumn(
        name: 'reserva_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?PmsReserva $reserva = null;

    /* ======================================================
     * DATOS PERSONALES
     * ====================================================== */

    #[ORM\Column(length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(length: 100)]
    private ?string $apellido = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $fechaNacimiento = null;

    /**
     * País: ID Natural (String 2 - ISO)
     * SE ELIMINA BINARY(16)
     */
    #[ORM\ManyToOne(targetEntity: MaestroPais::class, inversedBy: 'huespedes')]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroPais $pais = null;

    /**
     * Tipo de Documento: ID Natural (String 2 - SUNAT)
     * SE ELIMINA BINARY(16)
     */
    #[ORM\ManyToOne(targetEntity: MaestroDocumentoTipo::class)]
    #[ORM\JoinColumn(name: 'tipo_documento_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroDocumentoTipo $tipoDocumento = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $documentoNumero = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $esPrincipal = false;

    /* ======================================================
     * GESTIÓN DE ARCHIVOS (VICH UPLOADER)
     * ====================================================== */

    #[Vich\UploadableField(mapping: 'huesped_docs', fileNameProperty: 'documentoName')]
    private ?File $documentoFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $documentoName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $tamNumero = null;

    #[Vich\UploadableField(mapping: 'huesped_docs', fileNameProperty: 'tamName')]
    private ?File $tamFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tamName = null;

    #[Vich\UploadableField(mapping: 'huesped_firmas', fileNameProperty: 'firmaName')]
    private ?File $firmaFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firmaName = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $firmadoEn = null;

    /** Trigger técnico para VichUploader */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $fileUpdatedAt = null;

    /* ======================================================
     * PROPIEDADES VIRTUALES / URLS
     * ====================================================== */
    private ?string $documentoUrl = null;
    private ?string $tamUrl = null;
    private ?string $firmaUrl = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->initializeToken(); // Método del MediaTrait
    }

    /* ======================================================
     * LÓGICA DE NEGOCIO
     * ====================================================== */

    public function getEdadAlCheckOut(): ?int
    {
        if ($this->fechaNacimiento === null || $this->reserva?->getFechaSalida() === null) {
            return null;
        }
        return $this->fechaNacimiento->diff($this->reserva->getFechaSalida())->y;
    }

    public function esMenorDeEdadAlCheckOut(): bool
    {
        $edad = $this->getEdadAlCheckOut();
        return $edad !== null && $edad < 18;
    }

    public function __toString(): string
    {
        return trim(($this->nombre ?? '') . ' ' . ($this->apellido ?? '')) ?: 'Nuevo Huésped';
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getReserva(): ?PmsReserva { return $this->reserva; }
    public function setReserva(?PmsReserva $reserva): self { $this->reserva = $reserva; return $this; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getApellido(): ?string { return $this->apellido; }
    public function setApellido(?string $apellido): self { $this->apellido = $apellido; return $this; }

    public function getFechaNacimiento(): ?DateTimeInterface { return $this->fechaNacimiento; }
    public function setFechaNacimiento(?DateTimeInterface $fechaNacimiento): self { $this->fechaNacimiento = $fechaNacimiento; return $this; }

    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $pais): self { $this->pais = $pais; return $this; }

    public function getTipoDocumento(): ?MaestroDocumentoTipo { return $this->tipoDocumento; }
    public function setTipoDocumento(?MaestroDocumentoTipo $tipoDocumento): self { $this->tipoDocumento = $tipoDocumento; return $this; }

    public function getDocumentoNumero(): ?string { return $this->documentoNumero; }
    public function setDocumentoNumero(?string $documentoNumero): self { $this->documentoNumero = $documentoNumero; return $this; }

    public function isEsPrincipal(): bool { return $this->esPrincipal; }
    public function setEsPrincipal(bool $esPrincipal): self { $this->esPrincipal = $esPrincipal; return $this; }

    public function getTamNumero(): ?string { return $this->tamNumero; }
    public function setTamNumero(?string $tamNumero): self { $this->tamNumero = $tamNumero; return $this; }

    public function setDocumentoFile(?File $documentoFile = null): void
    {
        $this->documentoFile = $documentoFile;
        if (null !== $documentoFile) $this->fileUpdatedAt = new DateTimeImmutable();
    }
    public function getDocumentoFile(): ?File { return $this->documentoFile; }
    public function setDocumentoName(?string $documentoName): self { $this->documentoName = $documentoName; return $this; }
    public function getDocumentoName(): ?string { return $this->documentoName; }

    public function setTamFile(?File $tamFile = null): void
    {
        $this->tamFile = $tamFile;
        if (null !== $tamFile) $this->fileUpdatedAt = new DateTimeImmutable();
    }
    public function getTamFile(): ?File { return $this->tamFile; }
    public function setTamName(?string $tamName): self { $this->tamName = $tamName; return $this; }
    public function getTamName(): ?string { return $this->tamName; }

    public function setFirmaFile(?File $firmaFile = null): void
    {
        $this->firmaFile = $firmaFile;
        if (null !== $firmaFile) {
            $this->fileUpdatedAt = new DateTimeImmutable();
            if ($this->firmadoEn === null) $this->firmadoEn = new DateTimeImmutable();
        }
    }
    public function getFirmaFile(): ?File { return $this->firmaFile; }
    public function setFirmaName(?string $firmaName): self { $this->firmaName = $firmaName; return $this; }
    public function getFirmaName(): ?string { return $this->firmaName; }

    public function getFirmadoEn(): ?DateTimeInterface { return $this->firmadoEn; }
    public function setFirmadoEn(?DateTimeInterface $firmadoEn): self { $this->firmadoEn = $firmadoEn; return $this; }

    public function getFileUpdatedAt(): ?DateTimeImmutable { return $this->fileUpdatedAt; }
    public function setFileUpdatedAt(?DateTimeImmutable $date): self { $this->fileUpdatedAt = $date; return $this; }

    public function getDocumentoUrl(): ?string { return $this->documentoUrl; }
    public function setDocumentoUrl(?string $url): self { $this->documentoUrl = $url; return $this; }

    public function getTamUrl(): ?string { return $this->tamUrl; }
    public function setTamUrl(?string $url): self { $this->tamUrl = $url; return $this; }

    public function getFirmaUrl(): ?string { return $this->firmaUrl; }
    public function setFirmaUrl(?string $url): self { $this->firmaUrl = $url; return $this; }
}