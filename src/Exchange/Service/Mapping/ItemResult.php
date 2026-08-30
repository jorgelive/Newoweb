<?php
declare(strict_types=1);

namespace App\Exchange\Service\Mapping;

final readonly class ItemResult
{
    /**
     * @param array<array-key, mixed> $extraData Lo que el handler quiera adjuntar al resultado
     *        de este ítem.
     *
     * ⚠️ **Sin forma fija, y comprobado.** La primera anotación decía `array<string, mixed>` y
     * PHPStan la tumbó al instante: `Beds24Receive` le pasa una LISTA de mensajes
     * (`list<array<mixed>>`), no un mapa. Es el aviso de la cabecera del baseline hecho carne —
     * la forma se descubre midiendo, no leyendo el nombre del campo.
     */
    public function __construct(
        public string|int $queueItemId,
        public bool $success,
        public ?string $message = null,
        public ?string $remoteId = null,
        public array $extraData = []
    ) {}
}