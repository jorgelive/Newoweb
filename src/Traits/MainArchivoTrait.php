<?php

namespace App\Traits;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * MainArchivo trait.
 *
 * Importante:
 * - Este trait define columnas legacy para evitar que Doctrine proponga DROP COLUMN.
 * - Las ENTIDADES que lo usen deben tener: #[ORM\HasLifecycleCallbacks]
 * - Requiere que la entidad provea: una propiedad/método getWebDir() (usa $this->path).
 */
trait MainArchivoTrait
{
    /**
     * Directorio absoluto del /public (filesystem).
     * Se recomienda inyectarlo con %kernel.project_dir%/public usando setInternalPublicDir().
     * Mantiene fallback relativo por si aún no se inyecta.
     */
    private string $internalPublicDir = __DIR__ . '/../../public';

    /** Permite inyectar %kernel.project_dir%/public desde un subscriber */
    public function setInternalPublicDir(string $dir): void
    {
        $this->internalPublicDir = rtrim($dir, '/');
    }

    /** @var array{extension:string,image:string,thumb:string,token:string} */
    private array $oldFile = ['extension' => '', 'image' => '', 'thumb' => '', 'token' => ''];
    private ?string $tempThumb = null;

    /** @var string[] */
    private array $externalTypes = ['youtube', 'vimeo'];
    /** @var string[] */
    private array $modalTypes    = ['jpg', 'jpeg', 'png', 'webp', 'youtube', 'vimeo'];
    /** @var string[] */
    private array $resizableTypes = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var array<string,array{width:string,height:string}> */
    private array $imageSize = [
        'image' => ['width' => '800', 'height' => '800'],
        'thumb' => ['width' => '400', 'height' => '400'],
    ];

    private string $pregYoutube = "/^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user|shorts)\/))([^\?&\"'>]+)/";
    private string $pregVimeo   = '/^(?:http(?:s)?:\/\/)?(?:player\.)?(?:www\.)?vimeo\.com\/(?:video\/)?(\d+)/';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $enlace = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $enlacecode = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $enlaceurl = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $enlacethumburl = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $token = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $extension = 'initial'; // sentinel

    #[ORM\Column(name: 'prioridad', type: 'integer', nullable: true)]
    private ?int $prioridad = null;

    #[ORM\Column(name: 'ancho', type: 'integer', nullable: true)]
    private ?int $ancho = null;

    #[ORM\Column(name: 'altura', type: 'integer', nullable: true)]
    private ?int $altura = null;

    #[Assert\File(maxSize: '8M')]
    private ?UploadedFile $archivo = null;

    // ----- getters/setters -----

    public function setEnlace(?string $enlace) { $this->enlace = $enlace; return $this; }
    public function getEnlace(): ?string { return $this->enlace; }

    public function setEnlacecode(?string $enlacecode) { $this->enlacecode = $enlacecode; return $this; }
    public function getEnlacecode(): ?string { return $this->enlacecode; }

    public function setEnlaceurl(?string $enlaceurl) { $this->enlaceurl = $enlaceurl; return $this; }
    public function getEnlaceurl(): ?string { return $this->enlaceurl; }

    public function setEnlacethumburl(?string $enlacethumburl) { $this->enlacethumburl = $enlacethumburl; return $this; }
    public function getEnlacethumburl(): ?string { return $this->enlacethumburl; }

    public function setToken(?string $token) { $this->token = $token; return $this; }
    public function getToken(): string { return $this->token ?? ''; }

    public function setNombre(?string $nombre) { $this->nombre = $nombre; return $this; }
    public function getNombre(): ?string { return $this->nombre; }

    public function setExtension(?string $extension)
    {
        $this->extension = $extension ? strtolower($extension) : $extension;
        return $this;
    }
    public function getExtension(): ?string { return $this->extension; }

