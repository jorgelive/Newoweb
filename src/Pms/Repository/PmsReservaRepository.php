<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Service\Phone\PhoneSanitizer;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositorio para la gestión de la entidad PmsReserva.
 */
class PmsReservaRepository extends ServiceEntityRepository
{
    /**
     * Inicializa el repositorio con el registro de Doctrine.
     *
     * @param ManagerRegistry $registry El registro de gestores de entidades.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsReserva::class);
    }

    /**
     * Busca una reserva coincidiendo con el Master ID o con cualquiera
     * de los Book IDs de sus eventos (habitaciones) vinculados.
     */
    public function findByAnyBeds24Id(string $beds24Id): ?PmsReserva
    {
        $qb = $this->createQueryBuilder('r');

        return $qb
            ->leftJoin('r.eventosCalendario', 'e')
            ->leftJoin('e.beds24Links', 'l')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('r.beds24MasterId', ':bookId'),
                    $qb->expr()->eq('l.beds24BookId', ':bookId')
                )
            )
            ->setParameter('bookId', $beds24Id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Días tras el check-out en que un número sigue apuntando a esa reserva.
     *
     * Las dudas no se acaban al salir: el cobro, la factura o un olvido en la casita llegan
     * después. Una semana cubre eso sin resucitar estancias viejas.
     */
    private const int DIAS_GRACIA_TRAS_SALIDA = 7;

    /**
     * Cuánta antelación cuenta como «su próxima reserva».
     *
     * Se pregunta por la llegada, el transporte o el check-in con semanas de margen. Más allá
     * de dos meses, un número suelto es más probable que sea de otra cosa.
     */
    private const int DIAS_ANTICIPACION = 60;

    /**
     * Reservas VIVAS a las que puede pertenecer un número, **de la más pertinente a la menos**.
     *
     * Sirve para identificar a quien escribe por WhatsApp: el remitente es sólo un número, y de
     * aquí sale a qué reserva atar la conversación (y por tanto qué puede consultar el huésped).
     *
     * ### Qué reserva cuenta
     *
     * Se exige al menos un evento en {@see PmsEventoEstado::IDENTIFICAN_HUESPED} — pendiente,
     * confirmada o requerimiento—. Eso descarta de un golpe los tres casos que no son un huésped
     * al que debamos contarle nada: la reserva con **todos los eventos cancelados**, el
     * **bloqueo** (casita cerrada, no hay huésped) y el **abierto** (un inquiry de Airbnb es una
     * consulta, no una reserva). Las **extensiones** tampoco cuentan por sí solas: son la noche
     * fantasma de un horario extra (§7.1.b), y su reserva ya entra por el tramo que las generó.
     *
     * ### En qué orden
     *
     * ```
     * 1. La ACTUAL      — un tramo en curso hoy (inicio <= hoy <= fin). Si hay varias, la de
     *                     entrada más reciente: es la que el huésped está viviendo.
     * 2. La PRÓXIMA     — sin ninguna en curso, la de entrada más cercana.
     * (descartadas)     — las que acabaron hace más de DIAS_GRACIA_TRAS_SALIDA.
     * ```
     *
     * Devuelve lista y no una sola reserva para que quien llama pueda ver si hubo empate: un
     * mismo número puede tener varias vivas —en producción hay uno con 7 de 2 personas
     * distintas, porque aquí también se opera como agencia y un titular reserva para terceros—.
     * El orden decide, pero la lista deja constancia de que había más de una.
     *
     * Se busca en los DOS teléfonos de la reserva (`telefono` y `telefono2`) sin mirar
     * `telefono2EsPrincipal`: ese flag dice a cuál escribimos NOSOTROS, no desde cuál escribe él.
     *
     * El criterio de coincidencia (exacto o los últimos 8 dígitos) es el mismo que ya usa
     * `WhatsappMetaReceivePersister::resolveConversation()` para las conversaciones, y por el
     * mismo motivo: los números que llegan de las OTA vienen sucios, a veces con el código de
     * país duplicado.
     *
     * @return list<PmsReserva> Vacía si no hay ninguna; la primera es la que toca.
     */
    public function findVivasByTelefono(?string $telefono, PhoneSanitizer $sanitizer): array
    {
        $telefono = trim((string) $telefono);
        if ($telefono === '') {
            return [];
        }

        $limpio = $sanitizer->cleanPhoneNumber($telefono, 'PE');
        if ($limpio === '') {
            return [];
        }

        $hoy = new DateTimeImmutable('today');
        $corte = $hoy->modify(sprintf('-%d days', self::DIAS_GRACIA_TRAS_SALIDA));

        $qb = $this->createQueryBuilder('r');
        $coincide = $qb->expr()->orX(
            $qb->expr()->eq('r.telefono', ':exacto'),
            $qb->expr()->eq('r.telefono2', ':exacto'),
        );

        // Con menos de 9 dígitos sólo coincidencia exacta: un sufijo corto engancharía
        // números que no tienen nada que ver.
        if (strlen($limpio) >= 9) {
            $coincide->add($qb->expr()->like('r.telefono', ':cola'));
            $coincide->add($qb->expr()->like('r.telefono2', ':cola'));
            $qb->setParameter('cola', '%' . substr($limpio, -8));
        }

        /** @var list<PmsReserva> $candidatas */
        $candidatas = $qb
            // El filtro va sobre los EVENTOS, no sobre `fechaLlegada`/`fechaSalida` de la
            // reserva: esos dos son agregados (mín/máx de sus tramos) y una reserva con un
            // tramo cancelado y otro vivo daría una ventana que no corresponde a nada real.
            ->innerJoin('r.eventosCalendario', 'e')
            ->innerJoin('e.estado', 'est')
            ->where($coincide)
            ->andWhere('est.id IN (:estadosVivos)')
            // Fuera las extensiones: no son estancias, son la noche que bloquea un horario extra.
            ->andWhere('e.eventoOrigen IS NULL')
            ->andWhere('e.fin >= :corte')
            ->andWhere('e.inicio <= :hasta')
            ->setParameter('exacto', $limpio)
            ->setParameter('estadosVivos', PmsEventoEstado::IDENTIFICAN_HUESPED)
            ->setParameter('corte', $corte)
            ->setParameter('hasta', $hoy->modify(sprintf('+%d days', self::DIAS_ANTICIPACION)))
            ->distinct()
            ->getQuery()
            ->getResult();

        // El orden se resuelve en PHP y no en el ORDER BY: hace falta el tramo relevante de
        // cada reserva (el que está en curso, o el próximo), y con el JOIN de arriba una
        // reserva de varias casitas trae varias filas — ordenar en SQL mezclaría los tramos.
        usort($candidatas, function (PmsReserva $a, PmsReserva $b) use ($hoy, $corte): int {
            $ta = $this->tramoRelevante($a, $hoy, $corte);
            $tb = $this->tramoRelevante($b, $hoy, $corte);

            // En curso antes que futura.
            if ($ta['enCurso'] !== $tb['enCurso']) {
                return $ta['enCurso'] ? -1 : 1;
            }

            // Entre dos en curso, la que entró más tarde (la que está viviendo ahora).
            // Entre dos futuras, la que llega antes.
            return $ta['enCurso']
                ? $tb['inicio'] <=> $ta['inicio']
                : $ta['inicio'] <=> $tb['inicio'];
        });

        return array_values($candidatas);
    }

    /**
     * El tramo de la reserva por el que se la juzga: el que está en curso, o si no el próximo.
     *
     * Se recalcula sobre los eventos ya cargados —el JOIN de arriba los trajo— aplicando los
     * MISMOS filtros que la consulta. Sin repetirlos, una reserva con un tramo cancelado
     * antiguo y otro confirmado futuro se ordenaría por el cancelado.
     *
     * @return array{enCurso: bool, inicio: DateTimeInterface}
     */
    private function tramoRelevante(PmsReserva $reserva, DateTimeImmutable $hoy, DateTimeImmutable $corte): array
    {
        $mejor = null;
        $mejorEnCurso = false;

        foreach ($reserva->getEventosCalendario() as $evento) {
            if ($evento->getEventoOrigen() !== null
                || !in_array($evento->getEstado()?->getId(), PmsEventoEstado::IDENTIFICAN_HUESPED, true)
            ) {
                continue;
            }

            $inicio = $evento->getInicio();
            $fin = $evento->getFin();

            if ($inicio === null || $fin === null || $fin < $corte) {
                continue;
            }

            $enCurso = $inicio <= $hoy && $fin >= $hoy;

            // Un tramo en curso desplaza a cualquier futuro. Entre dos del mismo tipo, el que
            // empieza antes marca a la reserva.
            if ($mejor === null
                || ($enCurso && !$mejorEnCurso)
                || ($enCurso === $mejorEnCurso && $inicio < $mejor)
            ) {
                $mejor = $inicio;
                $mejorEnCurso = $enCurso;
            }
        }

        return ['enCurso' => $mejorEnCurso, 'inicio' => $mejor ?? $hoy];
    }

}