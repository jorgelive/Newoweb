<?php

namespace App\Api\Controller\Pax;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsUnidad;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class GuiaHelperController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/pax/guiahelper/{id}', name: 'guia_helper', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        // 1. Buscar Evento (Modo Huésped)
        $evento = $this->em->getRepository(PmsEventoCalendario::class)->find($id);
        if ($evento) {
            return $this->buildResponse($evento->getPmsUnidad(), $evento);
        }

        // 2. Buscar Unidad (Modo Demo/Qr)
        $unidad = $this->em->getRepository(PmsUnidad::class)->find($id);
        if ($unidad) {
            return $this->buildResponse($unidad, null);
        }

        return new JsonResponse(['error' => 'ID no encontrado'], 404);
    }

    private function buildResponse(PmsUnidad $unidad, ?PmsEventoCalendario $evento): JsonResponse
    {
        $est = $unidad->getEstablecimiento();
        $reserva = $evento?->getReserva();

        $acceso = $this->calcularAcceso($evento);
        $esAutorizado = $acceso['authorized'];
        $mask = '********';

        // ---------------------------------------------------------
        // A. TEXT FIXED: Variables que NUNCA se traducen (Strings simples)
        // ---------------------------------------------------------
        $textFixed = [
            'guest_name'     => $reserva ? $reserva->getNombreCliente() : 'Huésped',
            'unit_name'      => $unidad->getNombre(),
            'hotel_name'     => $est?->getNombreComercial() ?? 'Hotel',
            'booking_ref'    => $reserva?->getLocalizador() ?? 'DEMO',

            'check_in'       => ($evento ? $evento->getInicio() : $est?->getHoraCheckIn())?->format('H:i'),
            'check_out'      => ($evento ? $evento->getFin() : $est?->getHoraCheckOut())?->format('H:i'),
            'start_date'     => $evento?->getInicio()->format('d/m/Y'),
            'end_date'       => $evento?->getFin()->format('d/m/Y'),

            // Códigos de acceso
            'door_code'      => $esAutorizado ? $unidad->getCodigoPuerta() : $mask,
            'safe_code'      => $esAutorizado ? $unidad->getCodigoCaja() : $mask,
            'keybox_main'    => $esAutorizado ? $est?->getCodigoCajaPrincipal() : $mask,
            'keybox_sec'     => $esAutorizado ? $est?->getCodigoCajaSecundaria() : $mask,

            // WiFi Texto (Para uso rápido en párrafos)
            'wifi_ssid'      => $unidad->getWifiNetworks()[0]['ssid'] ?? 'N/A',
            'wifi_pass'      => $esAutorizado ? ($unidad->getWifiNetworks()[0]['password'] ?? 'N/A') : $mask,
        ];

        // ---------------------------------------------------------
        // B. TEXT TRANSLATABLE: Variables que SIEMPRE son Arrays de idiomas
        // ---------------------------------------------------------
        $textTranslatable = [
            'status_msg' => $this->traducirEstado($acceso['status']),
        ];

        // ---------------------------------------------------------
        // C. WIDGETS: Datos complejos
        // ---------------------------------------------------------
        $widgets = [
            'wifi_data' => $this->prepararWifi($unidad->getWifiNetworks(), $esAutorizado)
        ];

        // ---------------------------------------------------------
        // D. CONFIG: Lógica interna
        // ---------------------------------------------------------
        $config = [
            'mode'           => $evento ? 'guest' : 'demo',
            'access_status'  => $acceso['status'],
            'is_locked'      => !$esAutorizado,
            'unit_uuid'      => $unidad->getId()->toRfc4122(), // ID crítico
        ];

        return new JsonResponse([
            'data' => [
                'text_fixed'        => $textFixed,
                'text_translatable' => $textTranslatable,
                'widgets'           => $widgets,
                'config'            => $config
            ]
        ]);
    }

    private function calcularAcceso(?PmsEventoCalendario $evento): array
    {
        if (!$evento) return ['status' => 'demo', 'authorized' => false];

        $ahora = new \DateTime();
        $inicio = (clone $evento->getInicio())->modify('-1 day')->setTime(0, 0, 0);
        $fin = (clone $evento->getFin())->setTime(23, 59, 59);

        if ($ahora < $inicio) return ['status' => 'pending', 'authorized' => false];
        if ($ahora > $fin)    return ['status' => 'expired', 'authorized' => false];

        return ['status' => 'active', 'authorized' => true];
    }

    private function prepararWifi(?array $networks, bool $autorizado): array
    {
        if (empty($networks)) return [];
        if ($autorizado) return $networks;

        return array_map(function($net) {
            return [
                'ssid' => $net['ssid'],
                'password' => '********',
                'ubicacion' => $net['ubicacion'] ?? 'General',
                'is_locked' => true
            ];
        }, $networks);
    }

    private function traducirEstado(string $status): array
    {
        return match($status) {
            'pending' => [
                ['language' => 'es', 'content' => 'Disponible pronto'],
                ['language' => 'en', 'content' => 'Available soon'],
                ['language' => 'pt', 'content' => 'Disponível em breve'],
                ['language' => 'fr', 'content' => 'Bientôt disponible'],
                ['language' => 'de', 'content' => 'Bald verfügbar'],
                ['language' => 'it', 'content' => 'Disponibile a breve'],
                ['language' => 'nl', 'content' => 'Binnenkort beschikbaar'],
            ],
            'expired' => [
                ['language' => 'es', 'content' => 'Reserva finalizada'],
                ['language' => 'en', 'content' => 'Booking ended'],
                ['language' => 'pt', 'content' => 'Reserva finalizada'],
                ['language' => 'fr', 'content' => 'Réservation terminée'],
                ['language' => 'de', 'content' => 'Buchung beendet'],
                ['language' => 'it', 'content' => 'Prenotazione terminata'],
                ['language' => 'nl', 'content' => 'Boeking beëindigd'],
            ],
            default => [ // Demo o Protegido
                ['language' => 'es', 'content' => 'Info Protegida'],
                ['language' => 'en', 'content' => 'Protected Info'],
                ['language' => 'pt', 'content' => 'Info Protegida'],
                ['language' => 'fr', 'content' => 'Info Protégée'],
                ['language' => 'de', 'content' => 'Geschützte Info'],
                ['language' => 'it', 'content' => 'Info Protetta'],
                ['language' => 'nl', 'content' => 'Beschermde Info'],
            ]
        };
    }
}