    /**
     * Tipo lógico:
     * - ''       → no hay archivo/enlace (extension null o 'initial')
     * - 'remoto' → youtube/vimeo
     * - 'local'  → cualquier otro con extension real
     */
    public function getTipo(): string
    {
        $ext = $this->getExtension();
        if ($ext === null || $ext === 'initial') return '';
        if (in_array($ext, $this->externalTypes, true)) return 'remoto';
        return 'local';
    }

    public function setAncho(?int $ancho = null) { $this->ancho = $ancho; return $this; }
    public function getAncho(): ?int { return $this->ancho; }

    public function setAltura(?int $altura = null) { $this->altura = $altura; return $this; }
    public function getAltura(): ?int { return $this->altura; }

    /** @return int|float|null */
    public function getAspectRatio()
    {
        if (empty($this->ancho) || empty($this->altura) || $this->altura === 0) return null;
        return $this->ancho / $this->altura;
    }

    public function setPrioridad(?int $prioridad = null) { $this->prioridad = $prioridad; return $this; }
    public function getPrioridad(): ?int { return $this->prioridad; }

    public function isInModal(): bool
    {
        $ext = $this->getExtension();
        return $ext !== null && in_array($ext, $this->modalTypes, true);
    }

    public function setArchivo(?UploadedFile $archivo): void
    {
        $this->saveOldFilesInfo();
        $this->archivo = $archivo;
    }
    public function getArchivo(): ?UploadedFile { return $this->archivo; }

