<?php

declare(strict_types=1);

namespace App\Message\Contract;

/**
 * Contrato estricto que cualquier entidad debe cumplir para poder
 * tener una conversación de chat (Reservas, Viajes, Soporte, etc.).
 */
interface MessageContextInterface
{
    // --- IDENTIFICADORES Y CONTACTO BASE ---
    public function getContextType(): string;
    public function getContextId(): string;
    public function getContextLanguage(): string;
    public function getContextName(): ?string;
    public function getContextPhone(): ?string;

    // --- DICCIONARIO AGNÓSTICO (Para el campo JSON) ---
    public function getOrigin(): ?string;
    public function getStatusTag(): ?string;

    /**
     * Qué vínculo tiene con nosotros quien escribe por esta conversación.
     *
     * ### Por qué está en el contrato y no se deduce de `getStatusTag()`
     *
     * Se dedujo así al principio: el agente traducía `inquiry`/`confirmed`/`cancelled` a un
     * vínculo. Pero esos strings son vocabulario del PMS, y `getStatusTag()` los declara como
     * un `?string` libre — de modo que agente y PMS estaban acordando valores mágicos sin que
     * ningún contrato lo dijera.
     *
     * El problema no era estético. Un módulo de tours que emitiera `pagado` o `en_curso` caía
     * en el default conservador y su cliente pagado era tratado como un simple interesado: sin
     * error, sin log, sólo un cliente maltratado.
     *
     * **Qué convierte a alguien en cliente es conocimiento del dominio.** En alojamiento lo
     * decide el estado de la reserva; en un tour puede decidirlo el pago. Por eso lo responde
     * quien lo sabe, y el agente sólo consume el enum.
     *
     * `getStatusTag()` sigue existiendo, pero para lo que nació: una etiqueta corta para
     * pintar el chat y filtrar reglas. No es el oráculo del que cuelga el trato al cliente.
     */
    public function getVinculo(): VinculoComercial;

    /**
     * Agencia mayorista (B2B) dueña del contexto, o null si es venta directa/OTA.
     *
     * Alimenta el filtro `allowedAgencies` de MessageRule. Mientras un contexto
     * devuelva null, cualquier regla que restrinja por agencia simplemente no le
     * aplicará — que es lo correcto para un filtro de inclusión.
     */
    public function getAgencyId(): ?string;

    /**
     * Las fechas clave del asunto: llegada, salida, creación, cancelación…
     *
     * ⚠️ Devuelve {@see MapaDeHitos} y no `array` a propósito. Mientras fue `array`, su tipo de
     * valor no lo sabía nadie —el `@return` que lo decía estaba mal escrito y ningún analizador
     * lo leía—, así que pasar sus valores a un método que esperaba texto compilaba sin protestar
     * y reventaba en producción. Ver `docs/Mensajeria.md` §22.16.
     */
    public function getMilestones(): MapaDeHitos;

    /**
     * @return list<array<string, mixed>>
     */
    public function getItems(): array;
    public function getFinancialTotal(): ?float;
    public function isFinancialCleared(): bool;

    // --- REGLAS DE NEGOCIO DEL CHAT ---
    /**
     * Define si la conversación debe archivarse/cerrarse automáticamente
     * (Ej: Si todos los eventos de la reserva fueron cancelados).
     */
    public function isCancelled(): bool;
}