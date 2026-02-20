<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity]
#[ORM\Table(name: 'msg_attachment')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable] // 🔥 ATRIBUTO REQUERIDO POR VICH
class MessageAttachment
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    // =========================================================================
    // CONFIGURACIÓN DE VICH UPLOADER
    // =========================================================================

    /**
     * Este es el campo virtual (NO se guarda en BD) que recibe el archivo físico del formulario.
     * Vich inyecta automáticamente los datos en las otras propiedades.
     */
    #[Vich\UploadableField(
        mapping: 'message_attachments', // <-- ¡Debes definir esto en tu vich_uploader.yaml!
        fileNameProperty: 'fileName',
        size: 'fileSize',
        mimeType: 'mimeType',
        originalName: 'originalName'
    )]
    private ?File $file = null;

    /**
     * El nombre generado por Vich (ej: "65c3a1...8d.pdf"). Reemplaza a tu antiguo filePath.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fileSize = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    // =========================================================================
    // MÉTODOS DE VICH UPLOADER (El Setter es vital)
    // =========================================================================

    public function setFile(?File $file = null): void
    {
        $this->file = $file;

        if (null !== $file) {
            // Es OBLIGATORIO modificar una fecha para que Doctrine detecte
            // el cambio en la entidad y lance los eventos que Vich necesita escuchar.
            $this->updatedAt = new \DateTime();
        }
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    // =========================================================================
    // HELPER PARA LIIP IMAGINE (El agnóstico)
    // =========================================================================

    /**
     * Helper para que la vista (Twig) sepa si debe pasarle el archivo a Liip
     * o si debe mostrar un simple enlace de descarga (para PDFs, DOCX).
     */
    public function isImage(): bool
    {
        return $this->mimeType !== null && str_starts_with($this->mimeType, 'image/');
    }

    // =========================================================================
    // GETTERS Y SETTERS TRADICIONALES
    // =========================================================================

    public function getId(): UuidV7 { return $this->id; }

    public function getMessage(): ?Message { return $this->message; }
    public function setMessage(?Message $message): self { $this->message = $message; return $this; }

    public function getFileName(): ?string { return $this->fileName; }
    public function setFileName(?string $fileName): self { $this->fileName = $fileName; return $this; }

    public function getOriginalName(): ?string { return $this->originalName; }
    public function setOriginalName(?string $originalName): self { $this->originalName = $originalName; return $this; }

    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(?string $mimeType): self { $this->mimeType = $mimeType; return $this; }

    public function getFileSize(): ?int { return $this->fileSize; }
    public function setFileSize(?int $fileSize): self { $this->fileSize = $fileSize; return $this; }
}