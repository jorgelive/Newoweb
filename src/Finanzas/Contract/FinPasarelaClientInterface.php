<?php

declare(strict_types=1);

namespace App\Finanzas\Contract;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinPasarela;
use RuntimeException;
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
 * ⚠️ **«Corta» no es «floja», y la distinción está en el porqué del hueco.** `cobrarConToken()`
 * no está aquí porque el flujo de Izipay *no tiene* ese paso: pedirle que lo implemente sería
 * inventarle un concepto. `reembolsar()` **sí** está, aunque hoy Izipay lo deje lanzando,
 * porque devolver dinero no es una peculiaridad de Culqi: es algo que cualquier pasarela de
 * tarjeta hace, y lo único que le falta a Izipay es estar implementado.
 *
 * La regla que sale de ahí: se deja fuera lo que a una pasarela **no le aplica**; no lo que
 * simplemente **no está escrito todavía**. Un contrato no se afloja por el estado de una
 * implementación — se afloja y el hueco desaparece de la vista.
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

    /**
     * Devuelve el dinero de un enlace ya cobrado.
     *
     * ── Va en el contrato COMÚN, no en una capacidad opcional ────────────────────
     * Se planteó como interfaz aparte (`FinPasarelaReembolsable`) para que Izipay —parada—
     * no tuviera que implementarla, y la decisión se revirtió: **un contrato no se afloja
     * por el estado de una implementación.** Devolver dinero no es una rareza de Culqi, es
     * algo que cualquier pasarela de tarjeta hace; declararlo opcional habría escondido el
     * hueco de Izipay detrás de un `instanceof` que nadie mira.
     *
     * Declarado aquí, el hueco tiene nombre y sale en el editor: `IzipayClient::reembolsar()`
     * existe, está vacío a propósito y su docblock dice por qué. El día que Izipay se
     * habilite, la lista de lo que falta es la lista de métodos que lanzan.
     *
     * ⚠️ Se devuelve el **neto**, no el total: el recargo fue el coste de pagar con tarjeta
     * —anunciado al cliente antes de pulsar— y la pasarela no lo reintegra. Ver
     * `FinEnlacePago::montoNetoCentimos()`.
     *
     * ⚠️ Una implementación que **no sepa** devolver debe **lanzar**, nunca devolver un array
     * vacío. Un método de dinero que finge haber trabajado deja el enlace marcado como
     * reembolsado sobre una devolución que no ocurrió, y eso no lo descubre nadie hasta que
     * el cliente reclama.
     *
     * @param string $motivo Lo que escribió el operador. Cada pasarela decide si le sirve:
     *                       Culqi tiene un enum cerrado de tres valores y lo ignora, quedando
     *                       el texto en el asiento del módulo.
     *
     * @return array<string, mixed> La respuesta cruda de la pasarela, para auditoría.
     * @throws RuntimeException si la pasarela rechaza la devolución o no sabe hacerlas.
     */
    public function reembolsar(FinEnlacePago $enlace, string $motivo = ''): array;
}
