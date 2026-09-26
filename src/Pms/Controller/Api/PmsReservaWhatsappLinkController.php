<?php

declare(strict_types=1);

namespace App\Pms\Controller\Api;

use App\Message\Entity\MessageTemplate;
use App\Message\Service\Formato\HidratadorDeMarcadores;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Message\PmsMessageDataResolver;
use App\Pms\Service\Message\TelefonoDeContacto;
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
        private readonly TelefonoDeContacto $telefonos,
        private readonly HidratadorDeMarcadores $hidratador,
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
            $internalLang = strtolower((string) $idiomaEntity->getId());
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

        // ⚠️ **Con el idioma del CUERPO que se acaba de elegir**, no sin idioma.
        //
        // Sin él, las variables redactadas —`estancias`, `bloque_pago`…— salían vacías y el
        // huésped recibía «Tu reserva:» seguido de un hueco: pasó de verdad con «Detalle de pago»
        // enviada a mano (17/09/2026). Hoy el resolver ya no devuelve medio diccionario —cae al
        // idioma de la reserva—, pero pasarlo aquí sigue siendo lo correcto: el cuerpo puede estar
        // en inglés porque el del huésped no está entre los siete, y entonces sus bloques tienen
        // que ir en inglés también, no en el suyo.
        $variables = $this->messageDataResolver->getMessageVariables((string) $reserva->getId(), $templateLang);

        // El mismo hidratador que el envío de verdad: una sola regla para los marcadores —lo que
        // existe y vale vacío desaparece, lo que no existe se queda a la vista— en vez de una
        // segunda copia que se desincroniza.
        $textoFinal = $this->hidratador->hidratar($cuerpoPlantilla, $variables);

        $telefonoLimpio = preg_replace('/[^0-9]/', '', $this->telefonos->para($reserva) ?? '');
        if (empty($telefonoLimpio)) {
            throw new UnprocessableEntityHttpException('Esta reserva no tiene un número de teléfono válido.');
        }

        return new JsonResponse([
            'telefono' => $telefonoLimpio,
            'texto' => $textoFinal,
        ]);
    }
}
