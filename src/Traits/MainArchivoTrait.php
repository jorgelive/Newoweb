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
 * - Las entidades que lo usen deben tener: #[ORM\HasLifecycleCallbacks]
 */
trait MainArchivoTrait
{
    private string $internalPublicDir = __DIR__ . '/../../public';

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
    private ?string $extension = 'initial';

    #[ORM\Column(name: 'prioridad', type: 'integer', nullable: true)]
    private ?int $prioridad = null;

    #[ORM\Column(name: 'ancho', type: 'integer', nullable: true)]
    private ?int $ancho = null;

    #[ORM\Column(name: 'altura', type: 'integer', nullable: true)]
    private ?int $altura = null;

    #[Assert\File(maxSize: '6M')]
    private ?UploadedFile $archivo = null;

    // ----- getters/setters (sin cambios de lógica) -----

    public function setEnlace(?string $enlace)
    {
        $this->enlace = $enlace;
        return $this;
    }
    public function getEnlace(): ?string { return $this->enlace; }

    public function setEnlacecode(?string $enlacecode)
    {
        $this->enlacecode = $enlacecode;
        return $this;
    }
    public function getEnlacecode(): ?string { return $this->enlacecode; }

    public function setEnlaceurl(?string $enlaceurl)
    {
        $this->enlaceurl = $enlaceurl;
        return $this;
    }
    public function getEnlaceurl(): ?string { return $this->enlaceurl; }

    public function setEnlacethumburl(?string $enlacethumburl)
    {
        $this->enlacethumburl = $enlacethumburl;
        return $this;
    }
    public function getEnlacethumburl(): ?string { return $this->enlacethumburl; }

    public function setToken(?string $token)
    {
        $this->token = $token;
        return $this;
    }
    public function getToken(): string { return $this->token ?? ''; }

    public function setNombre(?string $nombre)
    {
        $this->nombre = $nombre;
        return $this;
    }
    public function getNombre(): ?string { return $this->nombre; }

    public function setExtension(?string $extension)
    {
        $this->extension = $extension;
        return $this;
    }
    public function getExtension(): ?string { return $this->extension; }

    public function getTipo(): string
    {
        if (in_array($this->getExtension(), $this->externalTypes)) return 'remoto';
        if (!empty($this->getExtension())) return 'local';
        return '';
    }

    public function setAncho(?int $ancho = null)
    {
        $this->ancho = $ancho;
        return $this;
    }
    public function getAncho(): ?int { return $this->ancho; }

    public function setAltura(?int $altura = null)
    {
        $this->altura = $altura;
        return $this;
    }
    public function getAltura(): ?int { return $this->altura; }

    /** @return int|float|null */
    public function getAspectRatio()
    {
        if (empty($this->ancho) || empty($this->altura)) return null;
        return $this->ancho / $this->altura;
    }

    public function setPrioridad(?int $prioridad = null)
    {
        $this->prioridad = $prioridad;
        return $this;
    }
    public function getPrioridad(): ?int { return $this->prioridad; }

