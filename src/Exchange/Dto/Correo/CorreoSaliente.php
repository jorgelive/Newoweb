<?php

declare(strict_types=1);

namespace App\Exchange\Dto\Correo;

use App\Dto\Lee;

/**
 * Un correo listo para el mailer: lo que `EmailSendMappingStrategy` deja en el payload y
 * `MailerExchangeClient` recoge.
 *
 * ── Por qué un DTO para algo que escribimos nosotros ────────────────────────
 * Porque entre los dos está el motor genérico: `MappingResult::$payload` es un `array<mixed>` que
 * vale para todos los canales, así que lo que la estrategia escribe como texto le llega al cliente
 * como `mixed`. Con el mismo objeto escribiendo (`toArray()`) y leyendo (`fromArray()`), las claves
 * `to`/`subject`/`text` se escriben en UN sitio y no pueden desalinearse.
 *
 * El payload se guarda tal cual en `last_request_raw` de la cola: `toArray()` conserva las claves y
 * el `null` del destinatario para que la auditoría no cambie.
 */
final readonly class CorreoSaliente
{
    public function __construct(
        public ?string $para,
        public string $asunto,
        public string $texto,
    ) {}

    /** @param array<mixed> $correo */
    public static function fromArray(array $correo): self
    {
        return new self(
            para: Lee::texto($correo['to'] ?? null),
            asunto: Lee::texto($correo['subject'] ?? null) ?? '',
            texto: Lee::texto($correo['text'] ?? null) ?? '',
        );
    }

    /** @return array{to: string|null, subject: string, text: string} */
    public function toArray(): array
    {
        return ['to' => $this->para, 'subject' => $this->asunto, 'text' => $this->texto];
    }
}