    // ----- lifecycle (usar #[ORM\HasLifecycleCallbacks] en la ENTIDAD que usa este trait) -----

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function preUpload()
    {
        // Caso 1: carga de archivo local
        if (null !== $this->getArchivo()) {
            $this->saveOldFilesInfo();

            // Normalizamos extensión a minúsculas
            $this->extension = strtolower((string) $this->getArchivo()->getClientOriginalExtension());

            // Si no hay nombre definido, lo tomamos del archivo sin la extensión
            if (!$this->getNombre()) {
                $this->nombre = preg_replace('/\.[^.]*$/', '', $this->getArchivo()->getClientOriginalName());
            }

            // Intentamos obtener dimensiones/aspect ratio
            $imageInfo = @getimagesize($this->getArchivo()->getPathname());
            $exifInfo  = $imageInfo ? @exif_read_data($this->getArchivo()->getPathname()) : false;
            unset($aspectRatio);

            if ($exifInfo || $imageInfo) {
                if ($exifInfo && array_key_exists('Orientation', $exifInfo)
                    && (array_key_exists('ExifImageLength', $exifInfo) || array_key_exists('ImageLength', $exifInfo))) {

                    // Calculamos ancho/alto desde EXIF, rotando si es necesario
                    $w = isset($exifInfo['ImageWidth']) ? (int)$exifInfo['ImageWidth'] :
                        (isset($exifInfo['ExifImageWidth']) ? (int)$exifInfo['ExifImageWidth'] : 0);
                    $h = isset($exifInfo['ImageLength']) ? (int)$exifInfo['ImageLength'] :
                        (isset($exifInfo['ExifImageLength']) ? (int)$exifInfo['ExifImageLength'] : 0);

                    if (in_array(($exifInfo['Orientation'] ?? 0), [6, 8], true)) {
                        [$w, $h] = [$h, $w];
                    }
                    if ($w > 0 && $h > 0) {
                        $aspectRatio = $w / $h;
                    }
                } elseif ($imageInfo) {
                    [$anchoOriginal, $alturaOriginal] = $imageInfo;
                    if ($alturaOriginal > 0) {
                        $aspectRatio = $anchoOriginal / $alturaOriginal;
                    }
                }

                if (isset($aspectRatio) && $aspectRatio > 0) {
                    if ($aspectRatio >= 1) {
                        $this->setAncho((int)$this->imageSize['image']['width']);
                        $this->setAltura((int)($this->imageSize['image']['width'] / $aspectRatio));
                    } else {
                        $this->setAltura((int)$this->imageSize['image']['height']);
                        $this->setAncho((int)($this->imageSize['image']['height'] * $aspectRatio));
                    }
                } else {
                    $this->setAncho(null);
                    $this->setAltura(null);
                }
            } else {
                $this->setAncho(null);
                $this->setAltura(null);
            }

            // Token fuerte para nombres únicos de archivos
            $this->setToken(bin2hex(random_bytes(8)));

            // Al subir archivo local, limpiamos cualquier enlace remoto previo
            $this->setEnlace(null);
            $this->setEnlacecode(null);
            $this->setEnlaceurl(null);
            $this->setEnlacethumburl(null);

            // Caso 2: enlace remoto (YouTube/Vimeo)
        } elseif (null !== $this->getEnlace()) {
            $enlaceValido = false;
            $this->saveOldFilesInfo();

            // YouTube
            if (preg_match($this->pregYoutube, $this->getEnlace(), $matches) === 1) {
                $this->setExtension('youtube');
                $this->setEnlacecode($matches[1]);
                $this->setEnlaceurl('https://www.youtube.com/embed/' . $matches[1]);
                $this->setEnlacethumburl('https://img.youtube.com/vi/' . $matches[1] . '/hqdefault.jpg');
                $enlaceValido = true;

                // Vimeo (oEmbed JSON sobre HTTPS; sin unserialize)
            } elseif (preg_match($this->pregVimeo, $this->getEnlace(), $matches) === 1) {
                $ctx = stream_context_create(['http' => ['timeout' => 3]]);
                $json = @file_get_contents('https://vimeo.com/api/oembed.json?url=https://vimeo.com/' . $matches[1], false, $ctx);
                $data = $json !== false ? json_decode($json, true) : null;

                if (is_array($data)) {
                    $this->setExtension('vimeo');
                    $this->setEnlacecode((string)$matches[1]);
                    $this->setEnlaceurl('https://player.vimeo.com/video/' . $matches[1]);
                    $this->setEnlacethumburl($data['thumbnail_url'] ?? null);
                    $enlaceValido = true;
                }
            }

            if ($enlaceValido == false) {
                // Reseteamos si el enlace no fue válido
                $this->setEnlace(null);
                $this->setEnlacecode(null);
                $this->setEnlaceurl(null);
                $this->setEnlacethumburl(null);
            }

            // Si pasamos a remoto, y antes había archivo local real, lo borramos
            if ($enlaceValido === true
                && !empty($this->oldFile['extension'])
                && !in_array($this->oldFile['extension'], array_merge($this->externalTypes, ['initial']), true)) {
                $this->setAncho(null);
                $this->setAltura(null);
                $this->removeOldFiles();
            }

            // Caso 3: ni archivo ni enlace → mantenemos limpio
        } else {
            $this->setEnlace(null);
        }
    }

    #[ORM\PostPersist]
    #[ORM\PostUpdate]
    public function upload(): void
    {
        if ($this->getArchivo() === null) return;

        $this->removeOldFiles();

        // Procesamos imágenes conocidas; el resto se mueve tal cual
        $imageTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (in_array($this->getArchivo()->getClientMimeType(), $imageTypes, true)) {
            // Genera thumb + image SIEMPRE. Con Imagick soportamos animados (thumb animado).
            $this->generarImagen($this->getArchivo(), $this->getInternalThumbDir(), (int)$this->imageSize['thumb']['width'], (int)$this->imageSize['thumb']['height']);
            $this->generarImagen($this->getArchivo(), $this->getInternalDir(),      (int)$this->imageSize['image']['width'], (int)$this->imageSize['image']['height']);
            @unlink($this->getArchivo()->getPathname());
        } else {
            // Otros tipos: mover sin redimensionar
            $this->getArchivo()->move($this->getInternalDir(), $this->id . '_' . $this->getToken() . '.' . $this->extension);
        }

        $this->setArchivo(null);
    }

