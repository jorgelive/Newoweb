<?php

declare(strict_types=1);

namespace App\Message\Dto;

/**
 * Una reserva, un expediente… a cuyo huésped hoy no le sale un WhatsApp.
 *
 * Lo produce cada dominio ({@see \App\Message\Contract\AsuntosSinTelefonoInterface}) y lo pinta el
 * reporte del portal. Ver `docs/Mensajeria.md`, «Reservas y cotizaciones sin teléfono».
 */
final readonly class AsuntoSinTelefono
{
    /** No hay conversación: nunca hubo un dato de contacto con el que abrirla. */
    public const string SIN_CONVERSACION = 'sin_conversacion';
    /** Hay conversación pero ningún teléfono vivo. */
    public const string SIN_TELEFONO = 'sin_telefono';
    /** El número está en la ficha pero no en su conversación: casi siempre, porque ya era de otra. */
    public const string SOLO_EN_LA_FICHA = 'solo_en_la_ficha';
    /** Tiene teléfono, pero el WhatsApp de ese hilo está vetado. */
    public const string WHATSAPP_VETADO = 'whatsapp_vetado';

    /**
     * @param string                $negocio        El `contextType` del asunto (`pms_reserva`, `cotizacion_file`).
     * @param string                $detalle        Lo que el dominio quiera decir de él: canal, casitas, localizador.
     * @param string|null           $fecha          Y-m-d de cuando empieza, para ordenar y para pintar.
     * @param array<string, string> $destino        Lo que necesita el panel para abrirlo (ids).
     */
    public function __construct(
        public string $negocio,
        public string $id,
        public string $nombre,
        public string $detalle,
        public ?string $fecha,
        public string $motivo,
        public ?string $conversacionId,
        public bool $fusionSugerida,
        public array $destino,
    ) {}

    /**
     * Por qué no le sale un WhatsApp, o null si sí le sale.
     *
     * Mira lo MISMO que el envío —`WhatsappMetaSendEnqueuer::disponiblePara()`: `guestPhone` del
     * hilo y su veto—, no la ficha. La ficha guarda la semilla con la que nació el contacto; a
     * dónde se escribe lo deciden las identidades del hilo, y `guestPhone` es su copia.
     */
    public static function motivo(bool $hayConversacion, ?string $telefonoDelHilo, bool $vetado, ?string $telefonoDeLaFicha): ?string
    {
        $tieneTelefono = $telefonoDelHilo !== null && trim($telefonoDelHilo) !== '';

        return match (true) {
            !$hayConversacion => self::SIN_CONVERSACION,
            !$tieneTelefono && $telefonoDeLaFicha !== null && trim($telefonoDeLaFicha) !== '' => self::SOLO_EN_LA_FICHA,
            !$tieneTelefono => self::SIN_TELEFONO,
            $vetado => self::WHATSAPP_VETADO,
            default => null,
        };
    }

    /**
     * @return array{negocio: string, id: string, nombre: string, detalle: string, fecha: string|null, motivo: string,
     *               conversacionId: string|null, fusionSugerida: bool, destino: array<string, string>}
     */
    public function aArray(): array
    {
        return [
            'negocio' => $this->negocio,
            'id' => $this->id,
            'nombre' => $this->nombre,
            'detalle' => $this->detalle,
            'fecha' => $this->fecha,
            'motivo' => $this->motivo,
            'conversacionId' => $this->conversacionId,
            'fusionSugerida' => $this->fusionSugerida,
            'destino' => $this->destino,
        ];
    }
}
