<?php

declare(strict_types=1);

namespace App\Exchange\Dto\Correo;

use App\Dto\Lee;

/**
 * Lo que devuelve `MailerExchangeClient`: qué correos salieron (con su `Message-ID`) y cuáles no
 * (con el motivo), **por id de cola**.
 *
 * Es un mapa y no una lista posicional a propósito: con destinatarios distintos, emparejar por
 * posición es la clase de error que marca un envío como bueno cuando falló otro.
 *
 * Mismo motivo que {@see CorreoSaliente} para ser un DTO: el motor lo transporta como
 * `array<mixed>` entre el cliente que lo escribe y la estrategia que lo lee. `toArray()` da la
 * forma que se guarda en `last_response_raw` —`{enviados: {id: {messageId}}, fallos: {id: motivo}}`—,
 * la misma de antes.
 */
final readonly class ResultadoDelCorreo
{
    /**
     * @param array<string, string|null> $enviados Id de cola → `Message-ID` (null si el puente no lo dejó).
     * @param array<string, string>      $fallos   Id de cola → motivo.
     */
    public function __construct(
        public array $enviados,
        public array $fallos,
    ) {}

    /** @param array<mixed> $respuesta */
    public static function fromArray(array $respuesta): self
    {
        $enviados = [];
        foreach (Lee::mapa($respuesta['enviados'] ?? null) as $id => $enviado) {
            $enviados[(string) $id] = Lee::texto(Lee::en($enviado, 'messageId'));
        }

        $fallos = [];
        foreach (Lee::mapa($respuesta['fallos'] ?? null) as $id => $motivo) {
            // Un `null` no es un fallo: la estrategia preguntaba `$fallos[$id] ?? null`. Un motivo
            // que no es texto sí lo es, aunque no se pueda leer.
            if ($motivo !== null) {
                $fallos[(string) $id] = Lee::texto($motivo) ?? 'Fallo sin motivo legible';
            }
        }

        return new self($enviados, $fallos);
    }

    /** @return array{enviados: array<string, array{messageId: string|null}>, fallos: array<string, string>} */
    public function toArray(): array
    {
        return [
            'enviados' => array_map(static fn (?string $id): array => ['messageId' => $id], $this->enviados),
            'fallos' => $this->fallos,
        ];
    }
}
