<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillInterface;

/**
 * Las dos guardas del empate de herramientas: cuándo hay que preguntar, y si lo redactado
 * vale para mandárselo a una persona.
 *
 * Vive fuera de {@see \App\Agent\Service\AiConversationProcessor} porque las dos son
 * decisiones puras —entra una lista, sale un sí o un no— y porque son exactamente las dos que
 * fallaron en producción el 18/08/2026. Aquí se pueden probar sin contenedor ni base de
 * datos; dentro del procesador, con sus trece dependencias, no. Ver docs/Mensajeria.md §22.24.
 */
final readonly class AclaracionDeEmpate
{
    /**
     * Un chat, no una ficha. Pasado esto, el modelo ha explicado el dilema en vez de
     * preguntar, y explicar el dilema es justo lo que no se le traslada a quien escribe.
     */
    public const int MAX_CARACTERES = 320;

    /**
     * ¿El empate obliga a preguntar, o se puede adivinar sin coste?
     *
     * ⚠️ La pregunta NO es «¿cuántas empatan?» —eso era la versión que se llevó por delante a
     * una huésped— sino **«¿qué pasa si se elige la que no era?»**:
     *
     * - **Todas leen** → nada. El camino largo lleva las dos en el catálogo y el modelo puede
     *   consultar ambas: adivinar es gratis, y preguntar sólo traslada un problema nuestro.
     * - **Alguna escribe** → se ejecuta sobre lo que no era, y eso no se deshace.
     *
     * `NivelRiesgo::Interna` cuenta como lectura, igual que en el filtro del registro y por el
     * mismo motivo: escribe hacia dentro. Ver {@see NivelRiesgo::exigePermisoDeEscritura()}.
     *
     * @param list<SkillInterface> $empatadas
     */
    public static function obliga(array $empatadas): bool
    {
        if (count($empatadas) < 2) {
            return false;
        }

        foreach ($empatadas as $skill) {
            if ($skill->nivelRiesgo()->exigePermisoDeEscritura()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Por qué NO se puede mandar lo que redactó el modelo. `null` = se puede.
     *
     * 🔑 **Es el cierre por código de un texto que escribió un modelo.** Al prompt no se le
     * pide «no menciones herramientas internas»: eso es supresión, y la supresión es lo que
     * este proyecto lleva demostrando que se incumple. Se le da material limpio y se comprueba
     * el resultado aquí.
     *
     * Devuelve el motivo en vez de un booleano para que quien llama lo escriba en el log: un
     * descarte silencioso es indistinguible de que el motor no contestara.
     *
     * @param list<SkillInterface> $empatadas
     */
    public static function motivoDeDescarte(?string $texto, array $empatadas): ?string
    {
        $limpio = trim((string) $texto);

        if ($limpio === '') {
            return 'el motor no devolvió texto';
        }

        if (mb_strlen($limpio) > self::MAX_CARACTERES) {
            return sprintf('son %d caracteres, y el tope es %d', mb_strlen($limpio), self::MAX_CARACTERES);
        }

        // El nombre técnico es la señal barata de que se ha colado la ficha interna: si aparece
        // «consultar_guia», lo de alrededor tampoco estaba escrito para nadie.
        foreach ($empatadas as $skill) {
            if (mb_stripos($limpio, $skill->nombre()) !== false) {
                return sprintf('nombra la herramienta «%s»', $skill->nombre());
            }
        }

        return null;
    }
}
