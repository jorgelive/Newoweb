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


    private $temp;

    private $tempThumb;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $nombre;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $extension;

    /**
     * @var int
     *
     * @ORM\Column(name="prioridad", type="integer", nullable=true)
     */
    private $prioridad;

    /**
     * @Assert\File(maxSize = "2M")
     */
    private $archivo;

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

    public function getInModal(){
        if(in_array($this->getExtension(), ['jpg', 'jpeg', 'png', 'txt'])){
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
        $this->archivo = $archivo;

        if (is_file($this->getInternalFullPath())) {
            $this->temp = $this->getInternalFullPath();
        }

        if (is_file($this->getInternalFullThumbPath())) {
            $this->tempThumb = $this->getInternalFullThumbPath();
        }

        $this->extension = 'initial';

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
        if (null !== $this->getArchivo() || $this->archivo ) {
            //$this->extension = $this->getArchivo()->guessExtension();
            $this->extension = $this->getArchivo()->getClientOriginalExtension();
            if(!$this->getNombre()){
                $this->nombre = preg_replace('/\.[^.]*$/', '', $this->getArchivo()->getClientOriginalName());
            }
        }
    }

    /**
     * @ORM\PostPersist()
     * @ORM\PostUpdate()
     */
    public function upload()
    {
        if ($this->getArchivo() === null) {
            return;
        }
        if (!empty($this->temp)){
            unlink($this->temp);
            $this->temp = null;
        }
        if (!empty($this->tempThumb)){
            unlink($this->tempThumb);
            $this->tempThumb = null;
        }

        $imageTypes = ['image/jpeg', 'image/png'];

        if(in_array($this->getArchivo()->getMimeType(), $imageTypes )){
            //debe ir antes ta que la imagen sera movida
            $this->generarThumb($this->getArchivo(), $this->getInternalFullThumbDir(), 200, 200);
            $this->generarThumb($this->getArchivo(), $this->getInternalFullDir(), 800, 800);
            unlink($this->getArchivo()->getPathname());
        }else{
            $this->getArchivo()->move($this->getInternalFullDir(), $this->id . '.' . $this->extension);
        }

        $this->setArchivo(null);
    }


    public function generarThumb($image, $path, $ancho, $alto){
        // Create Imagick object

        $im = new \Imagick();
        $im->readImage($image->getPathname()); //Read the file
        $im->setCompressionQuality(95);

        if($image->getMimeType() == 'image/jpeg') {
            $im->setInterlaceScheme(\Imagick::INTERLACE_PNG);
        }elseif($image->getMimeType() == 'image/png'){
            $im->setInterlaceScheme(\Imagick::INTERLACE_JPEG);
        }
        $im->resizeImage($ancho, $alto,\Imagick::FILTER_LANCZOS, 1, TRUE);

        if(!is_dir($path)){
            mkdir($path, 0755, true);
        }
        //return $im->writeImages('C:\wamp\temp', true);
        return $im->writeImages($path . '/' . $this->id . '.' . $this->extension, true);

    }

    /**
     * @ORM\PreRemove()
     */
    public function storeFilenameForRemove()
    {
        $this->temp = $this->getInternalFullPath();
        if(!empty($this->getInternalFullThumbPath())){
            $this->tempThumb = $this->getInternalFullThumbPath();
        }
    }

    /**
     * @ORM\PostRemove()
     */
    public function removeUpload()
    {
        if (!empty($this->temp)) {
            unlink($this->temp);
        }
        if (!empty($this->tempThumb)) {
            unlink($this->tempThumb);
        }
    }

    protected function getInternalFullDir()
    {
        return __DIR__ . '/../../public' . $this->getWebDir();
    }

    public function getInternalFullPath()
    {
        if($this->extension === null){
            return null;
        }

        return $this->getInternalFullDir() . '/' . $this->id . '.' . $this->extension;
    }

    public function getWebPath()
    {
        if($this->extension === null){
            return null;
        }
        return $this->getWebDir() . '/' . $this->id . '.' . $this->extension;
    }

    public function getInternalFullThumbPath()
    {
        if($this->extension === null || empty($this->getInternalFullThumbDir())){
            return null;
        }
        return $this->getInternalFullThumbDir() . '/' . $this->id . '.' . $this->extension;
    }

    protected function getInternalFullThumbDir()
    {
        if(in_array($this->extension, ['jpg', 'jpeg', 'png'])){

            return __DIR__ . '/../../public' . $this->getWebThumbDir();
        }
        return null;
    }

    public function getWebThumbPath()
    {
        if($this->extension === null){
            return null;
        }
        if(in_array($this->extension, ['jpg', 'jpeg', 'png'])){
            return $this->getWebThumbDir() . '/' . $this->id . '.' . $this->extension;
        }else{
            return $this->getWebThumbDir() . '/' . $this->getIcon($this->extension) . '.png';
        }
    }

    public function getIcon($extension){
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

    public function getWebThumbDir()
    {
        if(in_array($this->extension, ['jpg', 'jpeg', 'png'])){
            return $this->getWebDir() . '/thumb';
        }else{
            return '/app/icons';
        }
    }

    protected function getWebDir()
    {
        return $this->path;
    }

    public function refreshModificado()
    {
        $this->setModificado(new \DateTime());
    }
}
