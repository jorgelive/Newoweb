<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

/**
 * Cuánta cabeza hace falta para el siguiente paso de un turno.
 *
 * 🔑 **No es una escala de calidad, es una escala de decisión.** Un turno del agente no es un
 * bloque homogéneo: tiene pasos que exigen entender de verdad —qué quiere el huésped, cuál de
 * las veinte skills sirve, si esto es una emergencia— y pasos que son casi mecánicos —pedir el
 * dato que falta, redactar en bonito lo que ya devolvió una herramienta—. Pagarlos todos al
 * precio del más difícil es el error que hace que un chat con IA no salga a cuenta.
 *
 * Cada tramo se resuelve a un `motor:modelo` por variable de entorno
 * ({@see SelectorDePotencia}), así que la asignación se ajusta sin desplegar y puede cruzar
 * proveedores: el triaje en Anthropic y la recolección de datos en Gemini Flash Lite es una
 * configuración perfectamente válida.
 *
 * Qué va en cada tramo:
 *
 * - **Alta** — decidir algo que no se puede deshacer barato, o que exige leer entre líneas.
 *   El triaje puede ir aquí si se ve que el medio confunde una queja con una pregunta.
 * - **Media** — el trabajo normal: elegir herramienta, encadenar dos consultas, responder con
 *   lo que devolvieron. Es el defecto de toda skill que no diga otra cosa, y es exactamente lo
 *   que hoy hace el agente entero.
 * - **Baja** — rellenar huecos y dar formato. Preguntar «¿de qué casita?» no necesita a nadie
 *   caro: la decisión ya está tomada y lo único que queda es pedir un dato concreto.
 *
 * ⚠️ **Bajar de tramo es reversible; subir la factura no.** Si una skill se comporta raro en
 * `Baja`, súbela a `Media` en su {@see \App\Agent\Skill\SkillDefinition} y ya está: no hay
 * ningún sitio más que tocar.
 */
enum PotenciaRequerida: string
{
    case Alta = 'alta';
    case Media = 'media';
    case Baja = 'baja';

    /**
     * Interpreta lo que venga de configuración, con el tramo medio como red.
     *
     * Una variable de entorno mal escrita NO debe dejar al agente sin contestar: se cae al
     * tramo que hoy usa todo el mundo, que es el comportamiento previo a este mecanismo.
     */
    public static function desde(?string $valor): self
    {
        return self::tryFrom(strtolower(trim((string) $valor))) ?? self::Media;
    }
}
