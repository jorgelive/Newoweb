<?php

declare(strict_types=1);

namespace App\Pms\Controller\Api;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Reserva\SolapesDeEstancia;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Con quién chocaría una estancia, ANTES de guardarla: lo que el editor enseña arriba en cuanto se
 * eligen casita y fechas.
 *
 * Hasta el 20/10/2026 el choque sólo salía al pulsar Guardar, como rechazo y junto a los botones.
 * Ahora se avisa antes y se puede guardar igual desde el editor (`PmsEventoCalendario::$solapeAceptado`).
 *
 * La regla es la del candado, {@see SolapesDeEstancia}: se monta en memoria una estancia con lo que
 * hay en el formulario —nunca se guarda— y se le pregunta. Lo de la misma reserva no choca, así que
 * al editar basta con pasar la reserva para que la estancia no choque consigo misma.
 *
 * ```
 * GET /pms/estancias/solapes?unidad=…&inicio=2026-10-22T14:00:00&fin=2026-11-04T10:00:00
 *     &estado=confirmada&entradaTemprana=0&salidaTardia=0&reserva=…
 * → {"choques": ["Casita 4 ya está ocupada del 25/10 al 31/10 por Idalith Mp. …"]}
 * ```
 */
final class PmsSolapesDeEstanciaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SolapesDeEstancia $solapes,
    ) {}

    #[Route('/pms/estancias/solapes', name: 'app_pms_estancia_solapes', methods: ['GET'])]
    public function __invoke(Request $peticion): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::RESERVAS_SHOW);

        $unidadId = (string) $peticion->query->get('unidad', '');
        $estadoId = (string) $peticion->query->get('estado', '');
        $reservaId = (string) $peticion->query->get('reserva', '');

        $unidad = Uuid::isValid($unidadId) ? $this->em->getRepository(PmsUnidad::class)->find(Uuid::fromString($unidadId)) : null;
        $estado = $estadoId !== '' ? $this->em->getRepository(PmsEventoEstado::class)->find($estadoId) : null;
        $inicio = self::fecha((string) $peticion->query->get('inicio', ''));
        $fin = self::fecha((string) $peticion->query->get('fin', ''));

        // A medio rellenar no hay nada que avisar: el editor pregunta en cuanto cambia un campo.
        if ($unidad === null || $estado === null || $inicio === null || $fin === null) {
            return new JsonResponse(['choques' => []]);
        }

        $estancia = (new PmsEventoCalendario())
            ->setPmsUnidad($unidad)
            ->setEstado($estado)
            ->setInicio($inicio)
            ->setFin($fin)
            ->setEntradaTemprana($peticion->query->getBoolean('entradaTemprana'))
            ->setSalidaTardia($peticion->query->getBoolean('salidaTardia'));

        if (Uuid::isValid($reservaId)) {
            $estancia->setReserva($this->em->getReference(PmsReserva::class, Uuid::fromString($reservaId)));
        }

        return new JsonResponse(['choques' => $this->solapes->de($estancia)]);
    }

    private static function fecha(string $valor): ?\DateTimeImmutable
    {
        if ($valor === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($valor);
        } catch (\Exception) {
            return null;
        }
    }
}
