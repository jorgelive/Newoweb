<?php

namespace App\Traits;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\HttpFoundation\File\UploadedFile;


/**
 * MainArchivo trait.
 *
 */
trait MainArchivoTrait
{
    private string $internalPublicDir = __DIR__ . '/../../public';

    private $oldFile = ['extension' => '', 'image' => '', 'thumb' => '', 'token' => ''];
    private $tempThumb;

    private $externalTypes = ['youtube', 'vimeo'];
    private $modalTypes = ['jpg', 'jpeg', 'png', 'webp', 'youtube', 'vimeo'];
    private $resizableTypes = ['jpg', 'jpeg', 'png', 'webp'];
    private $imageSize = ['image' => ['width' => '800', 'height' => '800'], 'thumb' => ['width' => '400', 'height' => '400']];


    private $pregYoutube = "/^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user|shorts)\/))([^\?&\"'>]+)/";
    private $pregVimeo = '/^(?:http(?:s)?:\/\/)?(?:player\.)?(?:www\.)?vimeo\.com\/(?:video\/)?(\d+)/';


    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $enlace;

    /**
     * @ORM\Column(type="string", length=30, nullable=true)
     */
    private $enlacecode;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $enlaceurl;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $enlacethumburl;

    /**
     * @ORM\Column(type="string", length=30, nullable=true)
     */
    private $token;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $nombre;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $extension = 'initial';

    /**
     * @var int
     *
     * @ORM\Column(name="prioridad", type="integer", nullable=true)
     */
    private $prioridad;

    /**
     * @Assert\File(maxSize = "6M")
     */
    private $archivo;

    /**
     * Set enlace
     *
     * @param string $enlace
     */
    public function setEnlace($enlace)
    {
        $this->enlace = $enlace;

        return $this;
    }

    /**
     * Get enlace
     *
     * @return string
     */
    public function getEnlace()
    {
        return $this->enlace;
    }

    /**
     * Set enlacecode
     *
     * @param string $enlacecode
     */
    public function setEnlacecode($enlacecode)
    {
        $this->enlacecode = $enlacecode;

        return $this;
    }

    /**
     * Get enlacecode
     *
     * @return string
     */
    public function getEnlacecode()
    {
        return $this->enlacecode;
    }

    /**
     * Set enlaceurl
     *
     * @param string $enlaceurl
     */
    public function setEnlaceurl($enlaceurl)
    {
        $this->enlaceurl = $enlaceurl;

        return $this;
    }

    /**
     * Get enlaceurl
     *
     * @return string
     */
    public function getEnlaceurl()
    {
        return $this->enlaceurl;
    }

    /**
     * Set enlacethumburl
     *
     * @param string $enlacethumburl
     */
    public function setEnlacethumburl($enlacethumburl)
    {
        $this->enlacethumburl = $enlacethumburl;

        return $this;
    }

    /**
     * Get enlacethumburl
     *
     * @return string
     */
    public function getEnlacethumburl()
    {
        return $this->enlacethumburl;
    }

    /**
     * Set token
     *
     * @param string $token
     */
    public function setToken($token)
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Get token
     *
     * @return string
     */
    public function getToken()
    {
        return $this->token ?? '';
    }

    /**
     * Set nombre
     *
     * @param string $nombre
     */
    public function setNombre($nombre)
    {
        $this->nombre = $nombre;

        return $this;
    }

    /**
     * Get nombre
     *
     * @return string
     */
    public function getNombre()
    {
        return $this->nombre;
    }

    /**
     * Set extension
     *
     * @param string $extension
     */
    public function setExtension($extension)
    {
        $this->extension = $extension;

        return $this;
    }

    /**
     * Get extension
     *
     * @return string
     */
    public function getExtension()
    {
        return $this->extension;
    }

    /**
     * Get tipo
     *
     * @return string
     */
    public function getTipo(): string
    {
        if(in_array($this->getExtension(), $this->externalTypes)) {
            return 'remoto';
        }elseif(!empty($this->getExtension())){
            return 'local';
        }else{
            return '';
        }
    }

    /**
     * Set prioridad.
     *
     * @param int|null $prioridad
     */
    public function setPrioridad($prioridad = null)
    {
        $this->prioridad = $prioridad;

        return $this;
    }

    /**
     * Get prioridad.
     *
     * @return int|null
     */
    public function getPrioridad()
    {
        return $this->prioridad;
    }

    /**
     * Get inModal.
     *
     * @return bool
     */
    public function getInModal(){
        if(in_array($this->getExtension(), $this->modalTypes)){
            return true;
        }
        return false;
    }

