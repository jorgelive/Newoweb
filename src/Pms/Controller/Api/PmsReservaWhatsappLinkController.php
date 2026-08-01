<?php

declare(strict_types=1);

namespace App\Pms\Controller\Api;

use App\Message\Entity\MessageTemplate;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Message\PmsMessageDataResolver;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Resuelve el texto final (con variables reemplazadas) de una plantilla de WhatsApp
 * "link" (api.whatsapp.com) para una reserva, en JSON — para que el frontend arme
 * la URL de WhatsApp y haga window.open() del lado del cliente.
 *
 * Reemplaza al antiguo PmsReservaCrudController::generarWhatsappUrl(), que devolvía
 * un 302 Redirect con el mensaje completo embebido en la URL: con plantillas largas
 * esa URL (y por tanto la cabecera `Location`) podía superar los límites del
 * servidor/proxy y la petición colapsaba. Aquí solo se devuelve JSON.
 */
#[Route('/pms/reservas')]
final class PmsReservaWhatsappLinkController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PmsMessageDataResolver $messageDataResolver,
    ) {}

    #[Route('/{id}/whatsapp-link/{templateId}', name: 'app_pms_reserva_whatsapp_link', methods: ['GET'])]
    public function __invoke(string $id, string $templateId): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::RESERVAS_SHOW);

        $reserva = $this->entityManager->getRepository(PmsReserva::class)->find($id);
        if (!$reserva instanceof PmsReserva) {
            throw new NotFoundHttpException('Reserva no encontrada.');
        }

        $template = $this->entityManager->getRepository(MessageTemplate::class)->find($templateId);
        if (!$template instanceof MessageTemplate) {
            throw new NotFoundHttpException('Plantilla no encontrada.');
        }

        // Misma bifurcación de idioma que el flujo legacy de EasyAdmin.
        $idiomaEntity = $reserva->getIdioma();
        $templateLang = 'es';
        if ($idiomaEntity !== null) {
            $internalLang = strtolower($idiomaEntity->getId());
            $templateLang = ($idiomaEntity->getPrioridad() > 0) ? $internalLang : 'en';
        }

        $cuerpoPlantilla = $template->getWhatsappLinkBody($templateLang);
        if (!$cuerpoPlantilla) {
            throw new UnprocessableEntityHttpException(sprintf(
                'La plantilla "%s" no tiene traducción disponible para el idioma seleccionado (%s).',
                $template->getName(),
                strtoupper($templateLang)
            ));
        }

        $variables = $this->messageDataResolver->getMessageVariables((string) $reserva->getId());

        $replacePairs = [];
        foreach ($variables as $key => $value) {
            $replacePairs['{{ ' . $key . ' }}'] = (string) $value;
            $replacePairs['{{' . $key . '}}'] = (string) $value;
        }

        $textoFinal = strtr($cuerpoPlantilla, $replacePairs);

        $telefonoLimpio = preg_replace('/[^0-9]/', '', $reserva->getTelefono() ?? $reserva->getTelefono2() ?? '');
        if (empty($telefonoLimpio)) {
            throw new UnprocessableEntityHttpException('Esta reserva no tiene un número de teléfono válido.');
        }

        return new JsonResponse([
            'telefono' => $telefonoLimpio,
            'texto' => $textoFinal,
        ]);
    }
}
