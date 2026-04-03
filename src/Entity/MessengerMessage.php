<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

/**
 * Entidad de solo lectura para mapear la tabla nativa de Symfony Messenger.
 * * ¿Por qué existe?: Permite auditar, visualizar y purgar mensajes en cola
 * directamente desde EasyAdmin.
 */
#[ORM\Entity]
#[ORM\Table(name: 'messenger_messages')]
#[ORM\Index(columns: ['queue_name', 'available_at', 'delivered_at'], name: 'IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750')]
class MessengerMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    #[ORM\Column(type: 'text')]
    private ?string $body = null;

    #[ORM\Column(type: 'text')]
    private ?string $headers = null;

    #[ORM\Column(type: 'string', length: 190)]
    private ?string $queueName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $availableAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $deliveredAt = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getHeaders(): ?string
    {
        return $this->headers;
    }

    public function getQueueName(): ?string
    {
        return $this->queueName;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAvailableAt(): ?DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function getDeliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }
}