    /**
     * Genera una imagen redimensionada en $destDir con tamaño $w x $h.
     * Prefiere IMAGICK si está disponible; si no, cae a GD.
     * - WebP animado con Imagick → conserva animación (también en thumb).
     * - WebP animado con GD → redimensiona, pero pierde animación (primer frame).
     * Mantiene transparencia para PNG/WebP.
     */
    protected function generarImagen(UploadedFile $file, string $destDir, int|string $w, int|string $h): bool
    {
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }

        $w = (int) $w;
        $h = (int) $h;

        $ext   = strtolower((string) $this->getExtension());
        $mime  = $file->getClientMimeType();
        $name  = $this->id . '_' . $this->getToken() . '.' . $ext;
        $path  = rtrim($destDir, '/').'/'.$name;
        $src   = $file->getPathname();

        $isWebp = ($ext === 'webp' || $mime === 'image/webp');
        $isAnimatedWebp = $isWebp && $this->isAnimatedWebp($src);

        // --- Ruta A: IMAGICK (preferida si está disponible) ---
        if (\extension_loaded('imagick')) {
            try {
                // Caso especial: WebP animado → redimensionar por frame y conservar animación (incluye thumb)
                if ($isAnimatedWebp) {
                    $im = new \Imagick();
                    $im->readImage($src);

                    // Normaliza a secuencia completa (cada frame independiente)
                    $im = $im->coalesceImages();

                    foreach ($im as $frame) {
                        if (\method_exists($frame, 'autoOrient')) {
                            @$frame->autoOrient();
                        } elseif (\method_exists($frame, 'autoOrientImage')) {
                            @$frame->autoOrientImage();
                        }

                        // Redimensiona manteniendo aspecto y rellenando hasta exacto
                        $frame->thumbnailImage($w, $h, true, true);

                        // Formato/opciones WebP
                        $frame->setImageFormat('webp');
                        $frame->setOption('webp:method', '6');
                        $frame->setOption('webp:quality', '85');
                        $frame->setOption('webp:alpha-quality', '85');
                        $frame->setImageCompressionQuality(85);

                        // Transparencia
                        $frame->setImageBackgroundColor(new \ImagickPixel('transparent'));

                        // Limpia metadatos
                        @$frame->stripImage();
                    }

                    // Para ambos (thumb e image) escribimos animación
                    $ok = $im->writeImages($path, true);
                    $im->clear(); $im->destroy();
                    return (bool)$ok;
                }

                // ---- WebP estático o JPG/PNG: ruta estándar con Imagick
                $im = new \Imagick();
                $im->readImage($src);

                if (\method_exists($im, 'autoOrient')) {
                    @$im->autoOrient();
                } elseif (\method_exists($im, 'autoOrientImage')) {
                    @$im->autoOrientImage();
                }

                // Formato de salida según extensión
                $format = match ($ext) {
                    'jpg', 'jpeg' => 'jpeg',
                    'png'         => 'png',
                    'webp'        => 'webp',
                    default       => null,
                };
                if ($format) {
                    $im->setImageFormat($format);
                }

                // Compresión/calidad por formato
                if (\in_array($ext, ['jpg','jpeg'], true)) {
                    $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
                    $im->setImageCompressionQuality(85);
                    $im->setInterlaceScheme(\Imagick::INTERLACE_JPEG);
                } elseif ($ext === 'png') {
                    $im->setImageCompression(\Imagick::COMPRESSION_ZIP);
                    $im->setInterlaceScheme(\Imagick::INTERLACE_PNG);
                } elseif ($ext === 'webp') {
                    $im->setOption('webp:method', '6');
                    $im->setOption('webp:quality', '85');
                    $im->setOption('webp:alpha-quality', '85');
                    $im->setImageCompressionQuality(85);
                }

                // Redimensionar manteniendo aspecto y rellenando al tamaño exacto
                $im->thumbnailImage($w, $h, true, true);

                // Fondo transparente si aplica
                if (\in_array($ext, ['png','webp'], true)) {
                    $im->setImageBackgroundColor(new \ImagickPixel('transparent'));
                }

                // Limpiar metadatos pesados (EXIF/ICC si no los necesitas)
                @$im->stripImage();

                $ok = $im->writeImage($path);
                $im->clear();
                $im->destroy();

                return (bool)$ok;
            } catch (\Throwable $e) {
                // Si Imagick falla por cualquier razón, caemos a GD
            }
        }

