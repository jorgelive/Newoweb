<?php

declare(strict_types=1);

namespace App\Message\Service\Formato;

/**
 * El formato de los mensajes de texto libre, y su degradación por canal.
 *
 * ### El canónico es el de WhatsApp
 *
 * Un mensaje libre —del operador en el chat, del agente de IA, de una skill— se escribe UNA
 * vez y sale por varios canales. El formato en que se guarda (`contentExternal`) es el de
 * WhatsApp, porque es el único canal de salida con formato propio:
 *
 *   *negrita*   _cursiva_   ~tachado~   __subrayado__
 *
 * `__subrayado__` es marca NUESTRA, no de WhatsApp (WhatsApp no tiene subrayado): el panel la
 * pinta como <u>, y los canales la degradan quitando las marcas.
 *
 * ### Quién llama a qué
 *
 * | Salida | Método | Dónde |
 * |---|---|---|
 * | WhatsApp | {@see self::paraWhatsapp()} | `WhatsappMetaSendMappingStrategy` (texto libre) |
 * | Beds24 (texto puro) | {@see self::paraTextoPlano()} | `Beds24SendMappingStrategy` (texto libre) |
 * | Panel (HTML) | espejo TS: `util/src/utils/formatoDeTexto.ts` | `ChatView.vue` |
 *
 * 🔁 **Espejo PHP ↔ TS.** Las reglas de `normalizar()` y las marcas viven también en
 * `util/src/utils/formatoDeTexto.ts` para pintar el HTML del panel. Si tocas una, toca la
 * otra, o el operador verá un formato distinto del que recibe el huésped.
 *
 * ### ⚠️ Las plantillas NO pasan por aquí
 *
 * Cada plantilla tiene su cuerpo POR CANAL, ya escrito con el formato que le toca (el de
 * Beds24 sin marcas, el de WhatsApp con ellas). Degradarlas de nuevo rompería texto pensado a
 * mano. Las estrategias sólo formatean la rama de texto libre (`getTemplate() === null`).
 *
 * ### `normalizar()`: la red para lo que escribe el modelo
 *
 * Los modelos emiten Markdown aunque el prompt lo prohíba: `**negrita**`, `# títulos`,
 * listas con `- `, enlaces `[texto](url)`. La normalización lo traduce al canónico ANTES de
 * degradar, para que al huésped nunca le lleguen los asteriscos dobles literales. Es una red,
 * no un permiso: el prompt sigue pidiendo texto de chat, sin listas ni títulos.
 */
final readonly class FormatoDeTexto
{
    /**
     * Markdown de modelo → canónico WhatsApp. Idempotente sobre texto ya canónico.
     */
    public function normalizar(string $texto): string
    {
        // Enlaces [texto](url) → «texto: url». Antes que nada, para que las marcas dentro del
        // texto del enlace se procesen después como cualquier otra. La URL puede venir ya
        // convertida en marcador \x00N\x00 por conUrlsProtegidas(): se aceptan las dos formas.
        $texto = preg_replace(
            '/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+|\x00\d+\x00)\)/u',
            '$1: $2',
            $texto
        ) ?? $texto;

        // `# Título` → «*Título*»: el título pierde la jerarquía pero conserva el énfasis.
        $texto = preg_replace('/^#{1,6}\s+(.+?)\s*$/mu', '*$1*', $texto) ?? $texto;

        // Triple asterisco `***x***` (negrita + cursiva en Markdown) → `_*x*_`
        $texto = preg_replace('/\*\*\*(?=\S)([^*\n]*\S)\*\*\*/u', '_*$1*_', $texto) ?? $texto;

        // `**x**` y `__…__` de Markdown son negrita; nuestro `__x__` es subrayado. La negrita
        // doble se colapsa ANTES de tratar guiones bajos: `**x**` → `*x*`, `~~x~~` → `~x~`.
        $texto = preg_replace('/\*\*(?=\S)([^*\n]*\S)\*\*/u', '*$1*', $texto) ?? $texto;
        $texto = preg_replace('/~~(?=\S)([^~\n]*\S)~~/u', '~$1~', $texto) ?? $texto;

        // Viñetas `- item` / `* item` → «• item». El guion pide espacio detrás, así que no
        // toca rangos («2-3») ni la negrita («*x*» no lleva espacio tras el asterisco).
        $texto = preg_replace('/^[ \t]*[-*]\s+/mu', '• ', $texto) ?? $texto;

        return $texto;
    }

    /**
     * Canónico → WhatsApp: todo tal cual, menos el subrayado, que WhatsApp no tiene.
     */
    public function paraWhatsapp(string $texto): string
    {
        return $this->conUrlsProtegidas(
            $texto,
            fn (string $t): string => $this->sinSubrayado($this->normalizar($t))
        );
    }

    /**
     * Canónico → texto puro (Beds24 y cualquier canal sin formato): fuera todas las marcas.
     *
     * Los mensajes de Beds24 acaban en la bandeja de Airbnb o Booking, que los muestran tal
     * cual: un `*hola*` llegaría con los asteriscos puestos.
     */
    public function paraTextoPlano(string $texto): string
    {
        return $this->conUrlsProtegidas($texto, function (string $t): string {
            $t = $this->sinSubrayado($this->normalizar($t));

            foreach (['*', '_', '~'] as $marca) {
                $t = $this->sinMarca($t, $marca);
            }

            return $t;
        });
    }

    /**
     * Aparta las URLs, aplica la transformación y las devuelve intactas.
     *
     * Una URL lleva guiones bajos y asteriscos con todo el derecho (`/mi_guia`), y el quitado
     * de marcas la mutilaría en silencio — y un enlace roto es peor que cualquier asterisco
     * de más. El marcador usa \x00, que no puede venir en texto de un chat.
     *
     * @param callable(string): string $transformar
     */
    private function conUrlsProtegidas(string $texto, callable $transformar): string
    {
        $urls = [];

        $texto = preg_replace_callback(
            '/https?:\/\/\S+/u',
            static function (array $m) use (&$urls): string {
                // Lo pegado al final —«mira *url*», «visita url.»— es texto, no URL: la marca
                // o el punto vuelven fuera para que el quitado de marcas los vea en pareja.
                $url = rtrim($m[0], '*_~.,;:!?)');
                $cola = substr($m[0], strlen($url));

                $urls[] = $url;

                return "\x00" . (count($urls) - 1) . "\x00" . $cola;
            },
            $texto
        ) ?? $texto;

        $texto = $transformar($texto);

        return preg_replace_callback(
            '/\x00(\d+)\x00/',
            static fn (array $m): string => $urls[(int) $m[1]] ?? '',
            $texto
        ) ?? $texto;
    }

    /** El subrayado va primero: `__x__` contiene `_x_` y el orden decide qué se interpreta. */
    private function sinSubrayado(string $texto): string
    {
        return preg_replace('/__(?=\S)([^_\n]*\S)__/u', '$1', $texto) ?? $texto;
    }

    /**
     * Quita UNA marca emparejada, sólo cuando envuelve contenido real (sin espacio pegado a
     * la marca, igual que exige WhatsApp). Un asterisco suelto —«2 * 3»— se queda como está.
     */
    private function sinMarca(string $texto, string $marca): string
    {
        $m = preg_quote($marca, '/');

        return preg_replace(
            sprintf('/%s(?=\S)([^%s\n]*\S)%s/u', $m, $m, $m),
            '$1',
            $texto
        ) ?? $texto;
    }
}
