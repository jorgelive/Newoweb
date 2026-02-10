<?php

namespace App\Oweb\Entity;

use App\Oweb\Entity\Trait\MainArchivoTrait;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'cot_filedocumento')]
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class CotizacionFiledocumento
{
    use MainArchivoTrait;

    /**
     * Ruta base fija para carga (no mapeada a DB).
     * Mantener como string literal ya que su valor es conocido.
     */
    private string $path = '/carga/cotizacionfiledocumento';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $vencimiento = null;

    #[ORM\ManyToOne(targetEntity: CotizacionTipofiledocumento::class)]
    #[ORM\JoinColumn(name: 'tipofiledocumento_id', referencedColumnName: 'id', nullable: false)]
    private ?CotizacionTipofiledocumento $tipofiledocumento = null;

    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'filedocumentos')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false)]
    protected ?CotizacionFile $file = null;

    // Fechas tipadas a DateTimeInterface y permitiendo null para compatibilidad con Gedmo
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        if (empty($this->getNombre())) {
            return sprintf('Id: %s.', $this->getId() ?? '');
        }
        if (!empty($this->getVencimiento())) {
            return sprintf('%s | %s', $this->getVencimiento()->format('Y-m-d'), $this->getNombre());
        }
        return (string) $this->getNombre();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setVencimiento(?DateTimeInterface $vencimiento): self
    {
        $this->vencimiento = $vencimiento;
        return $this;
    }

    public function getVencimiento(): ?DateTimeInterface
    {
        return $this->vencimiento;
    }

    public function setTipofiledocumento(?CotizacionTipofiledocumento $tipofiledocumento): self
    {
        $this->tipofiledocumento = $tipofiledocumento;
        return $this;
    }

    public function getTipofiledocumento(): ?CotizacionTipofiledocumento
    {
        return $this->tipofiledocumento;
    }

    public function setFile(?CotizacionFile $file = null): self
    {
        $this->file = $file;
        return $this;
    }

    public function getFile(): ?CotizacionFile
    {
        return $this->file;
    }

    public function setCreado(?DateTimeInterface $creado): self
    {
        $this->creado = $creado;
        return $this;
    }

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setModificado(?DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;
        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }
}
