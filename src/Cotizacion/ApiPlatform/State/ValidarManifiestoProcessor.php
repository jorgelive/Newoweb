<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Documento\ValidadorDeManifiesto;
use App\Cotizacion\Entity\CotizacionFile;
use RuntimeException;

/**
 * El botón: valida el manifiesto entero contra los escaneos de la bóveda.
 *
 * 🔑 **Se puede pulsar dos veces seguidas sin pagar dos veces**, y por eso puede ser un botón y no
 * un proceso con ceremonia. Dos cosas lo garantizan: lo ya resuelto se salta, y la lectura de cada
 * documento está cacheada en el archivo — así que lo caro se paga una vez por documento en toda su
 * vida, no una vez por pulsación.
 *
 * ⚠️ **Escribe el veredicto y NUNCA corrige el manifiesto.** Si el documento dice `2036` y el
 * manifiesto `2026`, deja escrito que no coinciden y para ahí. Corregir es una decisión de una
 * persona mirando los dos valores: a veces el equivocado será el escaneo, no el padrón.
 *
 * @implements ProcessorInterface<CotizacionFile, CotizacionFile>
 */
final readonly class ValidarManifiestoProcessor implements ProcessorInterface
{
    public function __construct(private ValidadorDeManifiesto $validador) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CotizacionFile
    {
        // El guarda se queda: `read: true` lo hidrata, pero quien llama es el framework con el
        // cuerpo de una petición HTTP. Ver la cabecera de `phpstan-baseline.neon`.
        if (!$data instanceof CotizacionFile) {
            throw new RuntimeException('Se esperaba un expediente.');
        }

        $this->validador->validar($data);

        // Se devuelve el expediente entero: el front repinta el manifiesto con los veredictos
        // nuevos sin una segunda vuelta, que con 135 personas se nota.
        return $data;
    }
}
