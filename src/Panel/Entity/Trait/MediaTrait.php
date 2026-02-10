<?php

namespace App\Panel\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Trait global para la gestión de archivos en el Panel.
 * * Funcionalidades:
 * 1. Seguridad: Genera un token único por entidad para evitar la enumeración de archivos.
 * 2. Visualización: Mapea extensiones a tus iconos en 'public/app/icons'.
 * 3. Lógica: Determina si un archivo debe procesarse con LiipImagine o mostrarse como icono.
 * * Requisitos:
 * - La entidad debe tener #[ORM\HasLifecycleCallbacks]
 * - Llamar a $this->initializeToken() en #[ORM\PrePersist]
 */
trait MediaTrait
{
    /**
     * Token único de seguridad compartido por todos los archivos de esta entidad.
     * Se usa en el Namer para salar el nombre del archivo.
     */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $token = null;

    /**
     * Inicializa el token si no existe.
     * Debe llamarse en el evento PrePersist de la entidad.
     */
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

    /**
     * Retorna la ruta relativa del icono correspondiente a la extensión del archivo.
     * Basado en tu estructura de carpetas: public/app/icons/
     * * @param string|null $fileName Nombre del archivo físico (ej: 'documento.pdf')
     * @return string Ruta relativa lista para usar en Twig con asset()
     */
    public function getIconPathFor(?string $fileName): string
    {
        if (!$fileName) {
            return '/app/icons/developer.png';
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $mapeo = [
            // Documentos de Oficina
            'pdf'   => 'pdf.png',
            'doc'   => 'word.png',
            'docx'  => 'word.png',
            'xls'   => 'excel.png',
            'xlsx'  => 'excel.png',
            'ppt'   => 'powerpoint.png',
            'pptx'  => 'powerpoint.png',
            'txt'   => 'text.png',

            // Archivos Comprimidos
            'zip'   => 'compressed.png',
            'rar'   => 'compressed.png',
            '7z'    => 'compressed.png',

            // Multimedia
            'mp3'   => 'music.png',
            'wav'   => 'music.png',
            'ogg'   => 'music.png',
            'mp4'   => 'movie.png',
            'avi'   => 'movie.png',
            'mov'   => 'movie.png',
            'mkv'   => 'movie.png',

            // Imágenes (Icono genérico por si falla la miniatura o es un formato raro)
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

        // Fallback por defecto si la extensión no está mapeada
        return '/app/icons/' . ($mapeo[$ext] ?? 'developer.png');
    }

    /**
     * Determina si el archivo es compatible con LiipImagine para generar thumbnails.
     * Si retorna true, el sistema intentará crear una miniatura visual.
     * Si retorna false, el sistema mostrará el icono correspondiente.
     */
    public function isImage(?string $fileName): bool
    {
        if (!$fileName) {
            return false;
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Lista estricta de formatos que Liip/Imagick pueden procesar visualmente
        return in_array($ext, [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'avif'
        ]);
    }
}