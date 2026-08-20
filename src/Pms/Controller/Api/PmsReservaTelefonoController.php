<?php

declare(strict_types=1);

namespace App\Pms\Controller\Api;

use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Message\TelefonoDeContacto;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * El número al que se le escribe a una reserva.
 *
 * ── Por qué un endpoint y no un campo del recurso ───────────────────────────
 * Porque el número **no está en la reserva**: sale de las identidades de la persona, y llegar a
 * ellas es `reserva → enlace titular → hilo → identidad principal`. Serializarlo en
 * `PmsReserva` metería ese recorrido en cada fila de cada listado del calendario.
 *
 * Se pide cuando hace falta —al abrir el cajón de la reserva o su menú—, que es una vez por
 * interacción y no cientos por pantalla.
 *
 * `origen` dice de dónde salió, y el panel lo usa para avisar: `semilla` significa que la
 * persona todavía no tiene identidad y se está mostrando el dato con el que se creó la reserva,
 * sin verificar.
 */
#[Route('/pms/reservas', name: 'app_pms_reserva_telefono_')]
final class PmsReservaTelefonoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelefonoDeContacto $telefonos,
    ) {}

    #[Route('/{id}/telefono-contacto', name: 'contacto', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::RESERVAS_SHOW);

        $reserva = $this->em->getRepository(PmsReserva::class)->find($id);

        if ($reserva === null) {
            return new JsonResponse(['error' => 'Reserva no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $telefono = $this->telefonos->para($reserva);

        return new JsonResponse([
            'telefono' => $telefono,
            // Lo dice el resolutor, no una comparación de valores: lo normal es que la
            // identidad y la semilla coincidan —aquélla se sembró de ésta—, y compararlas
            // diría «semilla» justo cuando sí hay identidad.
            'origen' => $telefono === null
                ? null
                : ($this->telefonos->vieneDeIdentidad($reserva) ? 'identidad' : 'semilla'),
        ]);
    }
}