    public function isInModal(): bool
    {
        return in_array($this->getExtension(), $this->modalTypes);
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
        if (null !== $this->getArchivo()) {
            $this->saveOldFilesInfo();
            $this->extension = $this->getArchivo()->getClientOriginalExtension();
            if (!$this->getNombre()) {
                $this->nombre = preg_replace('/\.[^.]*$/', '', $this->getArchivo()->getClientOriginalName());
            }

            $imageInfo = @getimagesize($this->getArchivo()->getPathname());
            $exifInfo  = $imageInfo ? @exif_read_data($this->getArchivo()->getPathname()) : false;

            if ($exifInfo || $imageInfo) {
                if ($exifInfo && array_key_exists('Orientation', $exifInfo)
                    && (array_key_exists('ExifImageLength', $exifInfo) || array_key_exists('ImageLength', $exifInfo))) {

                    if (array_key_exists('ImageLength', $exifInfo)) {
                        $aspectRatio = in_array($exifInfo['Orientation'], [6, 8])
                            ? $exifInfo['ImageLength'] / $exifInfo['ImageWidth']
                            : $exifInfo['ImageWidth'] / $exifInfo['ImageLength'];
                    } else {
                        $aspectRatio = in_array($exifInfo['Orientation'], [6, 8])
                            ? $exifInfo['ExifImageLength'] / $exifInfo['ExifImageWidth']
                            : $exifInfo['ExifImageWidth'] / $exifInfo['ExifImageLength'];
                    }
                } elseif ($imageInfo) {
                    [$anchoOriginal, $alturaOriginal] = $imageInfo;
                    $aspectRatio = $anchoOriginal / $alturaOriginal;
                }

                if (isset($aspectRatio) && $aspectRatio >= 1) {
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

            $this->setToken((string) mt_rand());
            $this->setEnlace(null);
            $this->setEnlacecode(null);
            $this->setEnlaceurl(null);
            $this->setEnlacethumburl(null);

        } elseif (null !== $this->getEnlace()) {
            $enlaceValido = false;
            $this->saveOldFilesInfo();

            if (preg_match($this->pregYoutube, $this->getEnlace(), $matches) === 1) {
                $this->setExtension('youtube');
                $this->setEnlacecode($matches[1]);
                $this->setEnlaceurl('https://www.youtube.com/embed/' . $matches[1]);
                $this->setEnlacethumburl('https://img.youtube.com/vi/' . $matches[1] . '/hqdefault.jpg');
                $enlaceValido = true;

            } elseif (preg_match($this->pregVimeo, $this->getEnlace(), $matches) === 1) {
                $hash = @unserialize(@file_get_contents('http://vimeo.com/api/v2/video/' . $matches[1] . ".php"));
                if ($hash !== false) {
                    $this->setExtension('vimeo');
                    $this->setEnlacecode($hash[0]['id']);
                    $this->setEnlaceurl('https://player.vimeo.com/video/' . $hash[0]['id']);
                    $this->setEnlacethumburl($hash[0]['thumbnail_medium']);
                    $enlaceValido = true;
                }
            }

            if ($enlaceValido == false) {
                $this->setEnlace(null);
                $this->setEnlacecode(null);
                $this->setEnlaceurl(null);
                $this->setEnlacethumburl(null);
            }

            if ($enlaceValido === true
                && !empty($this->oldFile['extension'])
                && !in_array($this->oldFile['extension'], array_merge($this->externalTypes, ['initial']))) {
                $this->setAncho(null);
                $this->setAltura(null);
                $this->removeOldFiles();
            }
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

        $imageTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (in_array($this->getArchivo()->getClientMimeType(), $imageTypes)) {
            $this->generarImagen($this->getArchivo(), $this->getInternalThumbDir(), $this->imageSize['thumb']['width'], $this->imageSize['thumb']['height']);
            $this->generarImagen($this->getArchivo(), $this->getInternalDir(), $this->imageSize['image']['width'], $this->imageSize['image']['height']);
            @unlink($this->getArchivo()->getPathname());
        } else {
            $this->getArchivo()->move($this->getInternalDir(), $this->id . '_' . $this->getToken() . '.' . $this->extension);
        }

        $this->setArchivo(null);
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
        if ($this->getExtension() === null) return '';
        return $this->getInternalDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $this->extension;
    }

    public function getWebPath(): string
    {
        if ($this->getExtension() === null) return '';
        if (in_array($this->getExtension(), $this->externalTypes)) return $this->getEnlaceurl() ?? '';
        return $this->getWebDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $this->extension;
    }

    protected function getWebDir(): string
    {
        return $this->path; // provisto por la clase que usa el trait
    }

    public function getTipoThumb(): string
    {
        if ($this->extension === null) return '';
        if (in_array($this->extension, $this->resizableTypes)) return 'image';
        return 'icon';
    }

    public function getInternalThumbPath(): string
    {
        if ($this->extension === null) return '';
        if (in_array($this->extension, $this->resizableTypes)) {
            return $this->getInternalThumbDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $this->extension;
        }
        if (in_array($this->getExtension(), $this->externalTypes)) {
            return $this->getInternalThumbDir() ?? '';
        }
        return $this->getInternalThumbDir() . '/' . $this->getIcon($this->extension) . '.png';
    }

    protected function getInternalThumbDir(): string
    {
        return $this->internalPublicDir . $this->getWebThumbDir();
    }

    public function getWebThumbPath(): string
    {
        if ($this->extension === null) return '';
        if (in_array($this->extension, $this->resizableTypes)) {
            return $this->getWebThumbDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $this->extension;
        }
        if (in_array($this->getExtension(), $this->externalTypes)) {
            return $this->getEnlacethumburl() ?? '';
        }
        return $this->getWebThumbDir() . '/' . $this->getIcon($this->extension) . '.png';
    }

    public function getWebThumbDir(): string
    {
        if (in_array($this->extension, $this->resizableTypes)) return $this->getWebDir() . '/thumb';
        return '/app/icons';
    }

    public function getIcon($extension): string
    {
        $tipos['image'] = ['tiff', 'tif', 'gif'];
        $tipos['word'] = ['doc', 'docx', 'rtf'];
        $tipos['text'] = ['txt'];
        $tipos['pdf'] = ['pdf'];
        $tipos['excel'] = ['xls', 'xlsx'];
        $tipos['powerpoint'] = ['ppt', 'pptx', 'ppsx', 'pps'];

        foreach ($tipos as $key => $tipo) {
            if (in_array($extension, $tipo)) return $key;
        }
        return 'developer';
    }

    public function refreshModificado(): void
    {
        // Cumple DateTimeInterface en tus entidades
        $this->setModificado(new \DateTime());
    }
}
