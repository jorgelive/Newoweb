<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

/**
 * Trozos de prompt que valen para TODOS los asistentes, estén donde estén.
 *
 * Hay tres puntos de entrada al modelo y cada uno arma su propio prompt de sistema:
 * `AiConversationProcessor::reglas()` (WhatsApp), `PanelAssistant::systemPrompt()` (el chat del
 * panel) y `VoiceAssistant` (Alexa). Escribir una regla en uno y olvidarla en los otros no
 * falla en voz alta: falla el día que alguien entra por la puerta que no la tiene.
 *
 * Ya pasó con la fecha de hoy —sólo el panel la decía, y el de WhatsApp acabó cotizando
 * tarifas del año anterior con total seguridad—. Lo que viva aquí no puede volver a pasarle.
 */
final class ReglasCompartidas
{
    /**
     * Los datos que se copian, nunca se escriben de cabeza.
     *
     * Estaba sólo en el prompt de WhatsApp, y el que MÁS lo necesitaba era el del panel: ahí se
     * le pide al asistente que redacte textos «para copiárselo al huésped», así que un número
     * de cuenta alucinado no lo lee un bot, lo pega un operador que se fía. El mismo incidente,
     * por la puerta de al lado.
     */
    public const string DATOS_QUE_SE_COPIAN = <<<'TXT'
        🔥 DATOS QUE SE COPIAN, NUNCA SE ESCRIBEN DE CABEZA:
        un enlace o dirección web, un número de cuenta, un CCI, un número de Yape o Plin, el
        nombre de un titular, un tipo de cambio, un código de puerta o de caja. Estos SOLO
        pueden salir, carácter a carácter, de lo que acaba de devolverte una herramienta en
        ESTE turno. No los recuerdes de otra conversación, no los deduzcas, no los completes
        «con el formato de siempre» y no pongas un ejemplo «para que se entienda»: alguien va a
        mandar dinero, o a plantarse en una puerta, con lo que tú escribas.
        Si la herramienta no te lo dio, ESE DATO NO EXISTE — dilo y avisa al equipo.
        TXT;

    /**
     * No rellenar un parámetro que nadie ha dicho.
     *
     * Estaba en el panel y en voz, y le faltaba justo al chat del huésped —donde el modelo
     * tiene delante un historial largo del que copiar un `pax` o unas fechas que ya no valen—.
     * Es la misma familia del fallo de cotizar con datos de otro turno.
     */
    public const string NO_INVENTES_PARAMETROS = <<<'TXT'
        ⚠️ NUNCA INVENTES EL VALOR DE UN PARÁMETRO. Si no te lo han dicho en ESTA conversación,
        OMÍTELO al llamar a la herramienta: ella te dirá que falta y con qué palabras
        preguntarlo. No lo rellenes con lo más probable, ni con lo que suele pasar, ni con lo
        que había en una consulta anterior. Un dato que no te han dicho no es un dato: es una
        pregunta pendiente.
        TXT;
}
