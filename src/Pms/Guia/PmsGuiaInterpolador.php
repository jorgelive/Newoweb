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
 * **Con UNA excepción**: `{{ video_ventana: url }}` e `{{ img_ventana: url }}`.
 * Ahí no se resuelve el bloque —eso sigue siendo del front— pero sí se decide
 * si la URL sale del servidor. Ver resolverMediaVentana().
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
     * Bloques de maquetación cuya URL solo puede viajar con la ventana abierta:
     * `{{ video_ventana: url }}` y `{{ img_ventana: url }}`.
     *
     * Son la excepción a la regla de "los bloques con `:` los resuelve el
     * front": aquí no se resuelve el bloque, se decide si la URL sale del
     * servidor. Dejar que el navegador reciba el enlace y "decida no pintarlo"
     * sería el mismo fallo que tenía interpolateString() con los códigos de
     * puerta — basta abrir las herramientas de desarrollo para leerlo.
     */
    private const REGEX_MEDIA_VENTANA = '/\{\{\s*(video_ventana|img_ventana)\s*:\s*(.+?)\s*\}\}/i';

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
     *
     * @param  list<array{language?: string, content?: string|null}>|null $contenido
     * @return list<array{language: string, content: string}>
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

    /**
     * Un texto suelto, no la tabla de idiomas.
     *
     * Existe para `agente_contenido`, que es UN texto y no un contenido multiidioma: el editor lo
     * escribe en el idioma en que quiere que el modelo lo lea. Sin esta puerta, ese campo no podía
     * referirse a NINGÚN dato —ni al número de la llave, ni al croquis— y la única salida era
     * teclearlos a mano, que es poner el dato en dos sitios: exactamente lo que dejó al agente
     * anunciando «el código de la puerta es #5».
     *
     * ⚠️ **`$revelar` se calcula aquí dentro, igual que en {@see self::interpolar()}.** Es la
     * regla que decide si un código sale o sale tapado, y recibirla por parámetro sería dejar que
     * cada llamador se equivoque una vez.
     */
    public function interpolarUno(
        string $texto,
        PmsGuiaContexto $contexto,
        PmsGuiaAcceso $acceso,
        string $idioma = PmsGuiaMensajes::IDIOMA_FALLBACK,
    ): string {
        return $this->interpolarTexto($texto, $contexto, $acceso, $idioma, $acceso->estaAbierto());
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

        $texto = $this->resolverMediaVentana($texto, $acceso, $idioma, $revelar);

        // Los medios de la casita van ANTES que las claves simples: `{{ croquis }}` no es un dato
        // que se pinte como texto, es una imagen. Aquí se convierte en el bloque que el front ya
        // sabe pintar y ya no llega abajo.
        $texto = $this->resolverMediaDeLaCasita($texto, $contexto, $acceso, $idioma, $revelar);

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
     * Los medios de la casita (`{{ croquis }}`, `{{ foto_puerta }}`, `{{ video_ingreso }}`,
     * `{{ video_caja_llaves }}`) pasan a ser el bloque que el front pinta.
     *
     * ── Por qué una clave simple y no `{{ img_ventana: url }}` ──────────────
     * Porque la URL tiene que vivir **en la casa, no en el texto**. Escrita dentro del contenido
     * hay que repetirla en los siete idiomas de `descripcion`, y cambiar un vídeo son siete
     * ediciones por ítem y por casita. Con la clave, el editor escribe `{{ croquis }}` y la URL
     * sale de {@see \App\Pms\Entity\PmsUnidadMedia}.
     *
     * El desenlace es el mismo que el de `{{ video_ventana: … }}` y se reutiliza a propósito:
     * ventana abierta → `{{ img: url }}` / `{{ video: url }}`; cerrada → el marco con el mensaje,
     * **sin que la URL viaje**. El front no se entera de que había una condición.
     *
     * ⚠️ **Un medio PÚBLICO que falta se quita del texto; uno CON VENTANA sale como bloqueado.**
     * La diferencia importa: decirle a alguien «esto se te mostrará más adelante» cuando en
     * realidad no existe es mentirle. El hueco de un croquis que falta se ve donde se arregla —la
     * pantalla de medios—, no en la guía del huésped. Ver `docs/PmsGuiaHuesped.md` §3.c.
     */
    private function resolverMediaDeLaCasita(
        string $texto,
        PmsGuiaContexto $contexto,
        PmsGuiaAcceso $acceso,
        string $idioma,
        bool $revelar,
    ): string {
        foreach (self::MEDIOS_DE_LA_CASITA as $clave => $esVideo) {
            if (!str_contains($texto, $clave)) {
                continue;
            }

            // Quién puede verlo lo decidió el TIPO al construir el contexto y lo resuelve el mismo
            // juez que para los ítems: `PmsGuiaAcceso::permite()`. Aquí no se vuelve a decidir.
            $medio = $contexto->medios[$clave] ?? null;

            if ($medio === null) {
                // Un medio que todavía no existe —una casita sin croquis— NO pinta un marco de
                // «bloqueado»: eso diría «se te mostrará más adelante» sobre algo que no hay. Se
                // quita el marcador. El hueco se ve donde se arregla, en la pantalla de medios.
                $bloque = '';
            } elseif ($acceso->permite($medio['nivel'])) {
                $bloque = sprintf('{{ %s: %s }}', $esVideo ? 'video' : 'img', $medio['valor']);
            } else {
                $bloque = sprintf(
                    '{{ %s: %s }}',
                    $esVideo ? 'videobloqueado' : 'imgbloqueado',
                    trim(PmsGuiaMensajes::bloqueo($acceso, $idioma), '[]')
                );
            }

            $texto = (string) preg_replace(
                sprintf('/\{\{\s*%s\s*\}\}/i', preg_quote($clave, '/')),
                str_replace('$', '\$', $bloque),
                $texto
            );
        }

        return $texto;
    }

    /**
     * Vídeos e imágenes con ventana: se reescribe la CLAVE, no el bloque.
     *
     * - Ventana abierta → se degrada a `{{ video: url }}` / `{{ img: url }}`,
     *   los bloques de siempre. El front no se entera de que había una condición.
     * - Ventana cerrada → `{{ videobloqueado: mensaje }}` / `{{ imgbloqueado: … }}`.
     *   **La URL no viaja**: en su lugar va el texto que el front pinta centrado
     *   sobre un marco con la forma del medio.
     *
     * Se le quitan los corchetes al mensaje: existen para que un dato bloqueado
     * se distinga en mitad de una frase, pero dentro de un marco grande y
     * centrado sobran.
     */
    private function resolverMediaVentana(
        string $texto,
        PmsGuiaAcceso $acceso,
        string $idioma,
        bool $revelar,
    ): string {
        if (!str_contains($texto, '_ventana')) {
            return $texto;
        }

        return preg_replace_callback(
            self::REGEX_MEDIA_VENTANA,
            static function (array $m) use ($acceso, $idioma, $revelar): string {
                $esVideo = str_starts_with(strtolower($m[1]), 'video');

                if ($revelar) {
                    return sprintf('{{ %s: %s }}', $esVideo ? 'video' : 'img', $m[2]);
                }

                return sprintf(
                    '{{ %s: %s }}',
                    $esVideo ? 'videobloqueado' : 'imgbloqueado',
                    trim(PmsGuiaMensajes::bloqueo($acceso, $idioma), '[]'),
                );
            },
            $texto,
        ) ?? $texto;
    }

    /**
     * Claves que SIEMPRE son sensibles, aunque la unidad no tenga el dato
     * cargado. Sin esta lista, una unidad sin `codigoCaja` filtraría el
     * placeholder crudo en lugar del mensaje de bloqueo.
     */
    private const CLAVES_SENSIBLES = ['door_code', 'numero', 'codigo_caja_casita', 'codigo_caja_llaves', 'codigo_caja_dinero'];

    /**
     * Los medios de la casita y si cada uno es vídeo. Salen de `PmsUnidadMedia` y del
     * establecimiento, no del texto — ver `resolverMediaDeLaCasita()`.
     *
     * ⚠️ Aquí **no** se dice cuál exige ventana: eso lo decide `PmsUnidadMediaTipo::esSensible()` y
     * se nota en si la clave llegó en `valores` o en `sensibles`. Repetirlo aquí sería tener la
     * misma regla en dos sitios, y el día que un tipo cambie de lado sólo cambiaría uno.
     */
    private const MEDIOS_DE_LA_CASITA = [
        'croquis'           => false,
        'foto_puerta'       => false,
        'video_ingreso'     => true,
        // Del EDIFICIO, no de la casita, pero se sirven igual. Sólo las de las LLAVES: las de la
        // caja del DINERO no entran en el contexto —`PmsEstablecimientoMediaTipo::visibilidad()`
        // devuelve `null`—, así que aunque alguien escriba `{{ foto_caja_dinero }}` no hay valor
        // que resolver y el marcador se quita.
        'video_caja_llaves' => true,
        'foto_caja_llaves'  => false,
    ];

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
