<?php

declare(strict_types=1);

namespace App\Pms\Controller\Api;

use App\Pms\Entity\PmsReserva;
use App\Pms\Guia\PmsGuiaThrottle;
use App\Pms\Service\Message\TelefonoDelHuesped;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /platform/client/pax/pms/pms_reserva/{localizador}/telefono — `{"telefono": "+33 6 …"}`.
 *
 * El huésped deja su WhatsApp desde su página. Público: la credencial es el localizador, como en
 * el resto de la página, y por eso pasa por el mismo freno que la guía (`PmsGuiaThrottle`): sin él,
 * seis caracteres se recorren en minutos. Las reglas están en {@see TelefonoDelHuesped}.
 */
#[AsController]
final class PmsReservaTelefonoHuespedController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelefonoDelHuesped $telefono,
        private readonly PmsGuiaThrottle $throttle,
    ) {}

    #[Route('/platform/client/pax/pms/pms_reserva/{localizador}/telefono', name: 'pax_reserva_telefono', methods: ['POST'])]
    public function __invoke(string $localizador, Request $request): JsonResponse
    {
        $this->throttle->asegurarPresupuesto();

        $reserva = $this->em->getRepository(PmsReserva::class)->findOneBy(['localizador' => $localizador]);

        if ($reserva === null) {
            $this->throttle->registrarFallo();

            return new JsonResponse(['resultado' => 'no_encontrada'], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $cuerpo */
        $cuerpo = json_decode((string) $request->getContent(), true) ?: [];
        $tecleado = is_string($cuerpo['telefono'] ?? null) ? $cuerpo['telefono'] : '';

        $resultado = $this->telefono->guardar($reserva, $tecleado);

        return new JsonResponse(['resultado' => $resultado], match ($resultado) {
            TelefonoDelHuesped::GUARDADO => Response::HTTP_OK,
            TelefonoDelHuesped::INVALIDO => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_CONFLICT,
        });
    }
}
