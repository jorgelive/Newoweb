<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

/**
 * De «dónde cae la cabecera del documento» a «cuántos grados hay que girar», y vuelta.
 *
 * 🔥 **Existe porque esta conversión llegó a estar en tres sitios y el front leía un cuarto.** Al
 * modelo se le pregunta una posición —`arriba`, `derecha`, `abajo`, `izquierda`— porque los grados
 * los razona mal; los grados los calculamos nosotros. Pero ese cálculo acabó repetido en el lector
 * y en el girador, y la pantalla leía una clave `rotacion` que **el modelo ya no devolvía**:
 * `datos_leidos` tenía `bordeSuperior` en 211 de 211 lecturas y `rotacion` en **cero**, así que
 * toda la interfaz de giro estaba invisible sin dar un solo error.
 *
 * Aquí está la única definición. La entidad la expone calculada a la API, así que el front no la
 * reimplementa: lee un número.
 *
 * Girar en sentido horario lleva `arriba → derecha → abajo → izquierda → arriba`, así que una
 * cabecera **a la derecha** necesita **270°** para volver arriba.
 */
final class Orientacion
{
    private const GRADOS = [
        'arriba' => 0,
        'izquierda' => 90,
        'abajo' => 180,
        'derecha' => 270,
    ];

    /** Cuántos grados en sentido horario le faltan al escaneo. Lo que no se reconozca es 0. */
    public static function grados(?string $bordeSuperior): int
    {
        return self::GRADOS[strtolower(trim((string) $bordeSuperior))] ?? 0;
    }

    /** El camino de vuelta, para dejar la lectura al día tras girar sin volver a leer. */
    public static function borde(int $gradosPendientes): string
    {
        $normalizado = ((($gradosPendientes % 360) + 360) % 360);

        return array_search($normalizado, self::GRADOS, true) ?: 'arriba';
    }
}
