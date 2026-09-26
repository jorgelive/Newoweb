<?php

declare(strict_types=1);

namespace App\Agent\Provider\Dto;

/**
 * Una skill que el modelo pidió ejecutar: nombre y argumentos, ya leídos.
 *
 * El modelo escribe los dos, así que ninguno es de fiar: el nombre se valida contra el catálogo del
 * actor en el adaptador de cada proveedor, y los argumentos los lee cada skill con `EntradaDeSkill`.
 * Aquí sólo se garantiza la FORMA.
 */
final readonly class LlamadaAHerramienta
{
    /**
     * @param string $id El que hay que devolver con el resultado. DeepSeek lo exige
     *        (`tool_call_id`); Gemini no lo tiene y va vacío.
     * @param array<string, mixed> $argumentos
     */
    public function __construct(
        public string $id,
        public string $nombre,
        public array $argumentos,
    ) {}

    /**
     * Los argumentos como objeto JSON: sólo las claves de texto.
     *
     * Una skill los lee por NOMBRE, así que una clave numérica —el modelo mandando una lista donde
     * iba un objeto— nunca se habría leído: descartarla aquí no cambia lo que hace la skill, y deja
     * el contrato de `SkillInterface::ejecutar()` (`array<string, mixed>`) diciendo la verdad.
     * Lo que no es un array es «sin argumentos», que es lo que hacían las lecturas de antes.
     *
     * @return array<string, mixed>
     */
    public static function objetoJson(mixed $valor): array
    {
        if (!is_array($valor)) {
            return [];
        }

        $objeto = [];
        foreach ($valor as $clave => $dato) {
            if (is_string($clave)) {
                $objeto[$clave] = $dato;
            }
        }

        return $objeto;
    }
}
