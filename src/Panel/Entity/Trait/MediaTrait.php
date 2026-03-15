<?php

namespace App\Panel\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Trait global para la gestión de archivos en el Panel.
 * Funcionalidades:
 * 1. Seguridad: Genera un token único por entidad para evitar la enumeración de archivos.
 * 2. Visualización: Mapea extensiones a tus iconos en 'public/app/icons'.
 * 3. Lógica: Determina si un archivo debe procesarse con LiipImagine o mostrarse como icono.
 */
trait MediaTrait
{
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $token = null;

    public function initializeToken(): void
    {
        if ($this->token === null) {
            $this->token = bin2hex(random_bytes(8));
        }
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getIconPathFor(?string $fileName): string
    {
        if (!$fileName) {
            return '/app/icons/developer.png';
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $mapeo = [
            'pdf'   => 'pdf.png',
            'doc'   => 'word.png',
            'docx'  => 'word.png',
            'xls'   => 'excel.png',
            'xlsx'  => 'excel.png',
            'ppt'   => 'powerpoint.png',
            'pptx'  => 'powerpoint.png',
            'txt'   => 'text.png',
            'zip'   => 'compressed.png',
            'rar'   => 'compressed.png',
            '7z'    => 'compressed.png',
            'mp3'   => 'music.png',
            'wav'   => 'music.png',
            'ogg'   => 'music.png',
            'mp4'   => 'movie.png',
            'avi'   => 'movie.png',
            'mov'   => 'movie.png',
            'mkv'   => 'movie.png',
            'jpg'   => 'image.png',
            'jpeg'  => 'image.png',
            'png'   => 'image.png',
            'webp'  => 'image.png',
            'gif'   => 'image.png',
            'heic'  => 'image.png',
            'avif'  => 'image.png',
            'bmp'   => 'image.png',
            'tiff'  => 'image.png'
        ];

        return '/app/icons/' . ($mapeo[$ext] ?? 'developer.png');
    }

    /**
     * Determina si el archivo es compatible con LiipImagine.
     * Valida primero por MIME Type (si la entidad lo soporta) y luego por extensión.
     */
    public function isImage(?string $fileName = null): bool
    {
        // 1. VALIDACIÓN ESTRICTA: Por MIME Type (Si la entidad tiene la propiedad)
        if (method_exists($this, 'getMimeType') && $this->getMimeType() !== null) {
            if (str_starts_with($this->getMimeType(), 'image/')) {
                return true;
            }
            // Si tiene MimeType y NO empieza con image/, descartamos inmediatamente
            return false;
        }

        // 2. VALIDACIÓN DE RESPALDO: Por Extensión (Útil para entidades que no guardan el MimeType)
        // Intentamos autodescubrir el nombre del archivo si no lo pasaron como argumento
        if ($fileName === null) {
            if (method_exists($this, 'getFileName')) {
                $fileName = $this->getFileName(); // Estándar general
            } elseif (method_exists($this, 'getImageName')) {
                $fileName = $this->getImageName(); // Estándar de PmsUnidad
            }
        }

        if (!$fileName) {
            return false;
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($ext, [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'avif'
        ]);
    }
}