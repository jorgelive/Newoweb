<?php

declare(strict_types=1);

namespace App\Message\Service\Formato;

/**
 * Sustituye los marcadores `{{ variable }}` de un cuerpo de plantilla por sus valores.
 *
 * ## Por qué es un servicio y no tres métodos privados
 *
 * Porque eran tres: `Beds24SendMappingStrategy`, `WhatsappMetaSendMappingStrategy` y
 * `EmailSendMappingStrategy` tenían cada una su copia, con la MISMA expresión regular y la
 * misma regla. Y la regla no es obvia —costó un fallo en producción—, así que tenerla escrita
 * tres veces es tenerla mal escrita dos veces en cuanto alguien toque una.
 *
 * ## La regla, que es lo único que hay que entender
 *
 * | La clave… | Qué sale | Por qué |
 * |---|---|---|
 * | existe con valor | el valor | lo esperado |
 * | existe y vale `null` o `''` | **nada** | hay variables que valen vacío a propósito: `bloque_pago` cuando no hay nada que cobrar. Sustituir por nada es lo que deja la frase legible |
 * | **no existe** | el marcador crudo | es un fallo de la plantilla y tiene que verse |
 *
 * ⚠️ Es `array_key_exists`, **no `??`**. El `??` trata `null` como ausente y dejaba el marcador
 * en el mensaje: un huésped llegó a leer literalmente «Queda pendiente un pago por
 * {{ importe_a_pagar }}».
 */
final readonly class HidratadorDeMarcadores
{
    /**
     * `{{ var }}` y `{{var}}`, con puntos y guiones en el nombre.
     *
     * Es la misma de las tres estrategias, carácter por carácter: cambiarla aquí las cambia a
     * todas, que es justo el punto.
     */
    private const string PATRON = '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/';

    /**
     * @param array<string, mixed> $variables
     */
    public function hidratar(string $texto, array $variables): string
    {
        if ($variables === [] || !str_contains($texto, '{{')) {
            return $texto;
        }

        return (string) preg_replace_callback(
            self::PATRON,
            static fn (array $m): string => array_key_exists($m[1], $variables)
                ? (string) $variables[$m[1]]
                : $m[0],
            $texto
        );
    }

    /**
     * Los marcadores que este texto pide y las variables NO traen.
     *
     * Se pregunta aparte de hidratar porque los dos que lo quieren lo quieren para cosas
     * distintas: el correo lo escribe en `failedReason` de la cola, y la previsualización lo
     * enseña en rojo. Devolverlo siempre obligaría a los otros dos a ignorar un valor.
     *
     * @param array<string, mixed> $variables
     * @return list<string> Sin repetidos y en el orden en que aparecen.
     */
    public function sinResolver(string $texto, array $variables): array
    {
        if (!str_contains($texto, '{{')) {
            return [];
        }

        preg_match_all(self::PATRON, $texto, $encontrados);

        $faltantes = array_filter(
            $encontrados[1],
            static fn (string $clave): bool => !array_key_exists($clave, $variables)
        );

        return array_values(array_unique($faltantes));
    }
}
