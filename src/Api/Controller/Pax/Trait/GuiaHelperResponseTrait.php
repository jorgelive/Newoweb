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

        // A. TEXT FIXED (Base inofensiva)
        $textFixed = [
            'guest_name'     => $reserva ? $reserva->getNombreCliente() : 'Visitante',
            'unit_name'      => $unidad->getNombre(),
            'hotel_name'     => $est?->getNombreComercial() ?? 'Hotel',
            'booking_ref'    => $reserva?->getLocalizador() ?? 'DEMO',
            'check_in'       => ($evento ? $evento->getInicio() : $est?->getHoraCheckIn())?->format('H:i'),
            'check_out'      => ($evento ? $evento->getFin() : $est?->getHoraCheckOut())?->format('H:i'),
            'start_date'     => $evento?->getInicio()->format('d/m/Y'),
            'end_date'       => $evento?->getFin()->format('d/m/Y'),
            'wifi_ssid'      => $unidad->getWifiNetworks()[0]['ssid'] ?? 'N/A',
        ];

        // B. TEXT TRANSLATABLE (Base)
        $textTranslatable = [
            'status_msg' => $this->traducirEstado($acceso['status'], $evento),
        ];

        // 🔥 LÓGICA DE SEGURIDAD (El backend decide dónde inyectar las llaves)
        if ($esAutorizado) {
            // 1. Autorizado: Enviamos los códigos reales directo al Fixed Text
            $textFixed['door_code']   = $unidad->getCodigoPuerta() ?? 'N/A';
            $textFixed['safe_code']   = $unidad->getCodigoCaja() ?? 'N/A';
            $textFixed['keybox_main'] = $est?->getCodigoCajaPrincipal() ?? 'N/A';
            $textFixed['keybox_sec']  = $est?->getCodigoCajaSecundaria() ?? 'N/A';
            $textFixed['wifi_pass']   = $unidad->getWifiNetworks()[0]['password'] ?? 'N/A';
        } else {
            if (!$evento) {
                // 2. Público (Demo/QR): Enviamos asteriscos al Fixed Text
                $textFixed['door_code']   = $mask;
                $textFixed['safe_code']   = $mask;
                $textFixed['keybox_main'] = $mask;
                $textFixed['keybox_sec']  = $mask;
                $textFixed['wifi_pass']   = $mask;
            } else {
                // 3. Huésped No Autorizado: Inyectamos el mensaje dinámico en el Translatable Text!
                // Al no existir en $textFixed, el frontend saltará a buscarlo aquí y lo traducirá.
                $mensajeBloqueo = $this->traducirEstado($acceso['status'], $evento);

                $textTranslatable['door_code']   = $mensajeBloqueo;
                $textTranslatable['safe_code']   = $mensajeBloqueo;
                $textTranslatable['keybox_main'] = $mensajeBloqueo;
                $textTranslatable['keybox_sec']  = $mensajeBloqueo;
                $textTranslatable['wifi_pass']   = $mensajeBloqueo;
            }
        }

        // C. WIDGETS
        $widgets = [
            'wifi_data' => $this->prepararWifi($unidad->getWifiNetworks(), $esAutorizado)
        ];

        // D. CONFIG
        $config = [
            'mode'           => $evento ? 'guest' : 'demo',
            'access_status'  => $acceso['status'],
            'is_locked'      => !$esAutorizado,
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

    private function traducirEstado(string $status, ?PmsEventoCalendario $evento): array
    {
        $fecha = $evento ? $evento->getInicio()->format('d/m/Y') : '';
        $hora  = $evento ? $evento->getInicio()->format('H:i') : '';

        return match($status) {
            'pending' => [
                ['language' => 'es', 'content' => $fecha ? "Disponible el $fecha a las $hora" : 'Disponible pronto'],
                ['language' => 'en', 'content' => $fecha ? "Available on $fecha at $hora" : 'Available soon'],
                ['language' => 'pt', 'content' => $fecha ? "Disponível em $fecha às $hora" : 'Disponível em breve'],
                ['language' => 'fr', 'content' => $fecha ? "Disponible le $fecha à $hora" : 'Bientôt disponible'],
                ['language' => 'it', 'content' => $fecha ? "Disponibile il $fecha alle $hora" : 'Disponibile a breve'],
                ['language' => 'de', 'content' => $fecha ? "Verfügbar am $fecha um $hora" : 'Bald verfügbar'],
                ['language' => 'nl', 'content' => $fecha ? "Beschikbaar op $fecha om $hora" : 'Binnenkort beschikbaar'],
            ],
            'expired' => [
                ['language' => 'es', 'content' => 'Reserva finalizada'],
                ['language' => 'en', 'content' => 'Booking ended'],
                ['language' => 'pt', 'content' => 'Reserva finalizada'],
                ['language' => 'fr', 'content' => 'Réservation terminée'],
                ['language' => 'it', 'content' => 'Prenotazione terminata'],
                ['language' => 'de', 'content' => 'Buchung beendet'],
                ['language' => 'nl', 'content' => 'Boeking beëindigd'],
            ],
            default => [
                ['language' => 'es', 'content' => 'Info Protegida'],
                ['language' => 'en', 'content' => 'Protected Info'],
                ['language' => 'pt', 'content' => 'Informação protegida'],
                ['language' => 'fr', 'content' => 'Info protégée'],
                ['language' => 'it', 'content' => 'Info protetta'],
                ['language' => 'de', 'content' => 'Geschützte Info'],
                ['language' => 'nl', 'content' => 'Beschermde info'],
            ]
        };
    }
}