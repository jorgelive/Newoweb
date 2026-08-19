<?php

declare(strict_types=1);

namespace App\Agent\Contract;

use App\Agent\Access\ActorInterface;
use App\Agent\Conversation\PerfilConversacion;
use App\Contract\MapaDeHitos;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * El bloque de prompt que depende DEL NEGOCIO, no de con quién se habla.
 *
 * ### Por qué existe
 *
 * `PerfilConversacion` mezclaba dos ejes. Uno es la relación —prospecto, interesado, huésped,
 * compañero de oficina, colaborador de campo—, que es universal: existe igual en alojamiento,
 * en tours y en soporte. El otro es el negocio: «casita», «check out», «consultar_mi_reserva».
 * Los cinco prompts estaban escritos con los dos entrelazados, así que cada perfil nuevo que
 * se añadía nacía hotelero y había que reescribirlo entero para el segundo dominio.
 *
 * Separarlos deja el enum diciendo QUIÉN escribe y esta interfaz diciendo DE QUÉ se habla. Un
 * módulo de tours implementa esto y hereda los cinco perfiles sin tocar el agente.
 *
 * ### Qué NO va aquí
 *
 * Lo que valga para cualquier negocio: no inventar datos, no dictar de memoria un número de
 * cuenta, no hablar de otros clientes. Eso vive en las reglas comunes del agente y se aplica
 * antes que este bloque, que puede endurecerlas pero nunca aflojarlas.
 */
#[AutoconfigureTag('app.agent.instrucciones_dominio')]
interface InstruccionesDeDominioInterface
{
    /**
     * @param string|null $contextType El de la conversación. `null` cuando no hay contexto
     *        —un prospecto—, y entonces decide {@see self::esPorDefecto()}.
     */
    public function supports(?string $contextType): bool;

    /**
     * ¿Es el dominio al que se atiende cuando no hay contexto que mirar?
     *
     * Exactamente uno debe decir que sí. Es el que atiende a quien todavía no es de nadie: un
     * número desconocido preguntando precios no trae `context_type`, y aun así hay que
     * venderle algo concreto.
     */
    public function esPorDefecto(): bool;

    /** El bloque para este perfil. Cadena vacía si al dominio no le aplica. */
    public function para(PerfilConversacion $perfil): string;

    /**
     * Lo que hay que saber de ESTA conversación ahora mismo, para el bloque volátil.
     *
     * En alojamiento es en qué punto va la estancia —«SE VA HOY a las 10:00», «SE FUE AYER»— y
     * qué pasa alrededor de sus fechas. Un tour diría otra cosa: si sale mañana, si ya volvió.
     *
     * Vive aquí y no en el agente porque calcularlo exige conocer el negocio: el agente sólo
     * sabe pegar la cadena en el sitio correcto. Antes estaba dentro de
     * `AiConversationProcessor`, que para ello importaba `PmsReserva` y `PmsEspacioEstancia` y
     * comparaba `context_type` contra `'pms_reserva'` a pelo — tres acoplamientos al PMS en el
     * corazón del agente, justo donde peor sientan.
     *
     * ⚠️ Va FUERA del bloque cacheado del prompt: cambia en cada conversación y a veces en cada
     * turno. Devuelve cadena vacía cuando no hay nada que decir, y las líneas empiezan por
     * salto de línea porque se concatenan a un contexto que ya viene empezado.
     *
     * Lo que aquí se dice son HECHOS. Cuánta flexibilidad cabe con ellos es política, y vive
     * en la guía; mezclarlos deja dos fuentes diciendo cosas distintas sin nadie que arbitre.
     *
     * ### Por qué recibe esto y no la conversación
     *
     * Hasta el 19/08/2026 recibía `MessageConversation` entera, y de ella usaba tres cosas: los
     * hitos, el `contextType` y el `contextId`. Las dos últimas ya están en el actor, y los
     * hitos tienen su propio tipo desde que existe {@see MapaDeHitos}. O sea que la entidad
     * sobraba —y metía un dominio concreto dentro de un contrato que existe justo para no
     * conocer ninguno—.
     *
     * Su contrato hermano lo tenía bien desde el principio:
     * {@see \App\Agent\Contract\IndiceDeTemasInterface::lineaVolatil()} hace el mismo trabajo
     * y recibe el actor a secas. La asimetría no era una decisión: era que éste se escribió
     * después y tiró de lo que tenía a mano.
     */
    public function contextoVolatil(ActorInterface $actor, MapaDeHitos $hitos): string;
}
