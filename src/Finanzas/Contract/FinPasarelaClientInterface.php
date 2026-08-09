<?php

declare(strict_types=1);

namespace App\Finanzas\Contract;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinPasarela;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Lo único que TODAS las pasarelas comparten de verdad.
 *
 * La interfaz es corta a propósito. Izipay y Culqi no se parecen en el flujo:
 *
 * - **Izipay** (Lyra): el servidor pide un `formToken` de un solo uso, el navegador monta
 *   el formulario con él y habla directamente con la pasarela. Nuestro servidor no vuelve a
 *   intervenir hasta que llega el IPN firmado.
 * - **Culqi**: el navegador captura la tarjeta y devuelve un **token**, y es NUESTRO
 *   servidor el que crea el cargo con ese token. El webhook es un aviso secundario.
 *
 * Forzar los dos flujos en una interfaz común habría producido métodos que una de las dos
 * implementa vacíos — la clase de abstracción que parece limpia y luego hay que deshacer.
 * Lo específico de cada pasarela vive en su cliente (`CulqiClient::cobrarConToken()`) y lo
 * consume su propio controlador.
 *
 * @see \App\Finanzas\Service\FinPasarelaRegistry
 */
#[AutoconfigureTag('finanzas.pasarela_client')]
interface FinPasarelaClientInterface
{
    public function pasarela(): FinPasarela;

    /** ¿Hay credenciales? Lo consulta el registry para no ofrecer pasarelas a medio configurar. */
    public function estaConfigurado(): bool;

    /**
     * Todo lo que el navegador necesita para montar el formulario de ESTA pasarela.
     *
     * La forma del array la decide cada pasarela y la página de `pax` hace un `switch` por
     * `pasarela`: son dos librerías JS distintas y no hay manera honesta de unificarlas.
     * Lo que sí es común es que aquí nunca va un secreto — sólo claves públicas.
     *
     * Ojo: para Izipay esto ABRE un intento de cobro (pide el formToken, que caduca en
     * minutos), así que se llama en cada carga de la página y no se cachea.
     *
     * @return array<string, mixed>
     */
    public function configuracionPago(FinEnlacePago $enlace): array;
}
