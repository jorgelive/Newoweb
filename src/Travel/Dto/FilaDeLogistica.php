<?php

declare(strict_types=1);

namespace App\Travel\Dto;

use App\Dto\Lee;
use App\Travel\Enum\ComponenteModoEnum;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Una fila de la logística ESPECÍFICA de un párrafo de itinerario, tal como la manda el modal del
 * panel (`travel_segmento_componente_modal_controller.js`) a `TravelSegmentoComponenteAjaxController`.
 *
 * ── Por qué se lee la lista ENTERA antes de tocar nada ─────────────────────
 * El controlador guarda borrando la logística vieja y escribiendo la nueva. Leía el cuerpo fila a
 * fila DESPUÉS de borrar, así que un id de tarifa mal formado o una hora ilegible en la fila 7 era
 * un 500 con la logística vieja ya borrada y la nueva a medias. Ahora {@see self::lista()} lee y
 * valida todo primero: si algo no se entiende, 400 diciendo qué fila, y no se ha tocado nada.
 *
 * La lectura de cada campo es la de antes, salvo lo que era basura: `servicioCompleto: "false"`
 * ya no es `true`.
 */
final readonly class FilaDeLogistica
{
    public function __construct(
        public Uuid $componenteId,
        public int $orden,
        public ComponenteModoEnum $modo,
        public ?int $dia,
        public ?Uuid $tarifaId,
        public ?DateTimeImmutable $hora,
        public ?DateTimeImmutable $horaFin,
        public bool $servicioCompleto,
    ) {}

    /**
     * @return list<self> Las filas con componente; las que no traen ninguno se ignoran, como antes.
     *
     * @throws InvalidArgumentException con la fila y el campo que no se entiende
     */
    public static function lista(mixed $cuerpo): array
    {
        if (!is_array($cuerpo) || !array_is_list($cuerpo)) {
            throw new InvalidArgumentException('Se esperaba una lista de filas.');
        }

        $filas = [];

        foreach ($cuerpo as $i => $fila) {
            if (!is_array($fila)) {
                throw new InvalidArgumentException(sprintf('La fila %d no es un objeto.', $i + 1));
            }

            $leida = self::fromArray($fila, $i + 1);

            if ($leida !== null) {
                $filas[] = $leida;
            }
        }

        return $filas;
    }

    /**
     * @param array<mixed> $fila
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $fila, int $numero = 1): ?self
    {
        if (empty($fila['componenteId'])) {
            return null;
        }

        $dia = $fila['dia'] ?? null;

        return new self(
            componenteId: self::uuid($fila['componenteId'], $numero, 'componente'),
            orden: Lee::entero($fila['orden'] ?? null) ?? 0,
            modo: ComponenteModoEnum::tryFrom(Lee::texto($fila['modo'] ?? null) ?? 'incluido') ?? ComponenteModoEnum::INCLUIDO,
            dia: $dia === null || $dia === '' ? null : Lee::entero($dia),
            tarifaId: empty($fila['tarifaId']) ? null : self::uuid($fila['tarifaId'], $numero, 'tarifa'),
            hora: empty($fila['hora']) ? null : self::hora($fila['hora'], $numero, 'hora'),
            horaFin: empty($fila['horaFin']) ? null : self::hora($fila['horaFin'], $numero, 'hora de fin'),
            // `Lee::booleano()` primero: un `"false"` en texto era `true` con el `!empty()` de antes.
            // Lo que no es un booleano legible conserva aquel criterio.
            servicioCompleto: Lee::booleano($fila['servicioCompleto'] ?? null) ?? !empty($fila['servicioCompleto']),
        );
    }

    private static function uuid(mixed $valor, int $numero, string $que): Uuid
    {
        $texto = Lee::texto($valor);

        try {
            return Uuid::fromString($texto ?? '');
        } catch (\InvalidArgumentException) {
            throw new InvalidArgumentException(sprintf('Fila %d: el id de %s no es válido.', $numero, $que));
        }
    }

    private static function hora(mixed $valor, int $numero, string $que): DateTimeImmutable
    {
        $texto = Lee::texto($valor);

        try {
            // ⚠️ Sin el `null` explícito: `new DateTimeImmutable('')` no falla, es AHORA.
            if ($texto === null) {
                throw new \InvalidArgumentException();
            }

            return new DateTimeImmutable($texto);
        } catch (\Exception) {
            throw new InvalidArgumentException(sprintf('Fila %d: no entiendo la %s «%s».', $numero, $que, $texto ?? ''));
        }
    }
}
