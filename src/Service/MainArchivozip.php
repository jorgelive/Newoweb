<?php

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MainArchivozip implements ContainerAwareInterface{

    use ContainerAwareTrait;

    private array $archivos;

    private string $archivoPath;

    private string $nombre;

    private bool $mantenerFuente;

    public function setArchivos(array $archivos): self
    {
        $this->archivos = $archivos;
        return $this;
    }

    public function getArchivos(): array
    {
        return $this->archivos;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setMantenerFuente(bool $mantenerFuente): self
    {
        $this->mantenerFuente=$mantenerFuente;
        return $this;
    }

    public function getMantenerFuente(): bool
    {
        return $this->mantenerFuente;
    }

    public function setNombre($nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function setParametros(array $archivos, string $nombre, bool $mantenerFuente = false): self
    {

        if(empty($archivos) || empty($nombre)){
            throw new HttpException(406, 'No estan correctamente ingresado el nombre del archivo o el array con los archivos y nombres.');
        }

        $this->setArchivos($archivos);

        $this->setNombre($nombre);

        $this->setMantenerFuente($mantenerFuente);

        return $this;
    }

    public function procesar(): self
    {

        $zip = new \ZipArchive();
        $this->archivoPath = tempnam(sys_get_temp_dir(), 'zip');
        $zip->open($this->getArchivoPath(),  \ZipArchive::CREATE);
        foreach($this->getArchivos() as $archivo) {
            $zip->addFile($archivo['path'], $archivo['nombre']);
        }

        $zip->close();

        if($this->getMantenerFuente() !== true){
            foreach($this->getArchivos() as $archivo) {
                unlink($archivo['path']);
            }
        }

        return $this;

    }

    public function getArchivoPath(): string
    {
        return $this->archivoPath;
    }

    public function getResponse(): Response
    {
        $response = new Response();
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $this->getNombre() . '.zip'));
        $response->headers->set('Content-Type', 'application/zip');
        $response->setStatusCode(200);
        $response->sendHeaders();
        $response->setContent(readfile($this->getArchivoPath()));

        unlink($this->getArchivoPath());
        return $response;
    }

}