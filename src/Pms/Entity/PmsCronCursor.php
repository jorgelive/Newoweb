<?php

namespace App\Pms\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'pms_cron_cursor')]
#[ORM\Entity]
class PmsCronCursor
{
    #[ORM\Id]
    #[ORM\Column(length: 100)]
    private ?string $jobName = null;

    /**
     * Fecha "puntero".
     * Al llamarse la propiedad $cursorDate, Doctrine creará la columna 'cursor_date'.
     */
    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $cursorDate = null;

    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $lastRunAt = null;

    public function __construct(string $jobName)
    {
        $this->jobName = $jobName;
        $this->lastRunAt = new DateTimeImmutable();
        $this->cursorDate = new DateTimeImmutable('today');
    }

    public function getJobName(): ?string
    {
        return $this->jobName;
    }

    public function getCursorDate(): ?DateTimeInterface
    {
        return $this->cursorDate;
    }

    public function setCursorDate(DateTimeInterface $cursorDate): self
    {
        $this->cursorDate = $cursorDate;
        return $this;
    }

    public function getLastRunAt(): ?DateTimeInterface
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(DateTimeInterface $lastRunAt): self
    {
        $this->lastRunAt = $lastRunAt;
        return $this;
    }
}