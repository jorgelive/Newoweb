<?php

namespace App\Api\Controller\Pax\Trait;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsUnidad;
use Symfony\Component\HttpFoundation\JsonResponse;

trait GuiaHelperResponseTrait
{
    private function buildResponse(PmsUnidad $unidad, ?PmsEventoCalendario $evento): JsonResponse
    {
        $est = $unidad->getEstablecimiento();
        $reserva = $evento?->getReserva();

        $acceso = $this->calcularAcceso($evento);
        $esAutorizado = $acceso['authorized'];
        $mask = '********';

        // A. TEXT FIXED
        $textFixed = [
            'guest_name'     => $reserva ? $reserva->getNombreCliente() : 'Visitante',
            'unit_name'      => $unidad->getNombre(),
            'hotel_name'     => $est?->getNombreComercial() ?? 'Hotel',
            'booking_ref'    => $reserva?->getLocalizador() ?? 'DEMO',

            'check_in'       => ($evento ? $evento->getInicio() : $est?->getHoraCheckIn())?->format('H:i'),
            'check_out'      => ($evento ? $evento->getFin() : $est?->getHoraCheckOut())?->format('H:i'),
            'start_date'     => $evento?->getInicio()->format('d/m/Y'),
            'end_date'       => $evento?->getFin()->format('d/m/Y'),

            // Códigos (Solo si es autorizado)
            'door_code'      => $esAutorizado ? $unidad->getCodigoPuerta() : $mask,
            'safe_code'      => $esAutorizado ? $unidad->getCodigoCaja() : $mask,
            'keybox_main'    => $esAutorizado ? $est?->getCodigoCajaPrincipal() : $mask,
            'keybox_sec'     => $esAutorizado ? $est?->getCodigoCajaSecundaria() : $mask,

            // WiFi
            'wifi_ssid'      => $unidad->getWifiNetworks()[0]['ssid'] ?? 'N/A',
            'wifi_pass'      => $esAutorizado ? ($unidad->getWifiNetworks()[0]['password'] ?? 'N/A') : $mask,
        ];

        // B. TEXT TRANSLATABLE
        $textTranslatable = [
            'status_msg' => $this->traducirEstado($acceso['status']),
        ];

        // C. WIDGETS
        $widgets = [
            'wifi_data' => $this->prepararWifi($unidad->getWifiNetworks(), $esAutorizado)
        ];

        // D. CONFIG
        $config = [
            'mode'           => $evento ? 'guest' : 'demo',
            'access_status'  => $acceso['status'],
            'is_locked'      => !$esAutorizado,
            // 🔥 CLAVE: Retornamos siempre el UUID de la unidad para que el front cargue el CMS
            'unit_uuid'      => method_exists($unidad, 'getUuid') ? $unidad->getUuid() : $unidad->getId(),
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
            ],
            'expired' => [
                ['language' => 'es', 'content' => 'Reserva finalizada'],
                ['language' => 'en', 'content' => 'Booking ended'],
            ],
            default => [
                ['language' => 'es', 'content' => 'Info Protegida'],
                ['language' => 'en', 'content' => 'Protected Info'],
            ]
        };
    }
}