<?php

declare(strict_types=1);

namespace App\Agent\Provider\Dto;

/**
 * Lo que costó una vuelta al modelo, en tokens, dicho igual para todos los proveedores.
 *
 * Son las cifras de las líneas `Agent (google): …` y `Agent (deepseek): …` de `info.log`, que es de
 * donde se mide el coste real del agente (docs/Agent.md §3.4). **Leerlas distinto cambia la factura
 * que se calcula**, así que cada proveedor las traduce aquí sin reinterpretar nada: ver sus
 * `fromArray()`.
 *
 * ⚠️ `entrada` es el TOTAL, cacheado incluido. Google lo manda así (`promptTokenCount`); DeepSeek lo
 * manda partido en acierto y fallo de caché, y se suman — {@see self::entradaSinCache()} devuelve
 * exactamente el `prompt_cache_miss_tokens` que se registraba.
 */
final readonly class ConsumoDeTokens
{
    public function __construct(
        public int $entrada = 0,
        public int $cacheLeido = 0,
        /**
         * `null` si el proveedor no lo mandó, que NO es lo mismo que 0. El aviso de turno truncado
         * de Google distingue los dos casos («pensamiento: ?») porque es lo que dice si el
         * presupuesto se fue en razonar o en contestar.
         */
        public ?int $pensamiento = null,
        /** Ídem. */
        public ?int $salida = null,
    ) {}

    /** Lo que se pagó a precio entero: la entrada que el caché NO sirvió. */
    public function entradaSinCache(): int
    {
        return $this->entrada - $this->cacheLeido;
    }
}
