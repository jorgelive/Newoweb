<?php

declare(strict_types=1);

namespace App\Cotizacion\Dto;

use App\Dto\Lee;
use DateTimeImmutable;

/**
 * El `PATCH` de un vuelo (`VueloEditarController`), leído una vez.
 *
 * Es un PATCH de verdad: **un campo que no viene no se toca, y uno que viene vacío se borra**. Por
 * eso el DTO guarda qué campos trae además de su valor —`trae()`—: `null` no basta para distinguir
 * «no lo mandaste» de «vacíalo».
 *
 * La misma lectura que hacía el controlador a mano, con dos matices que sólo afectan a basura:
 * lo que no es texto ya no se convierte en la palabra «Array», y una fecha que llega como algo que
 * no es texto es «no la entiendo» (400), no «vacíala».
 */
final readonly class CuerpoDeVuelo
{
    /** @param list<string> $presentes */
    private function __construct(
        private array $presentes,
        /** Recortado. Vacío o ilegible es `''`, que el controlador rechaza: un vuelo sin número no se deja. */
        public string $numero,
        public ?string $aerolinea,
        /** Código IATA en mayúsculas: «lim» y «LIM» no son dos aeropuertos. */
        public ?string $origen,
        public ?string $destino,
        /** `false` = no se entiende; `null` = vaciar. */
        public DateTimeImmutable|false|null $salida,
        public DateTimeImmutable|false|null $llegada,
    ) {}

    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): self
    {
        // `numero` con `isset`, no con `array_key_exists`: mandarlo a `null` era «no lo toques».
        $presentes = isset($datos['numero']) ? ['numero'] : [];

        foreach (['aerolinea', 'origen', 'destino', 'salida', 'llegada'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $presentes[] = $campo;
            }
        }

        $origen = Lee::textoLimpio($datos['origen'] ?? null);
        $destino = Lee::textoLimpio($datos['destino'] ?? null);

        return new self(
            presentes: $presentes,
            numero: trim(Lee::texto($datos['numero'] ?? null) ?? ''),
            aerolinea: Lee::textoLimpio($datos['aerolinea'] ?? null),
            origen: $origen === null ? null : strtoupper($origen),
            destino: $destino === null ? null : strtoupper($destino),
            salida: self::momento($datos['salida'] ?? null),
            llegada: self::momento($datos['llegada'] ?? null),
        );
    }

    public function trae(string $campo): bool
    {
        return in_array($campo, $this->presentes, true);
    }

    private static function momento(mixed $valor): DateTimeImmutable|false|null
    {
        if ($valor === null) {
            return null;
        }

        $texto = Lee::texto($valor);

        if ($texto === null) {
            return false;
        }

        $texto = trim($texto);

        if ($texto === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($texto);
        } catch (\Exception) {
            return false;
        }
    }
}
