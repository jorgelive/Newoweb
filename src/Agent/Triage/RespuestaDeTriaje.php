<?php

declare(strict_types=1);

namespace App\Agent\Triage;

use App\Dto\Lee;

/**
 * El JSON que devuelve el modelo al clasificar un mensaje, leído con tipo y SIN validar.
 *
 * Aquí sólo se garantiza la forma; lo que el modelo eligió —la skill, el tema, los candidatos— se
 * valida contra las listas blancas en {@see Triaje::interpretar()}, que es quien las tiene. Separar
 * las dos cosas es a propósito: esto es la frontera (docs/TiposDeFrontera.md), aquello es la regla.
 *
 * Cada campo se lee como se leía con el `(string)` de antes, salvo lo que no es texto —un array
 * donde iba un nombre—, que ahora es «no vino» en vez de la palabra «Array».
 */
final readonly class RespuestaDeTriaje
{
    /**
     * @param list<string> $candidatos
     */
    public function __construct(
        /** Sin recortar: `TipoDeMensaje::tryFrom()` lo quería tal cual. `null` si no vino. */
        public ?string $tipo,
        public string $skill,
        public array $candidatos,
        public string $pista,
        public string $temaId,
        public string $respuesta,
        public string $motivo,
        public string $resumen,
    ) {}

    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): self
    {
        return new self(
            tipo: Lee::texto($datos['tipo'] ?? null),
            skill: self::recortado($datos['skill'] ?? null),
            candidatos: self::candidatos($datos['candidatos'] ?? null),
            pista: self::recortado($datos['pista'] ?? null),
            temaId: self::recortado($datos['tema_id'] ?? null),
            respuesta: self::recortado($datos['respuesta'] ?? null),
            motivo: self::recortado($datos['motivo'] ?? null),
            resumen: self::recortado($datos['resumen'] ?? null),
        );
    }

    private static function recortado(mixed $valor): string
    {
        return trim(Lee::texto($valor) ?? '');
    }

    /**
     * Una lista de nombres. Un nombre suelto donde iba la lista cuenta como lista de uno: es lo
     * que hacía el `(array)` de antes, y un modelo que propone un solo candidato a veces lo manda así.
     *
     * @return list<string>
     */
    private static function candidatos(mixed $valor): array
    {
        if (is_array($valor)) {
            return Lee::listaDeTextos($valor);
        }

        $unico = Lee::texto($valor);

        return $unico !== null ? [$unico] : [];
    }
}
