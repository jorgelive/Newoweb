<?php
namespace App\Oweb\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'cot_cotcomponenteoperativa')]
class CotizacionCotcomponenteoperativa
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'operativa')]
    #[ORM\JoinColumn(name: 'cotcomponente_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotcomponente $cotcomponente = null;

    #[ORM\ManyToOne(targetEntity: MaestroContacto::class)]
    #[ORM\JoinColumn(name: 'contacto_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MaestroContacto $contacto = null;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?DateTimeInterface $horarecojoinicial = null;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?DateTimeInterface $horarecojofinal = null;

    #[ORM\Column(type: 'integer', nullable: true, options: ['default' => 0])]
    private ?int $tolerancia = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notas = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        return sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function getId(): ?int { return $this->id; }

    public function getCotcomponente(): ?CotizacionCotcomponente { return $this->cotcomponente; }
    public function setCotcomponente(CotizacionCotcomponente $cotcomponente): self
    {
        $this->cotcomponente = $cotcomponente;
        return $this;
    }

    public function getContacto(): ?MaestroContacto { return $this->contacto; }
    public function setContacto(?MaestroContacto $contacto): self
    {
        $this->contacto = $contacto;
        return $this;
    }

    public function getHorarecojoinicial(): ?DateTimeInterface { return $this->horarecojoinicial; }
    public function setHorarecojoinicial(?DateTimeInterface $horarecojoinicial): self
    {
        $this->horarecojoinicial = $horarecojoinicial;
        return $this;
    }

    public function getHorarecojofinal(): ?DateTimeInterface { return $this->horarecojofinal; }
    public function setHorarecojofinal(?DateTimeInterface $horarecojofinal): self
    {
        $this->horarecojofinal = $horarecojofinal;
        return $this;
    }

    public function getTolerancia(): ?int { return $this->tolerancia; }
    public function setTolerancia(?int $tolerancia): self
    {
        $this->tolerancia = $tolerancia;
        return $this;
    }

    public function getNotas(): ?string { return $this->notas; }
    public function setNotas(?string $notas): self
    {
        $this->notas = $notas;
        return $this;
    }

    public function getCreado(): ?DateTimeInterface { return $this->creado; }
    public function getModificado(): ?DateTimeInterface { return $this->modificado; }
}
