<?php

declare(strict_types=1);

namespace App\Message\Dto\Meta;

/**
 * Un mensaje del huésped: un elemento de `value.messages[]` del webhook de Meta.
 *
 * Lleva exactamente lo que lee `WhatsappMetaReceivePersister`, ni un campo más: los tipos que
 * aparecen en producción (texto, botón, reacción, imagen, interactivo, documento) y los que el
 * persister ya sabía tratar (audio, vídeo, sticker, ubicación). Un tipo que no esté aquí sigue
 * llegando con su `tipo` y el persister lo guarda como «no soportado», igual que antes.
 */
final readonly class MetaMensajeEntrante
{
    /** Los tipos cuyo contenido es un fichero, bajo `messages[].{tipo}`. */
    public const array TIPOS_ADJUNTO = ['image', 'document', 'audio', 'video', 'sticker'];

    public function __construct(
        public ?string $id,
        /** `text` por defecto, como hacía el persister cuando Meta no lo decía. */
        public string $tipo,
        /** Segundos Unix. Meta los manda como texto. */
        public ?int $timestamp,
        public ?string $texto,
        public ?string $botonPayload,
        public ?string $botonTexto,
        /** `button_reply` o `list_reply`, los dos que el persister trata. */
        public ?string $interactivoTipo,
        public ?string $interactivoId,
        public ?string $interactivoTitulo,
        public ?string $adjuntoId,
        public ?string $adjuntoMime,
        public ?string $adjuntoNombre,
        public ?string $latitud,
        public ?string $longitud,
        /** El mensaje NUESTRO al que reacciona el huésped. */
        public ?string $reaccionAMensaje,
        public ?string $reaccionEmoji,
    ) {}

    /** @param array<mixed> $mensaje */
    public static function fromArray(array $mensaje): self
    {
        $tipo = LeeMeta::texto($mensaje['type'] ?? null) ?? 'text';

        $interactivo = LeeMeta::mapa($mensaje['interactive'] ?? null);
        $interactivoTipo = LeeMeta::texto($interactivo['type'] ?? null);
        // La respuesta vive bajo su propio tipo: `interactive.button_reply` o `interactive.list_reply`.
        $respuesta = $interactivoTipo !== null ? LeeMeta::mapa($interactivo[$interactivoTipo] ?? null) : [];

        $adjunto = in_array($tipo, self::TIPOS_ADJUNTO, true) ? LeeMeta::mapa($mensaje[$tipo] ?? null) : [];
        $boton = LeeMeta::mapa($mensaje['button'] ?? null);
        $ubicacion = LeeMeta::mapa($mensaje['location'] ?? null);
        $reaccion = LeeMeta::mapa($mensaje['reaction'] ?? null);

        return new self(
            id: LeeMeta::texto($mensaje['id'] ?? null),
            tipo: $tipo,
            timestamp: LeeMeta::entero($mensaje['timestamp'] ?? null),
            texto: LeeMeta::texto(LeeMeta::mapa($mensaje['text'] ?? null)['body'] ?? null),
            botonPayload: LeeMeta::texto($boton['payload'] ?? null),
            botonTexto: LeeMeta::texto($boton['text'] ?? null),
            interactivoTipo: $interactivoTipo,
            interactivoId: LeeMeta::texto($respuesta['id'] ?? null),
            interactivoTitulo: LeeMeta::texto($respuesta['title'] ?? null),
            adjuntoId: LeeMeta::texto($adjunto['id'] ?? null),
            adjuntoMime: LeeMeta::texto($adjunto['mime_type'] ?? null),
            adjuntoNombre: LeeMeta::texto($adjunto['filename'] ?? null),
            latitud: LeeMeta::texto($ubicacion['latitude'] ?? null),
            longitud: LeeMeta::texto($ubicacion['longitude'] ?? null),
            reaccionAMensaje: LeeMeta::texto($reaccion['message_id'] ?? null),
            reaccionEmoji: LeeMeta::texto($reaccion['emoji'] ?? null),
        );
    }
}
