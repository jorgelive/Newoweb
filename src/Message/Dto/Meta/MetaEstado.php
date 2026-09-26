<?php

declare(strict_types=1);

namespace App\Message\Dto\Meta;

/**
 * Un cambio de estado de un mensaje NUESTRO: un elemento de `value.statuses[]` (enviado, entregado,
 * leído, fallido).
 */
final readonly class MetaEstado
{
    /**
     * @param list<array<mixed>> $errores Tal cual los manda Meta. Sólo se usan para dejar
     *        constancia del motivo cuando el primero no trae `message`.
     */
    public function __construct(
        /** El `wamid` del mensaje al que se refiere. */
        public ?string $id,
        public ?string $estado,
        public ?int $timestamp,
        public ?string $errorCodigo,
        public ?string $errorMensaje,
        public array $errores,
    ) {}

    /** @param array<mixed> $estado */
    public static function fromArray(array $estado): self
    {
        $errores = LeeMeta::listaDeMapas($estado['errors'] ?? null);
        $primero = $errores[0] ?? [];

        return new self(
            id: LeeMeta::texto($estado['id'] ?? null),
            estado: LeeMeta::texto($estado['status'] ?? null),
            timestamp: LeeMeta::entero($estado['timestamp'] ?? null),
            errorCodigo: LeeMeta::texto($primero['code'] ?? null),
            errorMensaje: LeeMeta::texto($primero['message'] ?? null),
            errores: $errores,
        );
    }
}
