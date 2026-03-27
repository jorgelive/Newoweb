<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Contract\RequiresJpegConversionInterface; // 🔥 Importamos el contrato
use App\Panel\Entity\Trait\MediaTrait;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Representa un archivo físico adjunto a un mensaje.
 * Implementa RequiresJpegConversionInterface para forzar que las imágenes
 * se guarden como JPEG, garantizando compatibilidad con canales externos (Beds24).
 */
#[ORM\Entity]
#[ORM\Table(name: 'msg_attachment')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class MessageAttachment implements RequiresJpegConversionInterface
{
    use IdTrait;
    use TimestampTrait;
    use MediaTrait;

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    // =========================================================================
    // CONFIGURACIÓN DE VICH UPLOADER
    // =========================================================================

    /**
     * Campo virtual (NO persistido en BD) que recibe el archivo temporal inyectado
     * por el controlador, el decodificador o el State Processor.
     */
    #[Vich\UploadableField(
        mapping: 'message_attachments',
        fileNameProperty: 'fileName',
        size: 'fileSize',
        mimeType: 'mimeType',
        originalName: 'originalName'
    )]
    private ?File $file = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['message:read'])]
    private ?string $fileName = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['message:read'])]
    private ?string $originalName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['message:read'])]
    private ?string $mimeType = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $fileUpdatedAt = null;

    #[Groups(['message:read'])]
    private ?string $fileUrl = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    // =========================================================================
    // MÉTODOS DE ARCHIVO VIRTUAL (VICH UPLOADER)
    // =========================================================================

    /**
     * Inyecta el archivo físico. Al hacerlo, actualizamos la fecha de modificación
     * para que Doctrine detecte un cambio en la entidad y dispare los eventos de VichUploader.
     *
     * @param File|null $file
     */
    public function setFile(?File $file = null): void
    {
        $this->file = $file;
        if (null !== $file) {
            $this->fileUpdatedAt = new DateTimeImmutable();
        }
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    // =========================================================================
    // GETTERS Y SETTERS ESTÁNDAR
    // =========================================================================

    #[Groups(['message:read'])]
    public function getId(): UuidV7
    {
        return $this->id;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): self
    {
        $this->fileName = $fileName;
        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): self
    {
        $this->originalName = $originalName;
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getFileUpdatedAt(): ?DateTimeImmutable
    {
        return $this->fileUpdatedAt;
    }

    /**
     * Establece la fecha de actualización del archivo.
     * Si la librería envía un objeto DateTime estándar, lo convertimos a Inmutable
     * para mantener la consistencia del tipo de dato en la entidad.
     */
    public function setFileUpdatedAt(?DateTimeInterface $fileUpdatedAt): self
    {
        if ($fileUpdatedAt instanceof DateTime) {
            $this->fileUpdatedAt = DateTimeImmutable::createFromMutable($fileUpdatedAt);
        } else {
            $this->fileUpdatedAt = $fileUpdatedAt;
        }

        return $this;
    }

    public function getFileUrl(): ?string
    {
        return $this->fileUrl;
    }

    public function setFileUrl(?string $fileUrl): self
    {
        $this->fileUrl = $fileUrl;
        return $this;
    }
}