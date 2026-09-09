<?php

declare(strict_types=1);

namespace App\Agent\Vision;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Leer una imagen y devolver datos con la forma que se le pida.
 *
 * 🔑 **Deliberadamente NO pasa por {@see \App\Agent\Conversation\AgentEngineInterface}**, y no es
 * pereza:
 *
 * - `ConversationRequest::$mensaje` es un `string`. Meter imágenes por ahí obligaría a que **los
 *   tres** motores implementaran visión para que uno la usara.
 * - Esto no es una conversación: no hay bucle, ni herramientas, ni historial, ni turno siguiente.
 *   Es una función con una imagen dentro.
 * - **Y no es una cuestión de potencia, que es el otro sitio donde habría cabido.** Los tramos
 *   Alta/Media/Baja miden «cuánta cabeza hace falta»; leer una MRZ y devolver JSON con esquema es
 *   una **capacidad** —multimodal, salida estructurada—, no más cabeza. El modelo más caro sin
 *   visión vale cero aquí, así que el eje no aplica.
 *
 * El contrato es transversal a propósito: **no sabe qué es un pasaporte**. Quien conoce los campos
 * de un documento de identidad es `src/Cotizacion/Documento/`, igual que manda la regla de
 * «Dominios y contratos» de `CLAUDE.md`. Aquí sólo entra «esta imagen, esta forma».
 */
#[AutoconfigureTag('app.lector_de_imagen')]
interface LectorDeImagenInterface
{
    /** Con qué proveedor se leyó, para poder decirlo en el rastro y en los logs. */
    public function nombre(): string;

    /**
     * ¿Hay credenciales? Sin esto, la alternativa sería enterarse por una excepción en mitad de
     * una carga de cien documentos.
     */
    public function estaConfigurado(): bool;

    /**
     * @param string $bytes Contenido del fichero, tal cual.
     * @param string $mime `image/webp`, `image/jpeg`, `application/pdf`…
     * @param string $instruccion Qué se busca, en prosa.
     * @param array<string, mixed> $esquema Forma exacta de la respuesta (JSON Schema).
     * @return array<string, mixed> Lo que devolvió, ya decodificado y con la forma del esquema.
     *
     * @throws RuntimeException Si el proveedor falla o devuelve algo que no es el esquema. Nunca
     *         devuelve datos a medias: media ficha de identidad es peor que ninguna, porque se
     *         guarda igual y nadie vuelve a mirarla.
     */
    public function leer(string $bytes, string $mime, string $instruccion, array $esquema): array;
}
