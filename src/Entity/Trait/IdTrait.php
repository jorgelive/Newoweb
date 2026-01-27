<?php
declare(strict_types=1);

namespace App\Entity\Trait;

use App\Doctrine\IdGenerator\UuidV7Generator;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

trait IdTrait
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true, options: ['fixed' => true])]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidV7Generator::class)]
    private ?Uuid $id = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /**
     * Útil cuando necesitas el ID antes del flush (colas, dedupe, etc.).
     */
    public function initializeId(): void
    {
        if ($this->id === null) {
            $this->id = new UuidV7();
        }
    }
}