    /**
     * Sets archivo.
     *
     * @param UploadedFile $archivo
     */
    public function setArchivo(UploadedFile $archivo = null)
    {
        $this->saveOldFilesInfo();

        $this->archivo = $archivo;

    }

    /**
     * Get archivo.
     *
     * @return UploadedFile
     */
    public function getArchivo()
    {
        return $this->archivo;
    }

    /**
     * @ORM\PrePersist()
     * @ORM\PreUpdate()
     */
    public function preUpload()
    {
        if(null !== $this->getArchivo()) {
            $this->saveOldFilesInfo();
            $this->extension = $this->getArchivo()->getClientOriginalExtension();
            if(!$this->getNombre()){
                $this->nombre = preg_replace('/\.[^.]*$/', '', $this->getArchivo()->getClientOriginalName());
            }
            //limpiamos enlace si es que hay archivo
            $this->setToken(mt_rand());
            $this->setEnlace(null);
            $this->setEnlacecode(null);
            $this->setEnlaceurl(null);
            $this->setEnlacethumburl(null);

        }elseif(null !== $this->getEnlace()){

            $enlaceValido = false;

            $this->saveOldFilesInfo();

            if(preg_match($this->pregYoutube, $this->getEnlace(), $matches) == 1){
                $this->setExtension('youtube');
                $this->setEnlacecode($matches[1]);
                $this->setEnlaceurl('https://www.youtube.com/embed/' . $matches[1]);
                $this->setEnlacethumburl('https://img.youtube.com/vi/' . $matches[1] . '/hqdefault.jpg');
                $enlaceValido = true;
            }elseif(preg_match($this->pregVimeo, $this->getEnlace(), $matches) == 1){
                $hash = unserialize(@file_get_contents('http://vimeo.com/api/v2/video/' . $matches[1] . ".php"));
                if($hash !== false){
                    $this->setExtension('vimeo');
                    $this->setEnlacecode($hash[0]['id']);
                    $this->setEnlaceurl('https://player.vimeo.com/video/' . $hash[0]['id']);
                    $this->setEnlacethumburl($hash[0]['thumbnail_medium']);
                    $enlaceValido = true;
                }else{
                    $this->setExtension(null);
                    $this->setEnlace(null);
                    $this->setEnlacecode(null);
                    $this->setEnlaceurl(null);
                    $this->setEnlacethumburl(null);
                }
            }else{ //se ha invalidado el enlace, lo quitamos
                $this->setEnlace(null);
                if($this->getExtension() == 'initial' || in_array($this->getExtension(), $this->externalTypes)){
                    $this->setExtension(null);
                    $this->setEnlace(null);
                    $this->setEnlacecode(null);
                    $this->setEnlaceurl(null);
                    $this->setEnlacethumburl(null);
                }
            }
            //si cambia de archivo a enlace borramos los archivos, no se borra nada si es que esta en estado inicial (carga) o ya era enlace
            if($enlaceValido === true
                && !empty($this->oldFile['extension'])
                && !in_array($this->oldFile['extension'], array_merge($this->externalTypes, ['initial']))
                ){
                $this->removeOldFiles();
            }
        }else{
            //no deberia darse este caso solo motivos de prueba
            $this->setEnlace(null);
        }
    }

    /**
     * @ORM\PostPersist()
     * @ORM\PostUpdate()
     */
    public function upload(): void
    {
        //limpia archivos antiguos solo si no envie nuevos
        if($this->getArchivo() === null) {
            return;
        }
        
        $this->removeOldFiles();

        $imageTypes = ['image/jpeg', 'image/png', 'image/webp'];

        if(in_array($this->getArchivo()->getClientMimeType(), $imageTypes)){ //getClientMimeType reemplazado por que produce error en webp
            //debe ir antes ya que la imagen sera movida
            $this->generarImagen($this->getArchivo(), $this->getInternalThumbDir(), $this->imageSize['thumb']['width'], $this->imageSize['thumb']['height']);
            $this->generarImagen($this->getArchivo(), $this->getInternalDir(), $this->imageSize['image']['width'], $this->imageSize['image']['height']);
            unlink($this->getArchivo()->getPathname());
        }else{
            $this->getArchivo()->move($this->getInternalDir(), $this->id . '_' . $this->getToken() . '.' . $this->extension);
        }

        $this->setArchivo(null);
    }


