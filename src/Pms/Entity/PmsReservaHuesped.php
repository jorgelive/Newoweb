<?php

namespace App\Pms\Entity;

use App\Entity\MaestroPais;
use App\Entity\MaestroTipodocumento;
use App\Panel\Trait\MediaTrait;
use App\Pms\Repository\PmsReservaHuespedRepository;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Entidad que representa a un huésped asociado a una reserva en el PMS.
 * * Gestiona la información personal, legal y los archivos adjuntos (DNI, TAM, Firmas).
 * Utiliza MediaTrait para la gestión segura de archivos (tokens) y helpers visuales.
 * * @author Susan
 */
#[ORM\Entity(repositoryClass: PmsReservaHuespedRepository::class)]
#[ORM\Table(name: 'pms_reserva_huesped')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class PmsReservaHuesped
{
    /*
     * Trait global del Panel.
     * Proporciona:
     * - $token: para seguridad en nombres de archivo.
     * - initializeToken(): para generar el token al persistir.
     * - getIconPathFor(): para visualización de fallbacks (PDFs, etc).
     * - isImage(): helper para lógica de LiipImagine.
     */
    use MediaTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Reserva a la que pertenece este huésped.
     * La eliminación de una reserva implica la eliminación en cascada de sus huéspedes.
     */
    #[ORM\ManyToOne(targetEntity: PmsReserva::class, inversedBy: 'huespedes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PmsReserva $reserva = null;

    #[ORM\Column(length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(length: 100)]
    private ?string $apellido = null;

    /**
     * Fecha de nacimiento, crítica para determinar si es menor de edad
     * al momento del Check-Out.
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $fechaNacimiento = null;

    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    private ?MaestroPais $pais = null;

    #[ORM\ManyToOne(targetEntity: MaestroTipodocumento::class)]
    private ?MaestroTipodocumento $tipoDocumento = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $documentoNumero = null;

    /**
     * Indica si este huésped es el titular o responsable de la reserva.
     */
    #[ORM\Column(options: ['default' => false])]
    private ?bool $esPrincipal = false;

    // =========================================================================
    // ARCHIVOS VICH UPLOADER
    // Configurados en vich_uploader.yaml para usar App\Pms\Naming\HuespedNamer
    // =========================================================================

    /**
     * Archivo físico del Documento de Identidad (DNI/Pasaporte).
     * Se procesa automáticamente por VichCompressionListener (LiipImagine) al subir.
     */
    #[Vich\UploadableField(mapping: 'huesped_docs', fileNameProperty: 'documentoName')]
    private ?File $documentoFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $documentoName = null;

    /**
     * Número de la Tarjeta Andina de Migración (TAM).
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $tamNumero = null;

    /**
     * Archivo físico de la TAM.
     */
    #[Vich\UploadableField(mapping: 'huesped_docs', fileNameProperty: 'tamName')]
    private ?File $tamFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tamName = null;

    /**
     * Archivo físico de la Firma Digital.
     */
    #[Vich\UploadableField(mapping: 'huesped_firmas', fileNameProperty: 'firmaName')]
    private ?File $firmaFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firmaName = null;

    /**
     * Fecha exacta en la que se capturó la firma del huésped.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $firmadoEn = null;

    /**
     * Campo técnico requerido por VichUploader para detectar cambios en los archivos
     * y forzar la actualización en la base de datos.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    // =========================================================================
    // AUDITORÍA (Gedmo Timestampable)
    // =========================================================================

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?DateTimeInterface $modificado = null;

    // =========================================================================
    // EVENTOS DEL CICLO DE VIDA
    // =========================================================================

    /**
     * Se ejecuta antes de persistir la entidad por primera vez.
     * Inicializa el token de seguridad definido en MediaTrait.
     */
    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->initializeToken();
    }

    // =========================================================================
    // LÓGICA DE NEGOCIO
    // =========================================================================

    /**
     * Calcula la edad que tendrá el huésped al momento de salir (Check-Out).
     * * @return int|null Edad en años o null si faltan datos.
     */
    public function getEdadAlCheckOut(): ?int
    {
        if ($this->fechaNacimiento === null) {
            return null;
        }

        $fechaSalida = $this->reserva?->getFechaSalida();

        if ($fechaSalida === null) {
            return null;
        }

        return $this->fechaNacimiento->diff($fechaSalida)->y;
    }

    /**
     * Verifica si el huésped será menor de edad al momento del Check-Out.
     * Útil para validar si requiere firma de un apoderado.
     * * @return bool True si tiene menos de 18 años.
     */
    public function esMenorDeEdadAlCheckOut(): bool
    {
        $edad = $this->getEdadAlCheckOut();
        return $edad !== null && $edad < 18;
    }

    /**
     * Retorna una representación en string del objeto.
     * * @return string Nombre completo o identificador por defecto.
     */
    public function __toString(): string
    {
        return trim(($this->nombre ?? '') . ' ' . ($this->apellido ?? '')) ?: 'Nuevo Huésped';
    }

    // =========================================================================
    // GETTERS Y SETTERS
    // =========================================================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReserva(): ?PmsReserva
    {
        return $this->reserva;
    }

    public function setReserva(?PmsReserva $reserva): self
    {
        $this->reserva = $reserva;
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getApellido(): ?string
    {
        return $this->apellido;
    }

    public function setApellido(?string $apellido): self
    {
        $this->apellido = $apellido;
        return $this;
    }

    public function getFechaNacimiento(): ?DateTimeInterface
    {
        return $this->fechaNacimiento;
    }

    public function setFechaNacimiento(?DateTimeInterface $fechaNacimiento): self
    {
        $this->fechaNacimiento = $fechaNacimiento;
        return $this;
    }

    public function getPais(): ?MaestroPais
    {
        return $this->pais;
    }

    public function setPais(?MaestroPais $pais): self
    {
        $this->pais = $pais;
        return $this;
    }

    public function getTipoDocumento(): ?MaestroTipodocumento
    {
        return $this->tipoDocumento;
    }

    public function setTipoDocumento(?MaestroTipodocumento $tipoDocumento): self
    {
        $this->tipoDocumento = $tipoDocumento;
        return $this;
    }

    public function getDocumentoNumero(): ?string
    {
        return $this->documentoNumero;
    }

    public function setDocumentoNumero(?string $documentoNumero): self
    {
        $this->documentoNumero = $documentoNumero;
        return $this;
    }

    public function isEsPrincipal(): ?bool
    {
        return $this->esPrincipal;
    }

    public function setEsPrincipal(bool $esPrincipal): self
    {
        $this->esPrincipal = $esPrincipal;
        return $this;
    }

    /**
     * Establece el archivo del documento y actualiza el timestamp.
     * * @param File|null $documentoFile
     */
    public function setDocumentoFile(?File $documentoFile = null): void
    {
        $this->documentoFile = $documentoFile;

        if (null !== $documentoFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getDocumentoFile(): ?File
    {
        return $this->documentoFile;
    }

    public function setDocumentoName(?string $documentoName): self
    {
        $this->documentoName = $documentoName;
        return $this;
    }

    public function getDocumentoName(): ?string
    {
        return $this->documentoName;
    }

    public function getTamNumero(): ?string
    {
        return $this->tamNumero;
    }

    public function setTamNumero(?string $tamNumero): self
    {
        $this->tamNumero = $tamNumero;
        return $this;
    }

    /**
     * Establece el archivo de la TAM y actualiza el timestamp.
     * * @param File|null $tamFile
     */
    public function setTamFile(?File $tamFile = null): void
    {
        $this->tamFile = $tamFile;

        if (null !== $tamFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getTamFile(): ?File
    {
        return $this->tamFile;
    }

    public function setTamName(?string $tamName): self
    {
        $this->tamName = $tamName;
        return $this;
    }

    public function getTamName(): ?string
    {
        return $this->tamName;
    }

    /**
     * Establece el archivo de firma.
     * Actualiza el timestamp general y, si es la primera vez, registra la fecha de firma.
     * * @param File|null $firmaFile
     */
    public function setFirmaFile(?File $firmaFile = null): void
    {
        $this->firmaFile = $firmaFile;

        if (null !== $firmaFile) {
            $this->updatedAt = new \DateTimeImmutable();

            // Si recién estamos subiendo la firma, marcamos la fecha de firma
            if ($this->firmadoEn === null) {
                $this->firmadoEn = new \DateTimeImmutable();
            }
        }
    }

    public function getFirmaFile(): ?File
    {
        return $this->firmaFile;
    }

    public function setFirmaName(?string $firmaName): self
    {
        $this->firmaName = $firmaName;
        return $this;
    }

    public function getFirmaName(): ?string
    {
        return $this->firmaName;
    }

    public function getFirmadoEn(): ?DateTimeInterface
    {
        return $this->firmadoEn;
    }

    public function setFirmadoEn(?DateTimeInterface $firmadoEn): self
    {
        $this->firmadoEn = $firmadoEn;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(DateTimeInterface $creado): self
    {
        $this->creado = $creado;
        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;
        return $this;
    }
}