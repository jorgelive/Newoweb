<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Agent\Conversation\PerfilConversacion;
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
}
