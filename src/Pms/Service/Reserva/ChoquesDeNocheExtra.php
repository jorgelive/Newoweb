<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Dto\ChoqueDeNocheExtra;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * ¿La estancia que acaba de cambiar el canal choca con una noche extra?
 *
 * En los dos sentidos:
 *
 * 1. **Sus noches caen sobre la noche extra de otro.** Booking mueve la reserva de Juan a la noche
 *    en que Anna tenía la entrada temprana. Beds24 lo debería impedir —la `black` cierra esa
 *    noche—, pero un cambio aceptado a mano en el canal o un desfase de sincronización lo cuelan.
 * 2. **Su noche extra cae sobre otro.** Booking cambia las fechas de Anna, que tiene entrada
 *    temprana: el canal sólo comprueba SUS noches, y su víspera nueva puede ser de Juan. Nuestra
 *    `black` la sigue igual (fase 2), y Beds24 la acepta encima.
 *
 * Ninguno se puede rechazar —el canal ya lo cambió—: hay que avisar a quien puede reubicar a uno
 * de los dos (fase 5 de docs/PlanHorarioExtraSinEventos.md). La regla de qué es «pisar» vive en
 * `PmsEventoCalendario::nocheExtraPisadaPor()`, la misma que pinta el calendario.
 */
final readonly class ChoquesDeNocheExtra
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /** @return list<ChoqueDeNocheExtra> */
    public function de(PmsEventoCalendario $estancia): array
    {
        $unidad = $estancia->getPmsUnidad();
        $inicio = $estancia->getInicio();
        $fin = $estancia->getFin();

        if ($unidad === null || $inicio === null || $fin === null
            || !in_array($estancia->getEstado()?->getId(), PmsEventoEstado::OCUPAN_UNIDAD, true)
        ) {
            return [];
        }

        // Dos días de margen por cada lado: el vecino puede alcanzarla con su noche extra, y ella
        // al vecino con la suya.
        /** @var list<PmsEventoCalendario> $vecinas */
        $vecinas = $this->em->createQueryBuilder()
            ->select('e')
            ->from(PmsEventoCalendario::class, 'e')
            ->where('e.pmsUnidad = :unidad')
            ->andWhere('IDENTITY(e.estado) IN (:vivas)')
            ->andWhere('e.inicio < :hasta AND e.fin > :desde')
            // ⚠️ Con tipo `uuid`: el id es BINARY(16) y sin él la consulta no encuentra nada.
            ->setParameter('unidad', $unidad->getId(), UuidType::NAME)
            ->setParameter('vivas', PmsEventoEstado::OCUPAN_UNIDAD)
            ->setParameter('desde', DateTimeImmutable::createFromInterface($inicio)->setTime(0, 0)->modify('-2 day'))
            ->setParameter('hasta', DateTimeImmutable::createFromInterface($fin)->setTime(0, 0)->modify('+2 day'))
            ->getQuery()
            ->getResult();

        $choques = [];
        foreach ($vecinas as $vecina) {
            $suya = $vecina->nocheExtraPisadaPor($estancia);
            if ($suya !== null) {
                $choques[] = new ChoqueDeNocheExtra($suya, duenio: $vecina, otra: $estancia, movida: $estancia);
            }

            $propia = $estancia->nocheExtraPisadaPor($vecina);
            if ($propia !== null) {
                $choques[] = new ChoqueDeNocheExtra($propia, duenio: $estancia, otra: $vecina, movida: $estancia);
            }
        }

        return $choques;
    }
}
