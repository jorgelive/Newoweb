<?php

declare(strict_types=1);

namespace App\Message\Dto\Meta;

/**
 * Cómo se lee un valor suelto del JSON de Meta. Un solo sitio para los cinco DTO del webhook.
 *
 * La regla es la del DTO de Beds24 (`Beds24BookingDto::toStringOrNull()`): **lo que no es del tipo
 * esperado es «no llegó»**, no un `(string)` a ciegas. Un array donde se esperaba texto se
 * convertía en la palabra «Array» y un warning; aquí es `null`, que el persister ya sabe tratar.
 *
 * ⚠️ **No recorta ni convierte el vacío en `null`**, a diferencia del de Beds24. Aquí el persister
 * ya hace su propio `trim()` y sus `?? ''`, y el objetivo de estos DTO es que lean LO MISMO que las
 * expresiones crudas a las que sustituyen — lo comprueba `tools/pruebas/probar-dto-meta.php` contra
 * los payloads reales de la auditoría. Cambiar la normalización es otra decisión, no ésta.
 *
 * Ver `docs/Mensajeria.md` — el webhook de Meta y sus DTO.
 */
final class LeeMeta
{
    public static function texto(mixed $valor): ?string
    {
        if (is_string($valor)) {
            return $valor;
        }

        // Números: Meta manda coordenadas y códigos de error como número. Se pasan a texto igual
        // que los pasaba la interpolación del código anterior (`"{$lat}"`).
        return is_int($valor) || is_float($valor) ? (string) $valor : null;
    }

    public static function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        // Meta manda los timestamps como TEXTO («"1727312345"»).
        return is_string($valor) && is_numeric($valor) ? (int) $valor : null;
    }

    /**
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
}
