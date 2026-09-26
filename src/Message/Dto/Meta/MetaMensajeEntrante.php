<?php

declare(strict_types=1);

namespace App\Message\Dto\Meta;

use App\Dto\Lee;

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
        $tipo = Lee::texto($mensaje['type'] ?? null) ?? 'text';

        $interactivo = Lee::mapa($mensaje['interactive'] ?? null);
        $interactivoTipo = Lee::texto($interactivo['type'] ?? null);
        // La respuesta vive bajo su propio tipo: `interactive.button_reply` o `interactive.list_reply`.
        $respuesta = $interactivoTipo !== null ? Lee::mapa($interactivo[$interactivoTipo] ?? null) : [];

        $adjunto = in_array($tipo, self::TIPOS_ADJUNTO, true) ? Lee::mapa($mensaje[$tipo] ?? null) : [];
        $boton = Lee::mapa($mensaje['button'] ?? null);
        $ubicacion = Lee::mapa($mensaje['location'] ?? null);
        $reaccion = Lee::mapa($mensaje['reaction'] ?? null);

        return new self(
            id: Lee::texto($mensaje['id'] ?? null),
            tipo: $tipo,
            timestamp: Lee::entero($mensaje['timestamp'] ?? null),
            texto: Lee::texto(Lee::mapa($mensaje['text'] ?? null)['body'] ?? null),
            botonPayload: Lee::texto($boton['payload'] ?? null),
            botonTexto: Lee::texto($boton['text'] ?? null),
            interactivoTipo: $interactivoTipo,
            interactivoId: Lee::texto($respuesta['id'] ?? null),
            interactivoTitulo: Lee::texto($respuesta['title'] ?? null),
            adjuntoId: Lee::texto($adjunto['id'] ?? null),
            adjuntoMime: Lee::texto($adjunto['mime_type'] ?? null),
            adjuntoNombre: Lee::texto($adjunto['filename'] ?? null),
            latitud: Lee::texto($ubicacion['latitude'] ?? null),
            longitud: Lee::texto($ubicacion['longitude'] ?? null),
            reaccionAMensaje: Lee::texto($reaccion['message_id'] ?? null),
            reaccionEmoji: Lee::texto($reaccion['emoji'] ?? null),
        );
    }
}
