<?php

declare(strict_types=1);

namespace App\Message\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Qué asuntos tiene abiertos un teléfono en ESTE negocio.
 *
 * Mismo patrón que {@see InstruccionesDeDominioInterface} y {@see IndiceDeTemasInterface}: el
 * núcleo no sabe de negocios, los negocios se registran solos por tag y un registro transversal
 * los recorre — {@see \App\Message\Service\EnumeradorDeFrentes}.
 *
 * ── Cada dominio colapsa lo suyo ────────────────────────────────────────────
 * La heurística de «cuál de las tres reservas vivas es la que toca» es conocimiento del negocio
 * y se queda en el negocio: el PMS ya la tiene escrita y probada en
 * `PmsReservaRepository::findVivasByTelefono()` (actual antes que próxima, con log de que había
 * varias). Este contrato **no la reinventa**: la envuelve.
 *
 * ── Devuelve LISTA, y esa es la corrección que costó una vuelta de diseño ───
 * La primera versión devolvía un candidato por negocio, dando por hecho que cada negocio tiene
 * un asunto y que su momento se deduce del vínculo comercial. Es falso: **un huésped alojado con
 * una cotización de ampliación pendiente tiene DOS asuntos vivos en alojamiento**, uno en
 * operación y otro en venta. Con un solo candidato por negocio, ese caso era invisible y el
 * agente contestaba siempre por el que ganara el desempate.
 */
#[AutoconfigureTag('app.message.frentes_dominio')]
interface FrentesPorDominioInterface
{
    /** `hotelero` | `turistico`. */
    public function negocio(): string;

    /**
     * ¿Este `context_type` de conversación es de mi negocio? (`pms_reserva`, `cotizacion_file`…)
     *
     * Mismo método y mismo papel que en {@see InstruccionesDeDominioInterface::supports()}: el
     * núcleo no sabe qué `context_type` pertenece a qué negocio, y no debe saberlo. Aquí sirve
     * para lo contrario de lo habitual —traducir un contexto a un negocio— y así poder decir a
     * qué dominios llega un actor sin ir a la base de datos a buscar sus asuntos vivos.
     */
    public function supports(?string $contextType): bool;

    /**
     * ¿Se le ofrece a CUALQUIERA la puerta de venta de este negocio, tenga o no algo comprado?
     *
     * Casi siempre sí: vender es lo que se hace con los desconocidos. Un negocio que no venda
     * por el chat (operación interna, postventa pura) devuelve `false` y entonces sólo aparece
     * para quien ya tiene algo.
     */
    public function esVendible(): bool;

    /** Cómo se presenta esa puerta: «Reservar o ampliar alojamiento». */
    public function etiquetaDeVenta(): string;

    /**
     * Los asuntos vivos de este teléfono, ya como frentes con su etiqueta y su momento.
     *
     * Vacío es la respuesta normal, no un error: significa que ese teléfono no tiene nada en
     * este negocio. Ordenados por relevancia —el primero es el que el dominio considera «el
     * que toca»—; quién queda marcado `porDefecto` lo decide el enumerador, no el dominio,
     * porque con varios negocios sólo puede haber un defecto global.
     *
     * @return list<Frente>
     */
    public function frentesVivos(?string $telefono): array;
}
