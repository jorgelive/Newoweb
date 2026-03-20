<?php

namespace App\Api\Controller\Pax\Trait;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsUnidad;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Trait encargado de construir el payload dinámico para la Guía Digital del huésped.
 * Centraliza la lógica de seguridad, inyectando contraseñas reales o máscaras
 * dependiendo del estado de la reserva y la ventana de tiempo (Check-in).
 */
trait GuiaHelperResponseTrait
{
    /**
     * Construye la respuesta JSON final con los datos dinámicos requeridos por el frontend.
     *
     * @param PmsUnidad $unidad La unidad de alojamiento asociada.
     * @param PmsEventoCalendario|null $evento El evento de reserva (null si es acceso público/demo).
     * @return JsonResponse Payload estructurado con textos fijos, traducibles, widgets y configuración.
     */
    private function buildResponse(PmsUnidad $unidad, ?PmsEventoCalendario $evento): JsonResponse
    {
        $est = $unidad->getEstablecimiento();
        $reserva = $evento?->getReserva();

        $acceso = $this->calcularAcceso($evento);
        $esAutorizado = $acceso['authorized'];
        $unlockAt = $acceso['unlock_at'];
        $mask = '********';

        // A. TEXT FIXED (Textos estáticos que no requieren traducción)
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

        // B. TEXT TRANSLATABLE (Textos que el frontend debe traducir)
        $textTranslatable = [
            'status_msg' => $this->traducirEstado($acceso['status'], $unlockAt),
        ];

        // 🔥 LÓGICA DE SEGURIDAD PARA CÓDIGOS Y PUERTAS
        if ($esAutorizado) {
            // El huésped está autorizado (en fechas y con reserva confirmada)
            $textFixed['door_code']   = $unidad->getCodigoPuerta() ?? 'N/A';
            $textFixed['safe_code']   = $unidad->getCodigoCaja() ?? 'N/A';
            $textFixed['keybox_main'] = $est?->getCodigoCajaPrincipal() ?? 'N/A';
            $textFixed['keybox_sec']  = $est?->getCodigoCajaSecundaria() ?? 'N/A';
        } else {
            // El huésped NO está autorizado (o es una vista pública DEMO)
            if (!$evento) {
                // Vista Demo: mostramos asteriscos
                $textFixed['door_code']   = $mask;
                $textFixed['safe_code']   = $mask;
                $textFixed['keybox_main'] = $mask;
                $textFixed['keybox_sec']  = $mask;
            } else {
                // Huésped real pero fuera de tiempo: mostramos mensaje de bloqueo
                $mensajeBloqueo = $this->traducirEstado($acceso['status'], $unlockAt);
                $textTranslatable['door_code']   = $mensajeBloqueo;
                $textTranslatable['safe_code']   = $mensajeBloqueo;
                $textTranslatable['keybox_main'] = $mensajeBloqueo;
                $textTranslatable['keybox_sec']  = $mensajeBloqueo;
            }
        }

        // C. WIDGETS DINÁMICOS (Procesando el JSON de wifiNetworks)
        $wifiData = [];
        $redesWifi = $unidad->getWifiNetworks();

        if (!empty($redesWifi)) {
            foreach ($redesWifi as $red) {
                $wifiData[] = [
                    // Se envía el array multi-idioma tal cual para que el helper de Vue lo procese
                    'ubicacion' => $red['ubicacion'] ?? [],
                    'ssid'      => $red['ssid'] ?? 'N/A',
                    // Protegemos la contraseña si la reserva no está autorizada
                    'password'  => $esAutorizado ? ($red['password'] ?? 'N/A') : $mask,
                    'is_locked' => !$esAutorizado
                ];
            }
        }

        // D. CONFIG (Parámetros de control para el frontend)
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
                'widgets'           => [
                    'wifi_data' => $wifiData
                ],
                'config'            => $config
            ]
        ]);
    }

    /**
     * Evalúa si el evento tiene el estado correcto y si está dentro de la ventana de tiempo permitida.
     * Considera como autorizados a los eventos a partir de 24 horas antes del Check-in.
     *
     * @param PmsEventoCalendario|null $evento
     * @return array{status: string, authorized: bool, unlock_at: \DateTimeInterface|null}
     */
    private function calcularAcceso(?PmsEventoCalendario $evento): array
    {
        if (!$evento) {
            return ['status' => 'demo', 'authorized' => false, 'unlock_at' => null];
        }

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
     * Devuelve el array multi-idioma estandarizado con el estado de la reserva.
     *
     * @param string $status Estado calculado ('unconfirmed', 'pending', 'expired', etc.)
     * @param \DateTimeInterface|null $unlockAt Fecha en la que se liberan los datos
     * @return array Lista de traducciones
     */
    private function traducirEstado(string $status, ?\DateTimeInterface $unlockAt): array
    {
        $fecha = $unlockAt ? $unlockAt->format('d/m/Y') : '';
        $hora  = $unlockAt ? $unlockAt->format('H:i') : '';

        return match($status) {
            'unconfirmed' => [
                ['language' => 'es', 'content' => '[ Disponible al confirmar ]'],
                ['language' => 'en', 'content' => '[ Available upon confirmation ]'],
                ['language' => 'pt', 'content' => '[ Disponível mediante confirmação ]'],
                ['language' => 'fr', 'content' => '[ Disponible après confirmation ]'],
                ['language' => 'it', 'content' => '[ Disponibile dopo conferma ]'],
                ['language' => 'de', 'content' => '[ Verfügbar nach Bestätigung ]'],
                ['language' => 'nl', 'content' => '[ Beschikbaar na bevestiging ]'],
            ],
            'pending' => [
                ['language' => 'es', 'content' => $fecha ? "[ Disponible el $fecha a las $hora ]" : '[ Disponible pronto ]'],
                ['language' => 'en', 'content' => $fecha ? "[ Available on $fecha at $hora ]" : '[ Available soon ]'],
                ['language' => 'pt', 'content' => $fecha ? "[ Disponível em $fecha às $hora ]" : '[ Disponível em breve ]'],
                ['language' => 'fr', 'content' => $fecha ? "[ Disponible le $fecha à $hora ]" : '[ Bientôt disponible ]'],
                ['language' => 'it', 'content' => $fecha ? "[ Disponibile il $fecha alle $hora ]" : '[ Disponibile a breve ]'],
                ['language' => 'de', 'content' => $fecha ? "[ Verfügbar am $fecha um $hora ]" : '[ Bald verfügbar ]'],
                ['language' => 'nl', 'content' => $fecha ? "[ Beschikbaar op $fecha om $hora ]" : '[ Binnenkort beschikbaar ]'],
            ],
            'expired' => [
                ['language' => 'es', 'content' => '[ Reserva finalizada ]'],
                ['language' => 'en', 'content' => '[ Booking ended ]'],
                ['language' => 'pt', 'content' => '[ Reserva finalizada ]'],
                ['language' => 'fr', 'content' => '[ Réservation terminée ]'],
                ['language' => 'it', 'content' => '[ Prenotazione terminata ]'],
                ['language' => 'de', 'content' => '[ Buchung beendet ]'],
                ['language' => 'nl', 'content' => '[ Boeking beëindigd ]'],
            ],
            default => [
                ['language' => 'es', 'content' => '[ Info Protegida ]'],
                ['language' => 'en', 'content' => '[ Protected Info ]'],
                ['language' => 'pt', 'content' => '[ Informação protegida ]'],
                ['language' => 'fr', 'content' => '[ Info protégée ]'],
                ['language' => 'it', 'content' => '[ Info protetta ]'],
                ['language' => 'de', 'content' => '[ Geschützte Info ]'],
                ['language' => 'nl', 'content' => '[ Beschermde info ]'],
            ]
        };
    }
}