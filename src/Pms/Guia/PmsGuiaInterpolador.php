<?php

declare(strict_types=1);

namespace App\Pms\Guia;

/**
 * Resuelve los `{{ placeholders }}` de datos del cuerpo de un ítem, en el
 * servidor y antes de serializar.
 *
 * Espejo de lo que hacía RichContentEngine.interpolateString() en
 * pax/src/core/RichContentEngine.ts. Se movió aquí porque la versión del
 * navegador obligaba a enviarle al cliente el diccionario COMPLETO de valores
 * —códigos de puerta incluidos— para que eligiera cuál pintar. Al hacerlo en
 * PHP, el valor real solo sale del servidor si PmsGuiaAcceso lo permite.
 *
 * Lo que NO toca: los placeholders con dos puntos (`{{ video: ... }}`,
 * `{{ img: ... }}`, `{{ map: ... }}`, `{{ widget: wifi }}`). Son bloques de
 * maquetación y siguen resolviéndose en el front, que es donde están los
 * componentes Vue. La expresión regular los descarta sola: no admite `:`
 * dentro de la clave.
 *
 * OJO al tocar la regex: tiene que seguir siendo idéntica a la del front, o
 * habrá placeholders que un lado sustituye y el otro deja crudos a la vista
 * del huésped.
 */
final class PmsGuiaInterpolador
{
    /** Clave simple entre llaves, tolerante a espacios: `{{ door_code }}`. */
    private const REGEX_CLAVE = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /**
     * Interpola una lista i18n completa.
     *
     * Se conserva la forma `[{language, content}]` en vez de resolver el idioma
     * aquí: el selector de idioma de la guía cambia el texto en caliente y no
     * debe disparar una petición nueva. Cada traducción se interpola con el
     * mensaje de bloqueo de SU idioma.
     *
     * @param array<int, array{language?: string, content?: string}> $contenido
     *
     * @return array<int, array{language: string, content: string}>
     */
    public function interpolar(?array $contenido, PmsGuiaContexto $contexto, PmsGuiaAcceso $acceso): array
    {
        if (empty($contenido)) {
            return [];
        }

        $revelar = $acceso->estaAbierto();
        $resultado = [];

        foreach ($contenido as $entrada) {
            $idioma = (string) ($entrada['language'] ?? PmsGuiaMensajes::IDIOMA_FALLBACK);
            $texto = (string) ($entrada['content'] ?? '');

            $resultado[] = [
                'language' => $idioma,
                'content'  => $this->interpolarTexto($texto, $contexto, $acceso, $idioma, $revelar),
            ];
        }

        return $resultado;
    }

    private function interpolarTexto(
        string $texto,
        PmsGuiaContexto $contexto,
        PmsGuiaAcceso $acceso,
        string $idioma,
        bool $revelar,
    ): string {
        if ('' === $texto || !str_contains($texto, '{{')) {
            return $texto;
        }

        return preg_replace_callback(
            self::REGEX_CLAVE,
            static function (array $m) use ($contexto, $acceso, $idioma, $revelar): string {
                $clave = strtolower($m[1]);

                if (isset($contexto->valores[$clave])) {
                    return self::envolver($contexto->valores[$clave]);
                }

                if (array_key_exists($clave, $contexto->sensibles)) {
                    return $revelar
                        ? self::envolver($contexto->sensibles[$clave])
                        : self::envolver(PmsGuiaMensajes::bloqueo($acceso, $idioma), true);
                }

                // Clave sensible conocida por el editor pero sin valor cargado
                // en esta unidad: se responde con el mensaje de bloqueo en vez
                // de dejar el `{{ door_code }}` crudo delante del huésped.
                if (in_array($clave, self::CLAVES_SENSIBLES, true)) {
                    return self::envolver(PmsGuiaMensajes::bloqueo($acceso, $idioma), true);
                }

                // Desconocida: se deja tal cual, igual que hacía el front. Es
                // una errata del editor y tiene que verse en la revisión.
                return $m[0];
            },
            $texto,
        ) ?? $texto;
    }

    /**
     * Claves que SIEMPRE son sensibles, aunque la unidad no tenga el dato
     * cargado. Sin esta lista, una unidad sin `codigoCaja` filtraría el
     * placeholder crudo en lugar del mensaje de bloqueo.
     */
    private const CLAVES_SENSIBLES = ['door_code', 'safe_code', 'keybox_main', 'keybox_sec'];

    /**
     * El valor se envuelve en un `<span>` para que herede el estilo que ya
     * usaba el front (destacado dentro del párrafo). `$bloqueado` lo pinta
     * apagado, que es la señal visual de "esto todavía no".
     */
    private static function envolver(string $valor, bool $bloqueado = false): string
    {
        $clase = $bloqueado ? 'guia-dato guia-dato--bloqueado' : 'guia-dato';

        return sprintf('<span class="%s">%s</span>', $clase, htmlspecialchars($valor, ENT_QUOTES, 'UTF-8'));
    }
}
