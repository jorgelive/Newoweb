<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Cómo se lee un valor suelto de un JSON que viene de FUERA. La única regla, para todos los DTO.
 *
 * ── Dónde se usa, y dónde no ────────────────────────────────────────────────
 * **Dentro de los `fromArray()` de los DTO de frontera** —webhooks, respuestas de APIs, la entrada
 * que escribe el modelo—, que es el único sitio donde se toca el array crudo. Fuera de ahí no: el
 * resto del código trabaja con el DTO ya tipado. Si un lector genérico empieza a aparecer en la
 * lógica de negocio, falta un DTO. Ver `docs/TiposDeFrontera.md`.
 *
 * ── La regla ────────────────────────────────────────────────────────────────
 * **Lo que no es del tipo esperado es «no llegó» (`null`), no un `(string)` a ciegas.** Un array
 * donde se esperaba texto era, con el cast, la palabra «Array» guardada en una reserva y un warning
 * en el log — el fallo que el nivel 9 de PHPStan (`mixed`) obliga a mirar.
 *
 * ⚠️ Dos lecturas de texto, a propósito distintas:
 * - {@see self::texto()} devuelve el texto TAL CUAL. Para sustituir una lectura cruda sin cambiar lo
 *   que se guarda (los DTO de Meta se comprobaron así contra 3 250 webhooks reales).
 * - {@see self::textoLimpio()} recorta y convierte el vacío en `null`. Es la de `Beds24BookingDto`:
 *   que «vacío» se guarde de UNA forma y no de dos según por dónde entró.
 *
 * Elegir una u otra es una decisión sobre los datos: cambiar la primera por la segunda en un DTO
 * existente cambia lo que se guarda.
 */
final class Lee
{
    /** Texto tal cual. Los números pasan a texto como lo haría una interpolación (`"{$n}"`). */
    public static function texto(mixed $valor): ?string
    {
        if (is_string($valor)) {
            return $valor;
        }

        return is_int($valor) || is_float($valor) ? (string) $valor : null;
    }

    /** Texto recortado; vacío o sólo espacios es `null`. */
    public static function textoLimpio(mixed $valor): ?string
    {
        $texto = self::texto($valor);
        if ($texto === null) {
            return null;
        }

        $texto = trim($texto);

        return $texto === '' ? null : $texto;
    }

    /** Entero. Acepta el número como texto («"1727312345"»), que es como mandan los timestamps. */
    public static function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        if (is_float($valor) && floor($valor) === $valor) {
            return (int) $valor;
        }

        return is_string($valor) && preg_match('/^\s*-?\d+\s*$/', $valor) === 1 ? (int) trim($valor) : null;
    }

    /** Decimal. Acepta el número como texto («"120.50"»), que es como mandan los importes. */
    public static function decimal(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        return is_string($valor) && is_numeric(trim($valor)) ? (float) trim($valor) : null;
    }

    /**
     * Booleano. Acepta las formas en que llega por JSON y por formularios: `true`, `1`, `"1"`,
     * `"true"`, `"yes"`/`"on"` (y sus contrarios). Lo que no sea ninguna es `null`: «no se sabe» no es
     * `false`.
     */
    public static function booleano(mixed $valor): ?bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        // 🔥 **`null` y `''` se miran ANTES de `filter_var()`, y no es redundante.** Con
        // `FILTER_NULL_ON_FAILURE`, `filter_var(null, …)` devuelve `false`, no `null`: un campo que
        // no llegó se leía como «no». En `CuerpoDeEnlace` eso era un enlace de pago SIN recargo
        // cuando el panel no mandaba `conRecargo` —el contrario del valor por defecto—. Lo cazó
        // `DtoDePagosTest` antes de desplegar.
        if ($valor === null || $valor === '' || !is_scalar($valor)) {
            return null;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Un objeto JSON. Lo que no sea un array es un objeto vacío.
     *
     * @return array<mixed>
     */
    public static function mapa(mixed $valor): array
    {
        return is_array($valor) ? $valor : [];
    }

    /**
     * Una lista de objetos: lo que no sea un array dentro de ella se descarta.
     *
     * @return list<array<mixed>>
     */
    public static function listaDeMapas(mixed $valor): array
    {
        if (!is_array($valor)) {
            return [];
        }

        return array_values(array_filter($valor, 'is_array'));
    }

    /**
     * Una lista de textos: lo que no sea texto (o número) dentro de ella se descarta.
     *
     * @return list<string>
     */
    public static function listaDeTextos(mixed $valor): array
    {
        if (!is_array($valor)) {
            return [];
        }

        $textos = [];
        foreach ($valor as $elemento) {
            $texto = self::texto($elemento);
            if ($texto !== null) {
                $textos[] = $texto;
            }
        }

        return $textos;
    }

    /**
     * Un campo anidado por su ruta: `Lee::en($datos, 'value', 'contacts', 0)`. Lo que falte por el
     * camino es `null`, sin avisos.
     */
    public static function en(mixed $valor, string|int ...$ruta): mixed
    {
        foreach ($ruta as $paso) {
            if (!is_array($valor) || !array_key_exists($paso, $valor)) {
                return null;
            }
            $valor = $valor[$paso];
        }

        return $valor;
    }
}
