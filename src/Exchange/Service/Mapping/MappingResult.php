<?php
declare(strict_types=1);

namespace App\Exchange\Service\Mapping;

use App\Exchange\Service\Contract\ChannelConfigInterface;

final readonly class MappingResult
{
    /**
     * @param array<array-key, mixed> $payload El cuerpo de la petición, con la forma que pida el
     *                                         canal: mapa en Beds24, lista agrupada en tarifas,
     *                                         vacío en las tareas que sólo leen.
     *
     * @param array<array-key, string|list<string>> $correlationMap Qué ítem de la cola generó
     *        cada parte de la petición, para repartir la respuesta al volver.
     *
     * ⚠️ **La forma la fija cada estrategia y NADIE más la lee.** Comprobado en las ocho:
     * `Beds24Receive` e `InvoiceReceive` guardan `[bookId => [ids]]`; `EmailSend` guarda
     * `[clave => clave]`; `RatesNested` mezcla índices numéricos con `skipped_N`; `BookingsPull`
     * guarda `['job' => id]`. Cada `parseResponse()` lee el mapa que construyó su propio
     * `map()`, así que las formas no se cruzan — y por eso el tipo es la unión y no una de
     * ellas. Estrecharlo a la que uno tenga delante rompería las otras siete.
     *
     * @param array<string, mixed> $metadata Datos sueltos que la estrategia quiera arrastrar.
     */
    public function __construct(
        public string $method,
        public string $fullUrl, // URL completa: Base + Endpoint
        public array $payload,
        public ChannelConfigInterface $config,
        public array $correlationMap,
        public array $metadata = []
    ) {}

    /**
     * El id de cola guardado bajo esa clave, cuando la estrategia guardó un id suelto.
     *
     * `correlationMap` es una unión —cada estrategia le da su forma— y **casi todas guardan un
     * id por clave**; sólo `Beds24Receive` e `InvoiceReceive` guardan listas, y ésas lo recorren
     * de otra manera. Sin este lector, las cinco que sí lo hacen así repetían el mismo
     * `is_string()` para convencer al analizador, que es cinco copias de una decisión.
     *
     * ⚠️ Lanza si lo que hay no es un id: llegados aquí, un mapa mal construido significa que la
     * respuesta del canal no se va a poder repartir entre sus ítems, y eso no debe seguir en
     * silencio — se quedarían todos en `processing` para siempre.
     */
    public function idDeCola(string|int $clave): string
    {
        $valor = $this->correlationMap[$clave] ?? null;

        if (!is_string($valor) || $valor === '') {
            throw new \LogicException(sprintf(
                'El mapa de correlación no trae un id de cola en «%s»; trae %s.',
                (string) $clave,
                get_debug_type($valor),
            ));
        }

        return $valor;
    }
}