        // --- Ruta B: GD (fallback).
        // Nota: GD no soporta animación WebP; abre el primer frame. Cumple tu requerimiento:
        // redimensiona y genera thumb, pero se pierde la animación.
        if (!\function_exists('imagecreatetruecolor')) {
            // Sin GD: mover original sin transformar
            $file->move($destDir, $name);
            return true;
        }

        $create = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png'  => 'imagecreatefrompng',
            'image/webp' => (\function_exists('imagecreatefromwebp') ? 'imagecreatefromwebp' : null),
            default      => null,
        };
        $save = match ($ext) {
            'jpg','jpeg' => 'imagejpeg',
            'png'        => 'imagepng',
            'webp'       => (\function_exists('imagewebp') ? 'imagewebp' : null),
            default      => null,
        };

        if ($create === null || $save === null) {
            $file->move($destDir, $name);
            return true;
        }

        $srcIm = @$create($src);
        if (!$srcIm) {
            $file->move($destDir, $name);
            return true;
        }

        $dstIm = \imagecreatetruecolor($w, $h);

        // Transparencia en PNG/WebP
        if (\in_array($ext, ['png','webp'], true)) {
            \imagealphablending($dstIm, false);
            \imagesavealpha($dstIm, true);
            $transparent = \imagecolorallocatealpha($dstIm, 0, 0, 0, 127);
            \imagefilledrectangle($dstIm, 0, 0, $w, $h, $transparent);
        }

        $srcW = \imagesx($srcIm);
        $srcH = \imagesy($srcIm);

        // Ajuste tipo "cover": calculamos el rectángulo fuente para mantener aspecto y rellenar exacto
        $srcRatio = $srcW / max(1, $srcH);
        $dstRatio = $w / max(1, $h);
        if ($srcRatio > $dstRatio) {
            // Recortar ancho
            $newW = (int) floor($srcH * $dstRatio);
            $srcX = (int) floor(($srcW - $newW) / 2);
            $srcY = 0;
            $copyW = $newW;
            $copyH = $srcH;
        } else {
            // Recortar alto
            $newH = (int) floor($srcW / max(0.0001, $dstRatio));
            $srcX = 0;
            $srcY = (int) floor(($srcH - $newH) / 2);
            $copyW = $srcW;
            $copyH = $newH;
        }

        \imagecopyresampled($dstIm, $srcIm, 0, 0, $srcX, $srcY, $w, $h, $copyW, $copyH);

        $ok = match ($ext) {
            'jpg','jpeg' => $save($dstIm, $path, 85),
            'png'        => $save($dstIm, $path, 6),
            'webp'       => $save($dstIm, $path, 85),
            default      => $save($dstIm, $path),
        };

        \imagedestroy($srcIm);
        \imagedestroy($dstIm);

        if (!$ok) {
            // Último recurso: mover original
            $file->move($destDir, $name);
            return true;
        }

        return true;
    }

    #[ORM\PreRemove]
    public function storeFilenameForRemove(): void
    {
        $this->saveOldFilesInfo();
    }

    #[ORM\PostRemove]
    public function removeUpload(): void
    {
        $this->removeOldFiles();
    }

    private function saveOldFilesInfo(): void
    {
        $this->oldFile['extension'] = (string) $this->getExtension();
        if (!empty($this->getInternalPath()) && is_file($this->getInternalPath())) {
            $this->oldFile['image'] = $this->getInternalPath();
        }
        if (!empty($this->getInternalThumbPath()) && is_file($this->getInternalThumbPath())) {
            $this->oldFile['thumb'] = $this->getInternalThumbPath();
        }
    }

    private function removeOldFiles(): void
    {
        // No borres si el estado anterior era 'initial'
        if (!empty($this->oldFile['image']) && file_exists($this->oldFile['image']) && $this->oldFile['extension'] != 'initial') {
            @unlink($this->oldFile['image']);
            $this->oldFile['image'] = '';
        }
        if (!empty($this->oldFile['thumb']) && file_exists($this->oldFile['thumb']) && $this->oldFile['extension'] != 'initial') {
            @unlink($this->oldFile['thumb']);
            $this->oldFile['thumb'] = '';
        }
    }

    protected function getInternalDir(): string
    {
        return $this->internalPublicDir . $this->getWebDir();
    }

    public function getInternalPath(): string
    {
        // Evita rutas fantasma cuando extension es null o 'initial'
        if ($this->getExtension() === null || $this->getExtension() === 'initial') return '';
        return $this->getInternalDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $this->extension;
    }

    public function getWebPath(): string
    {
        // Evita rutas fantasma cuando extension es null o 'initial'
        if ($this->getExtension() === null || $this->getExtension() === 'initial') return '';
        if (in_array($this->getExtension(), $this->externalTypes, true)) return $this->getEnlaceurl() ?? '';
        return $this->getWebDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $this->extension;
    }

    protected function getWebDir(): string
    {
        return $this->path; // provisto por la clase que usa el trait
    }

    public function getTipoThumb(): string
    {
        $ext = $this->extension;
        if ($ext === null || $ext === 'initial') return '';
        if (in_array($ext, $this->resizableTypes, true)) return 'image';
        return 'icon';
    }

    public function getInternalThumbPath(): string
    {
        $ext = $this->extension;
        if ($ext === null || $ext === 'initial') return '';
        if (in_array($ext, $this->resizableTypes, true)) {
            return $this->getInternalThumbDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $ext;
        }
        if (in_array($ext, $this->externalTypes, true)) {
            // Externos: no existe thumb local real; devolvemos el dir por compatibilidad de llamadas.
            return $this->getInternalThumbDir() ?? '';
        }
        return $this->getInternalThumbDir() . '/' . $this->getIcon($ext) . '.png';
    }

    protected function getInternalThumbDir(): string
    {
        return $this->internalPublicDir . $this->getWebThumbDir();
    }

    public function getWebThumbPath(): string
    {
        $ext = $this->extension;
        if ($ext === null || $ext === 'initial') return '';
        if (in_array($ext, $this->resizableTypes, true)) {
            return $this->getWebThumbDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $ext;
        }
        if (in_array($ext, $this->externalTypes, true)) {
            return $this->getEnlacethumburl() ?? '';
        }
        return $this->getWebThumbDir() . '/' . $this->getIcon($ext) . '.png';
    }

    public function getWebThumbDir(): string
    {
        if ($this->extension !== null && in_array($this->extension, $this->resizableTypes, true)) {
            return $this->getWebDir() . '/thumb';
        }
        return '/app/icons';
    }

    public function getIcon($extension): string
    {
        $tipos['image']      = ['tiff', 'tif', 'gif'];
        $tipos['word']       = ['doc', 'docx', 'rtf'];
        $tipos['text']       = ['txt'];
        $tipos['pdf']        = ['pdf'];
        $tipos['excel']      = ['xls', 'xlsx'];
        $tipos['powerpoint'] = ['ppt', 'pptx', 'ppsx', 'pps'];

        foreach ($tipos as $key => $tipo) {
            if (in_array($extension, $tipo, true)) return $key;
        }
        return 'developer';
    }

    public function refreshModificado(): void
    {
        // Cumple DateTimeInterface en tus entidades
        $this->setModificado(new \DateTime());
    }

    /**
     * Detección rápida de WebP animado (ANIM/ANMF).
     */
    private function isAnimatedWebp(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (!$h) return false;
        $buf = fread($h, 512);
        fclose($h);
        return strpos($buf, 'ANMF') !== false || strpos($buf, 'ANIM') !== false;
    }
}
