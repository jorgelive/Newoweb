<?php

namespace App\Oweb\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'cot_filepasajero')]
#[ORM\Entity]
class CotizacionFilepasajero
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    // Consigna: inicializar strings a null por compatibilidad con Symfony
    #[ORM\Column(name: 'nombre', type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(name: 'apellido', type: 'string', length: 100)]
    private ?string $apellido = null;

    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: false)]
    protected ?MaestroPais $pais = null;

    #[ORM\ManyToOne(targetEntity: MaestroSexo::class)]
    #[ORM\JoinColumn(name: 'sexo_id', referencedColumnName: 'id', nullable: false)]
    protected ?MaestroSexo $sexo = null;

    #[ORM\ManyToOne(targetEntity: MaestroTipodocumento::class)]
    #[ORM\JoinColumn(name: 'tipodocumento_id', referencedColumnName: 'id', nullable: false)]
    protected ?MaestroTipodocumento $tipodocumento = null;

    #[ORM\Column(name: 'fechanacimiento', type: 'date')]
    protected ?DateTimeInterface $fechanacimiento = null;

    // En DB es string; tipamos como string nullable para inicialización segura
    #[ORM\Column(name: 'numerodocumento', type: 'string', length: 100)]
    private ?string $numerodocumento = null;

    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'filepasajeros')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false)]
    protected ?CotizacionFile $file = null;

    // Fechas con DateTimeInterface y permitiendo null (Gedmo repuebla en persist/update)
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        $nombre = trim(($this->getNombre() ?? '') . ' ' . ($this->getApellido() ?? ''));
        if ($nombre === '') {
            return sprintf('Id: %s.', $this->getId() ?? '');
        }
        return $nombre;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setApellido(?string $apellido): self
    {
        $this->apellido = $apellido;
        return $this;
    }

    public function getApellido(): ?string
    {
        return $this->apellido;
    }

    public function getApellidoPaterno(): ?string
    {
        $apellido = $this->apellido ?? '';
        if ($apellido === '') {
            return null;
        }
        $apellidosArray = explode(' ', $apellido, 2);
        return $apellidosArray[0] ?? null;
    }

    public function getApellidoMaterno(): ?string
    {
        $apellido = $this->apellido ?? '';
        if ($apellido === '') {
            return null;
        }
        $apellidosArray = explode(' ', $apellido, 2);
        return $apellidosArray[1] ?? null;
    }

    public function setFechanacimiento(?DateTimeInterface $fechanacimiento): self
    {
        $this->fechanacimiento = $fechanacimiento;
        return $this;
    }

    public function getFechanacimiento(): ?DateTimeInterface
    {
        return $this->fechanacimiento;
    }

    public function getEdad(): ?int
    {
        if (!$this->fechanacimiento) {
            return null;
        }
        $hoy = new DateTimeImmutable('today');
        $diferencia = $hoy->diff($this->fechanacimiento);
        return $diferencia->y;
    }

    public function setNumerodocumento(?string $numerodocumento): self
    {
        $this->numerodocumento = $numerodocumento;
        return $this;
    }

    public function getNumerodocumento(): ?string
    {
        return $this->numerodocumento;
    }

    public function getTipopaxperurail(): ?int
    {
        $edad = $this->getEdad();
        if ($edad === null) {
            return null;
        }
        return $edad >= 12 ? 1 : 2;
    }

    public function getCategoriaddc(): ?int
    {
        $edad = $this->getEdad();
        if ($edad === null) {
            return null;
        }
        if ($edad >= 18) {
            return 1;
        }
        if ($edad >= 13 && $edad <= 17) {
            return 2;
        }
        if ($edad >= 3 && $edad <= 12) {
            return 7;
        }
        return 0;
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

    public function setPais(?MaestroPais $pais): self
    {
        $this->pais = $pais;
        return $this;
    }

    public function getPais(): ?MaestroPais
    {
        return $this->pais;
    }

    public function setSexo(?MaestroSexo $sexo): self
    {
        $this->sexo = $sexo;
        return $this;
    }

    public function getSexo(): ?MaestroSexo
    {
        return $this->sexo;
    }

    public function setTipodocumento(?MaestroTipodocumento $tipodocumento): self
    {
        $this->tipodocumento = $tipodocumento;
        return $this;
    }

    public function getTipodocumento(): ?MaestroTipodocumento
    {
        return $this->tipodocumento;
    }

    public function setFile(?CotizacionFile $file): self
    {
        $this->file = $file;
        return $this;
    }

    public function getFile(): ?CotizacionFile
    {
        return $this->file;
    }
}
