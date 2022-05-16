<?php

namespace App\Service;

use \Symfony\Component\Filesystem\Filesystem;
use \Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use \Symfony\Component\HttpFoundation\Response;
use \Symfony\Component\DependencyInjection\ContainerAwareInterface;
use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

class MainArchivozip implements ContainerAwareInterface{

    use ContainerAwareTrait;

    private $archivos;

    private $archivoPath;

    private $nombre;

    private $mantenerFuente;

    public function setArchivos($archivos){
        $this->archivos=$archivos;
        return $this;
    }

    public function getArchivos(){
        return $this->archivos;
    }

    public function getNombre(){
        return $this->nombre;
    }

    public function setMantenerFuente($mantenerFuente){
        $this->mantenerFuente=$mantenerFuente;
        return $this;
    }

    public function getMantenerFuente(){
        return $this->mantenerFuente;
    }

    public function setNombre($nombre){
        $this->nombre=$nombre;
        return $this;
    }

    public function setParametros($archivos,$nombre,$mantenerFuente=false){
        if(is_array($archivos)){
            $this->setArchivos($archivos);
        }
        if(!empty($nombre)){
            $this->setNombre($nombre);
        }
        if(!empty($mantenerFuente)){
            $this->setMantenerFuente($mantenerFuente);

        }
        return $this;
    }

    public function setArchivo(){
        if(empty($this->getArchivos())||empty($this->getNombre())){
            throw $this->createNotFoundException('No estan correctamente ingresados los archivos a comprimir.');
            return false;
        }
        $zip = new \ZipArchive();
        $this->archivoPath = tempnam(sys_get_temp_dir(), 'zip');
        $zip->open($this->getArchivoPath(),  \ZipArchive::CREATE);
        foreach ($this->getArchivos() as $archivo) {
            $zip->addFile($archivo['path'], $archivo['nombre']);
        }
        $zip->close();
        if($this->getMantenerFuente()!==true){
            foreach ($this->getArchivos() as $archivo) {
                unlink($archivo['path']);
            }
        }
        return $this;

    }

    public function getArchivoPath(){
        return $this->archivoPath;
    }

    public function getArchivo($tipo='response'){

        if($tipo=='archivo'){
            return $this->getArchivoPath();

        }elseif($tipo=='response'){
            $response = new Response();
            //$response->headers->set('X-Sendfile', $zipName);
            $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $this->getNombre().'.zip'));
            $response->headers->set('Content-Type', 'application/zip');
            $response->setStatusCode(200);
            $response->sendHeaders();
            $response->setContent(readfile($this->getArchivoPath()));
            
            unlink($this->getArchivoPath());
            return $response;
        }else{
            return false;
        }
    }

}