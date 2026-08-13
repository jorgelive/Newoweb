<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Agent\Access\ActorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * El catálogo de temas que el triaje puede elegir en la misma llamada.
 *
 * ### Qué resuelve
 *
 * El triaje decide de qué va un mensaje sin llamar a herramientas. Para que pueda además
 * señalar el TEMA exacto —«pregunta por la ducha», no sólo «pregunta algo de la guía»— se le
 * pasa un índice de etiquetas y uuids dentro del prompt.
 *
 * ### Por qué es un contrato y no una clase del PMS
 *
 * Porque el índice era de la guía del alojamiento y vivía dentro de `Triaje`, que para usarlo
 * cruzaba a mano el mapa `porUnidad` con las unidades del huésped, hablaba de «su casita» y
 * comprobaba el nombre literal de una skill del PMS. Cuatro conceptos de alojamiento dentro
 * del clasificador de mensajes, que no debería saber de qué negocio se habla.
 *
 * Un módulo de tours tendrá sus propios temas —punto de encuentro, qué llevar, política de
 * cancelación— y su propia forma de acotar cuáles le tocan a quien pregunta. Lo que el agente
 * necesita de ambos es lo mismo: un bloque de texto, una lista blanca de uuids y una línea
 * volátil.
 *
 * ### El reparto cacheado / volátil
 *
 * {@see self::bloqueParaElPrompt()} tiene que ser **idéntico en todas las conversaciones**:
 * viaja en el prefijo cacheado del triaje, y un bloque que cambie por huésped son dos cachés
 * compitiendo. Todo lo que dependa de quien escribe va en {@see self::lineaVolatil()}.
 */
#[AutoconfigureTag('app.agent.indice_temas')]
interface IndiceDeTemasInterface
{
    public function supports(?string $contextType): bool;

    /**
     * El nombre de la skill que habilita este índice.
     *
     * Sin ella en la lista del actor, el índice no pinta nada: sería ofrecerle elegir un tema
     * que luego no va a poder consultar. Lo declara el dominio para que el triaje no tenga que
     * conocer el nombre de ninguna skill concreta.
     */
    public function skillQueLoHabilita(): string;

    /** Estable entre conversaciones: va en el prefijo cacheado. Vacío si no hay nada. */
    public function bloqueParaElPrompt(): string;

    /**
     * Los uuids de tema que ESTE actor puede consultar de verdad.
     *
     * Es la lista blanca con la que se valida lo que el modelo eligió. Devolver vacío no es un
     * error: significa que no se le puede acotar, y quien valida decidirá si eso descarta el
     * tema o lo deja pasar.
     *
     * @return list<string>
     */
    public function temasPermitidos(ActorInterface $actor): array;

    /**
     * Lo poco del índice que cambia por conversación — «Su casita: Casita 3».
     *
     * Cadena vacía cuando no hay nada que decir. NUNCA entra en el bloque cacheado.
     */
    public function lineaVolatil(ActorInterface $actor): string;

    /**
     * ¿Este dominio ya responde a esto?
     *
     * Existe para que el **conocimiento genérico** —que es la última red antes de escalar— no
     * nazca repitiendo lo que la guía ya contesta mejor. Se consulta al dar de alta una entrada,
     * y devuelve las etiquetas de los temas propios que casan con esas palabras.
     *
     * ⚠️ **Informa, no prohíbe.** Duplicar un tema aquí puede ser lo correcto: la guía está
     * anclada a una casita y con ventana de acceso, así que quien pregunta **sin reserva** —«¿hay
     * estacionamiento?» antes de reservar— no llega a ella. Para ese caso la copia genérica es la
     * respuesta buena, y lo que hay que hacer es **declararla pública** acotándola a los perfiles
     * `prospecto` e `interesado`.
     *
     * Lo que sí es un error es una copia **sin acotar**: entonces un huésped recibiría la versión
     * genérica en lugar de la de su casita, con sus horas y sus códigos resueltos.
     *
     * @return list<string> Etiquetas legibles de los temas que ya lo cubren. Vacío si ninguno.
     */
    public function temasQueCubren(string $texto): array;
}
