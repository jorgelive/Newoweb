<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Contract\AsuntosSinTelefonoInterface;
use App\Message\Dto\AsuntoSinTelefono;
use App\Security\Roles;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /platform/message/asuntos-sin-telefono — el reporte del portal.
 *
 * Booking dejó de pasar el teléfono del huésped (octubre de 2026): sus reservas llegan sin número
 * y sólo se les puede escribir por la mensajería de Beds24, con su vuelta de ~6 minutos y sin
 * botones. Esto lista lo vigente a lo que hoy no le sale un WhatsApp, para pedir el número y
 * apuntarlo. Ver `docs/Mensajeria.md`, «Reservas y cotizaciones sin teléfono».
 *
 * No sabe de reservas ni de expedientes: cada dominio aporta lo suyo por
 * {@see AsuntosSinTelefonoInterface}.
 */
#[AsController]
final class AsuntosSinTelefonoController extends AbstractController
{
    /** @param iterable<AsuntosSinTelefonoInterface> $dominios */
    public function __construct(
        #[AutowireIterator('app.message.asuntos_sin_telefono')]
        private readonly iterable $dominios,
    ) {}

    #[Route('/platform/message/asuntos-sin-telefono', name: 'app_message_asuntos_sin_telefono', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_SHOW, null, 'Acceso denegado a las conversaciones.');

        $hoy = new DateTimeImmutable('today');
        $asuntos = [];

        foreach ($this->dominios as $dominio) {
            foreach ($dominio->sinTelefono($hoy) as $asunto) {
                $asuntos[] = $asunto->aArray();
            }
        }

        return $this->json(['asuntos' => $asuntos]);
    }
}
