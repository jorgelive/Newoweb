<?php

declare(strict_types=1);

namespace App\Agent\Skill;

use App\Dto\Lee;

/**
 * Lo que el MODELO manda a una skill, leído con tipo.
 *
 * ── Por qué no un DTO por skill ─────────────────────────────────────────────
 * Cada skill declara ya sus parámetros en su `SkillDefinition` —nombre, tipo, si es obligatorio—, y
 * eso es lo que ve el modelo. Un DTO por skill obligaría a escribir cada campo DOS veces: en el
 * esquema y en la clase, y el día que discrepen el modelo mandaría un campo que el código no lee.
 * Por eso aquí se lee por nombre, y `EntradaDeSkillTest` comprueba que **cada nombre que lee una
 * skill está declarado en su definición**: una sola fuente, y el descuido falla en los tests, no en
 * producción.
 *
 * ── La semántica ────────────────────────────────────────────────────────────
 * Es EXACTAMENTE la de las lecturas crudas que sustituye, salvo en lo que estaba roto:
 *
 * | Antes | Ahora | Cambia sólo si… |
 * |---|---|---|
 * | `(string) ($entrada['x'] ?? '')` | `texto('x')` | llega un array: era «Array», es `''` |
 * | `filter_var($entrada['x'] ?? false, FILTER_VALIDATE_BOOL)` | `booleano('x')` | nunca |
 * | `(int) ($entrada['x'] ?? 0)` | `entero('x')` | llega «12abc»: era 12, es 0 |
 *
 * Lo que escribe el modelo es la frontera menos fiable del sistema —se inventa formas con toda la
 * seguridad del mundo—, y estas skills escriben en la base. Ver `docs/TiposDeFrontera.md`.
 */
final readonly class EntradaDeSkill
{
    /** @param array<mixed> $entrada */
    public function __construct(private array $entrada)
    {
    }

    /** ¿Vino el campo, aunque sea vacío? */
    public function tiene(string $nombre): bool
    {
        return isset($this->entrada[$nombre]);
    }

    /**
     * Texto; `''` si no vino. Un booleano sale como lo sacaba el cast de antes (`'1'` o `''`).
     */
    public function texto(string $nombre): string
    {
        $valor = $this->entrada[$nombre] ?? null;

        if (is_bool($valor)) {
            return $valor ? '1' : '';
        }

        return Lee::texto($valor) ?? '';
    }

    /** Texto, o `null` si no vino (o no es texto). */
    public function textoONull(string $nombre): ?string
    {
        $valor = $this->entrada[$nombre] ?? null;

        return $valor === null ? null : $this->texto($nombre);
    }

    public function booleano(string $nombre, bool $siNoViene = false): bool
    {
        return Lee::booleano($this->entrada[$nombre] ?? null) ?? $siNoViene;
    }

    public function entero(string $nombre, int $siNoViene = 0): int
    {
        return Lee::entero($this->entrada[$nombre] ?? null) ?? $siNoViene;
    }

    public function decimal(string $nombre, float $siNoViene = 0.0): float
    {
        return Lee::decimal($this->entrada[$nombre] ?? null) ?? $siNoViene;
    }

    /**
     * Una lista de textos (los `valores` de un filtro, las casitas de una consulta).
     *
     * @return list<string>
     */
    public function textos(string $nombre): array
    {
        return Lee::listaDeTextos($this->entrada[$nombre] ?? null);
    }

    /**
     * Una lista de objetos, cada uno a su vez una entrada (las líneas de una reserva, por ejemplo).
     *
     * @return list<self>
     */
    public function objetos(string $nombre): array
    {
        return array_map(static fn (array $o): self => new self($o), Lee::listaDeMapas($this->entrada[$nombre] ?? null));
    }
}
