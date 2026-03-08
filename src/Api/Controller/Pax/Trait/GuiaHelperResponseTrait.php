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
        $unlockAt = $acceso['unlock_at'];
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
        ];

        // B. TEXT TRANSLATABLE (Base)
        // Pasamos la fecha de liberación en lugar del evento completo para mayor precisión
        $textTranslatable = [
            'status_msg' => $this->traducirEstado($acceso['status'], $unlockAt),
        ];

        // 🔥 LÓGICA DE SEGURIDAD
        if ($esAutorizado) {
            // 1. Autorizado: Enviamos los códigos reales directo al Fixed Text
            $textFixed['door_code']   = $unidad->getCodigoPuerta() ?? 'N/A';
            $textFixed['safe_code']   = $unidad->getCodigoCaja() ?? 'N/A';
            $textFixed['keybox_main'] = $est?->getCodigoCajaPrincipal() ?? 'N/A';
            $textFixed['keybox_sec']  = $est?->getCodigoCajaSecundaria() ?? 'N/A';
        } else {
            if (!$evento) {
                // 2. Público (Demo/QR): Enviamos asteriscos al Fixed Text
                $textFixed['door_code']   = $mask;
                $textFixed['safe_code']   = $mask;
                $textFixed['keybox_main'] = $mask;
                $textFixed['keybox_sec']  = $mask;
            } else {
                // 3. Huésped No Autorizado: Inyectamos el mensaje dinámico en el Translatable Text
                $mensajeBloqueo = $this->traducirEstado($acceso['status'], $unlockAt);

                $textTranslatable['door_code']   = $mensajeBloqueo;
                $textTranslatable['safe_code']   = $mensajeBloqueo;
                $textTranslatable['keybox_main'] = $mensajeBloqueo;
                $textTranslatable['keybox_sec']  = $mensajeBloqueo;
            }
        }

        // D. CONFIG
        // 🔥 AQUÍ SE INYECTA LA FECHA PARA QUE LLEGUE A VUE
        $config = [
            'mode'           => $evento ? 'guest' : 'demo',
            'access_status'  => $acceso['status'],
            'is_locked'      => !$esAutorizado,
            'unlock_at'      => $unlockAt ? $unlockAt->format('Y-m-d\TH:i:s') : null,
            'unit_uuid'      => method_exists($unidad, 'getUuid') ? $unidad->getUuid() : $unidad->getId(),
        ];

        return new JsonResponse([
            'data' => [
                'text_fixed'        => $textFixed,
                'text_translatable' => $textTranslatable,
                'config'            => $config
            ]
        ]);
    }

    /**
     * Evalúa si el evento tiene el estado correcto y si está dentro de la ventana de tiempo.
     */
    private function calcularAcceso(?PmsEventoCalendario $evento): array
    {
        if (!$evento) return ['status' => 'demo', 'authorized' => false, 'unlock_at' => null];

        // 1. Validamos primero el estado de la reserva
        $estadoId = $evento->getEstado()?->getId();
        if (!in_array($estadoId, PmsEventoCalendario::ESTADOS_CONFIRMADOS, true)) {
            return ['status' => 'unconfirmed', 'authorized' => false, 'unlock_at' => null];
        }

        // 2. Validamos la ventana de tiempo (Exactamente 24 horas antes del Check-in)
        $ahora = new \DateTime();
        $fechaLiberacion = (clone $evento->getInicio())->modify('-24 hours');
        $fin = (clone $evento->getFin())->setTime(23, 59, 59);

        if ($ahora < $fechaLiberacion) {
            // Aún no llega la fecha de liberación
            return ['status' => 'pending', 'authorized' => false, 'unlock_at' => $fechaLiberacion];
        }

        if ($ahora > $fin) {
            // Ya pasó el checkout
            return ['status' => 'expired', 'authorized' => false, 'unlock_at' => null];
        }

        // Todo correcto, damos acceso
        return ['status' => 'active', 'authorized' => true, 'unlock_at' => $fechaLiberacion];
    }

    /**
     * Traduce los estados a diferentes idiomas para el frontend.
     * Ahora recibe la fecha exacta de liberación en lugar del evento completo.
     */
    private function traducirEstado(string $status, ?\DateTimeInterface $unlockAt): array
    {
        $fecha = $unlockAt ? $unlockAt->format('d/m/Y') : '';
        $hora  = $unlockAt ? $unlockAt->format('H:i') : '';

        return match($status) {
            'unconfirmed' => [
                ['language' => 'es', 'content' => 'Reserva no confirmada o cancelada'],
                ['language' => 'en', 'content' => 'Booking unconfirmed or cancelled'],
                ['language' => 'pt', 'content' => 'Reserva não confirmada ou cancelada'],
                ['language' => 'fr', 'content' => 'Réservation non confirmée ou annulée'],
                ['language' => 'it', 'content' => 'Prenotazione non confermata o annullata'],
                ['language' => 'de', 'content' => 'Buchung nicht bestätigt oder storniert'],
                ['language' => 'nl', 'content' => 'Boeking niet bevestigd of geannuleerd'],
            ],
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