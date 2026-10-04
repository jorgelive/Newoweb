<?php

declare(strict_types=1);

namespace App\Pms\Controller\Api;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Buscador de reservas del calendario SPA: texto libre -> estancias.
 *
 * Devuelve ESTANCIAS (PmsEventoCalendario), no reservas: una reserva de dos
 * casitas tiene dos estancias con fechas y unidad propias, y lo que el usuario
 * necesita para saltar en el calendario es justamente esa fila concreta.
 *
 * El calendario ya carga por rango de fechas (ver PmsEventosSpaCalendarProvider),
 * así que no hay forma de encontrar una reserva de otro mes sin salir a buscarla:
 * este endpoint es esa salida, y por eso NO filtra por rango.
 */
#[Route('/pms/reservas')]
final class PmsReservaBuscarController extends AbstractController
{
    /** Suficiente para elegir a ojo sin convertir el desplegable en un listado. */
    private const LIMITE = 25;

    /** Con menos caracteres la búsqueda devuelve medio PMS y no ayuda a nadie. */
    private const MIN_CARACTERES = 2;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/buscar', name: 'app_pms_reserva_buscar', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::RESERVAS_SHOW);

        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < self::MIN_CARACTERES) {
            return new JsonResponse([]);
        }

        return new JsonResponse(array_map(
            $this->serializar(...),
            $this->buscar($q),
        ));
    }

    /**
     * Las estancias de estos días, primero las de quien comparte prefijo con un teléfono.
     *
     * Para enlazar al hilo de un número desconocido la reserva de la que habla. Carla escribió
     * «les escribo sobre mi reserva» desde un +55 85 sin decir de quién era (12/09/2026), y Jorge
     * dio con ella porque la única huésped brasileña alojada, Bruna, era también +55 85. Eso es
     * lo que se ordena aquí: alojadas o llegando en ±2 días, y arriba las del mismo país y zona.
     * El buscador de texto de arriba sigue sirviendo para lo que no salga.
     */
    #[Route('/candidatas', name: 'app_pms_reserva_candidatas', methods: ['GET'])]
    public function candidatas(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::RESERVAS_SHOW);

        $telefono = preg_replace('/\D/', '', (string) $request->query->get('telefono', '')) ?? '';
        $hoy = new \DateTimeImmutable('today');

        /** @var list<PmsEventoCalendario> $estancias */
        $estancias = $this->entityManager->createQueryBuilder()
            ->select('e, u, r, es, ep, c')
            ->from(PmsEventoCalendario::class, 'e')
            ->innerJoin('e.reserva', 'r')
            ->leftJoin('e.pmsUnidad', 'u')
            ->leftJoin('e.estado', 'es')
            ->leftJoin('e.estadoPago', 'ep')
            ->leftJoin('e.channel', 'c')
            ->where('e.inicio <= :hasta')
            ->andWhere('e.fin >= :desde')
            ->andWhere('es.id NOT IN (:fuera)')
            ->setParameter('hasta', $hoy->modify('+3 days'))
            ->setParameter('desde', $hoy->modify('-1 day'))
            ->setParameter('fuera', [PmsEventoEstado::CODIGO_CANCELADA, PmsEventoEstado::CODIGO_BLOQUEO])
            ->getQuery()
            ->getResult();

        $puntuadas = array_map(
            fn (PmsEventoCalendario $e): array => [$e, $this->prefijoComun($telefono, (string) $e->getReserva()?->getTelefono())],
            $estancias,
        );

        // Más prefijo en común primero; a igualdad, la que más cerca está de hoy.
        usort($puntuadas, static function (array $a, array $b) use ($hoy): int {
            return [$b[1], abs((int) $a[0]->getInicio()?->diff($hoy)->format('%r%a'))]
                <=> [$a[1], abs((int) $b[0]->getInicio()?->diff($hoy)->format('%r%a'))];
        });

        return new JsonResponse(array_map(
            fn (array $p): array => $this->serializar($p[0]) + ['mismoPrefijo' => $p[1] >= 4],
            array_slice($puntuadas, 0, 8),
        ));
    }

    /**
     * Dígitos iniciales en común entre dos teléfonos, hasta 4: el código de país y el de zona
     * (+55 85 Fortaleza, +51 98 móvil peruano). Más allá ya es casualidad.
     */
    private function prefijoComun(string $a, string $b): int
    {
        $b = preg_replace('/\D/', '', $b) ?? '';
        $n = 0;

        while ($n < 4 && isset($a[$n], $b[$n]) && $a[$n] === $b[$n]) {
            ++$n;
        }

        return $n;
    }

    /**
     * @return PmsEventoCalendario[]
     */
    private function buscar(string $q): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e, u, r, es, ep, c')
            ->from(PmsEventoCalendario::class, 'e')
            ->innerJoin('e.reserva', 'r')
            ->leftJoin('e.pmsUnidad', 'u')
            ->leftJoin('e.estado', 'es')
            ->leftJoin('e.estadoPago', 'ep')
            ->leftJoin('e.channel', 'c')
            // Lo primero que uno busca es la reserva de estos días, no la de hace
            // dos años: se ordena por cercanía a hoy, hacia adelante o hacia atrás.
            ->orderBy('ABS(DATE_DIFF(e.inicio, CURRENT_DATE()))', 'ASC')
            ->setMaxResults(self::LIMITE);

        // Cada palabra debe aparecer en algún campo (AND entre términos): así
        // "juan perez" encuentra al huésped aunque nombre y apellido estén en
        // columnas distintas, y "perez casita" no devuelve a todos los Pérez.
        foreach (preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $termino) {
            $qb->andWhere($qb->expr()->orX(
                "LOWER(CONCAT(COALESCE(r.nombreCliente, ''), ' ', COALESCE(r.apellidoCliente, ''))) LIKE :t$i",
                "LOWER(COALESCE(r.localizador, '')) LIKE :t$i",
                "LOWER(COALESCE(e.referenciaCanal, '')) LIKE :t$i",
                "LOWER(COALESCE(u.nombre, '')) LIKE :t$i",
            ))->setParameter("t$i", '%' . mb_strtolower($termino) . '%');
        }

        /** @var array<\App\Pms\Entity\PmsEventoCalendario> $resultado */
        $resultado = $qb->getQuery()->getResult();

        return $resultado;
    }

    /**
     * @return array<string,mixed>
     */
    private function serializar(PmsEventoCalendario $evento): array
    {
        $reserva = $evento->getReserva();
        $estado = $evento->getEstado();
        $estadoPago = $evento->getEstadoPago();

        return [
            'eventoId' => (string) $evento->getId(),
            'reservaId' => $reserva?->getId() ? (string) $reserva->getId() : null,
            'cliente' => $reserva?->getNombreApellido(),
            'localizador' => $reserva?->getLocalizador(),
            'unidad' => $evento->getPmsUnidad() ? (string) $evento->getPmsUnidad() : null,
            'unidadId' => $evento->getPmsUnidad()?->getId() ? (string) $evento->getPmsUnidad()->getId() : null,
            // Solo la fecha: es lo que el frontend necesita para posicionar el
            // calendario y para mostrar el rango (las horas son de check-in/out).
            'inicio' => $evento->getInicio()?->format('Y-m-d'),
            'fin' => $evento->getFin()?->format('Y-m-d'),
            'noches' => $evento->getNoches(),
            'pax' => $evento->getCantidadAdultos() + $evento->getCantidadNinos(),
            'estado' => $estado?->getNombre(),
            // El id (código natural) lo usa el frontend para detectar canceladas:
            // el calendario "No canceladas" las oculta y el salto quedaría en una
            // fila vacía si no se cambia de vista.
            'estadoId' => $estado?->getId(),
            // Mismo criterio de color que el calendario (ver resolveColor en
            // PmsEventosSpaCalendarProvider): el pago puede pisar al estado.
            'color' => $estadoPago?->isColorOverride() ? $estadoPago->getColor() : $estado?->getColor(),
            'estadoPago' => $estadoPago?->getNombre(),
            'canal' => $evento->getChannel()?->getId(),
            'isOta' => $evento->isOta(),
        ];
    }
}
