<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'msg_channel')]
#[UniqueEntity('id')]
#[ORM\HasLifecycleCallbacks]
class MessageChannel
{
    use TimestampTrait;

    // ID NATURAL = PROVEEDOR (ej: 'whatsapp_gupshup', 'beds24', 'email_marketing')
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'El ID solo puede contener letras minúsculas, números y guiones bajos.')]
    private ?string $id = null;

    // Nombre editable en EasyAdmin (con lógica de limpieza en el Setter)
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $name = null;

    // Configuración dinámica: ¿Qué campo de la plantilla lee este canal?
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    // Nota: Assert\Choice requiere que MessageTemplate::getTemplateFields sea estático y público
    #[Assert\Choice(callback: [MessageTemplate::class, 'getTemplateFields'])]
    private ?string $templateColumn = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    // Constructor para forzar el ID Natural al crear
    public function __construct()
    {

    }

    public function __toString(): string
    {
        return $this->name ?? $this->id;
    }

    // =========================================================================
    // GETTERS Y SETTERS EXPLÍCITOS
    // =========================================================================

    // Necesario para que EasyAdmin pueda inyectar el ID desde el formulario
    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    // Nota: No ponemos setId porque es un ID natural inmutable definido en el constructor.

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        // Lógica que antes estaba en el Hook
        $this->name = trim($name);
        return $this;
    }

    public function getTemplateColumn(): ?string
    {
        return $this->templateColumn;
    }

    public function setTemplateColumn(string $templateColumn): self
    {
        $this->templateColumn = $templateColumn;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }
}