    public function generarImagen($image, $path, $ancho, $alto): bool
    {
        // Create Imagick object

        $im = new \Imagick();
        $im->readImage($image->getPathname()); //Read the file
        $im->setCompressionQuality(95);

        if($image->getMimeType() == 'image/jpeg' || $image->getMimeType() == 'image/webp') { //reconoce webp como jpg
            $im->setInterlaceScheme(\Imagick::INTERLACE_JPEG);
        }elseif($image->getMimeType() == 'image/png'){
            $im->setInterlaceScheme(\Imagick::INTERLACE_PNG);
        }
        $im->resizeImage($ancho, $alto,\Imagick::FILTER_LANCZOS, 1, TRUE);

        if(!is_dir($path)){
            mkdir($path, 0755, true);
        }
        //return $im->writeImages('C:\wamp\temp', true);
        return $im->writeImages($path . '/' . $this->id . '_' . $this->getToken() . '.' . $this->getExtension(), true);

    }

    /**
     * @ORM\PreRemove()
     */
    public function storeFilenameForRemove(): void
    {
        $this->saveOldFilesInfo();
    }
    
    private function saveOldFilesInfo(): void
    {
        $this->oldFile['extension'] = $this->getExtension();
        if(!empty($this->getInternalPath()) && is_file($this->getInternalPath())) {
            $this->oldFile['image'] = $this->getInternalPath();
        }
        if(!empty($this->getInternalThumbPath()) && is_file($this->getInternalThumbPath())){
            $this->oldFile['thumb'] = $this->getInternalThumbPath();
        }
    }

    /**
     * @ORM\PostRemove()
     */
    public function removeUpload(): void
    {
        $this->removeOldFiles();
    }
    
    private function removeOldFiles(): void
    {
        if(!empty($this->oldFile['image']) && file_exists($this->oldFile['image'])){
            unlink($this->oldFile['image']);
            $this->oldFile['image'] = '';
        }
        if(!empty($this->oldFile['thumb']) && file_exists($this->oldFile['thumb'])){
            unlink($this->oldFile['thumb']);
            $this->oldFile['thumb'] = '';
        }
    }
    
    protected function getInternalDir(): string
    {
        return $this->internalPublicDir . $this->getWebDir();
    }

    public function getInternalPath(): string
    {
        if($this->getExtension() === null){
            return '';
        }

        return $this->getInternalDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $this->extension;
    }

    //acceso desde twig
    public function getWebPath(): string
    {
        if($this->getExtension() === null){
            return '';
        }elseif(in_array($this->getExtension(), $this->externalTypes)){
            return $this->getEnlaceurl() ?? '';
        }else{
            return $this->getWebDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $this->extension;
        }
    }
    protected function getWebDir(): string
    {
        //de la clase que extiende
        return $this->path;
    }

    public function getInternalThumbPath(): string
    {
        if($this->getExtension() === null || empty($this->getInternalThumbDir())){
            return '';
        }
        return $this->getInternalThumbDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $this->extension;
    }

    protected function getInternalThumbDir(): string
    {
        if(in_array($this->getExtension(), $this->resizableTypes)){

            return $this->internalPublicDir . $this->getWebThumbDir();
        }
        return '';
    }

    //acceso desde twig
    public function getWebThumbPath(): string
    {
        if($this->extension === null){
            return '';
        }elseif(in_array($this->extension, $this->resizableTypes)){
            return $this->getWebThumbDir() . '/' . $this->id . '_' . $this->getToken() . '.' . $this->extension;
        }elseif(in_array($this->getExtension(), $this->externalTypes)){
            return $this->getEnlacethumburl() ?? '';
        }else{
            return $this->getWebThumbDir() . '/' . $this->getIcon($this->extension) . '.png';
        }
    }

    public function getWebThumbDir(): string
    {
        if(in_array($this->extension, $this->resizableTypes)){
            return $this->getWebDir() . '/thumb';
        }else{
            return '/app/icons';
        }
    }

    public function getIcon($extension): string
    {
        $tipos['image'] = ['tiff', 'tif', 'gif'];
        $tipos['word'] = ['doc', 'docx', 'rtf'];
        $tipos['text'] = ['txt'];
        $tipos['pdf'] = ['pdf'];
        $tipos['excel'] = ['xls', 'xlsx'];
        $tipos['powerpoint'] = ['ppt', 'pptx', 'ppsx', 'pps'];

        foreach($tipos as $key => $tipo):
            if(in_array($extension, $tipo)){
                return $key;
            }
        endforeach;

        return 'developer';
    }



    public function refreshModificado(): void
    {
        $this->setModificado(new \DateTime());
    }
}
