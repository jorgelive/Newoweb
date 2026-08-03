<?php

declare(strict_types=1);

namespace App\Pms\Factory;

use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crea o recupera (Upsert) la cabecera financiera de una reserva.
 * Espejo de App\Message\Factory\MessageConversationFactory: garantiza una única
 * PmsInformacionFinanciera por PmsReserva.
 */
readonly class PmsInformacionFinancieraFactory
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function upsertForReserva(PmsReserva $reserva, bool $flush = false): PmsInformacionFinanciera
    {
        $repo = $this->em->getRepository(PmsInformacionFinanciera::class);

        $info = $repo->findOneBy(['reserva' => $reserva]);

        if (!$info) {
            $info = new PmsInformacionFinanciera();
            $info->setReserva($reserva);
            $this->em->persist($info);
        }

        if ($flush) {
            $this->em->flush();
        }

        return $info;
    }
}
