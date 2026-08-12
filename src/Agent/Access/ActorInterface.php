<?php

declare(strict_types=1);

namespace App\Agent\Access;

use App\Entity\User;
use App\Message\Contract\VinculoComercial;

/**
 * Quién pregunta.
 *
 * Es una interfaz y no sólo la clase concreta porque el origen se amplía: hoy panel y chat,
 * mañana un widget en la web del huésped o un endpoint de socio. Lo que no cambia es lo que
 * el resto del sistema necesita saber — qué roles trae y a qué contexto está atado.
 */
interface ActorInterface
{
    /** @return list<string> */
    public function roles(): array;

    /** `panel`, `whatsapp_meta`, `beds24`… De dónde llegó la pregunta. */
    public function origen(): string;

    /**
     * Contexto al que está atado, si lo hay.
     *
     * Para un huésped es la frontera de lo que puede consultar: sus skills lo usan en lugar
     * de aceptar un parámetro con el que apuntar a otra reserva.
     */
    public function contextoTipo(): ?string;

    public function contextoId(): ?string;

    /**
     * El hilo persistido por el que llegó la pregunta, si lo hay.
     *
     * ⚠️ NO es {@see self::contextoId()} ni lo sustituye. El contexto es la FRONTERA: lo que
     * acota a un huésped a su propia reserva, y lo que un prospecto no tiene a propósito
     * ({@see AgentActor::prospecto()}). Esto es sólo QUÉ CONVERSACIÓN está abierta, un dato
     * de enrutado que no autoriza a leer nada de nadie.
     *
     * Existe porque confundir ambas cosas dejaba a los prospectos sin escalada:
     * `EscalarAlEquipoSkill` localizaba la conversación por el contexto del actor, el
     * prospecto nace sin contexto, y la skill —que declara `ROLE_PROSPECTO` entre sus roles
     * justo para que un lead pueda pedir ayuda— fallaba siempre con «No sé de qué
     * conversación hablas», remitiendo además a `localizar_conversacion`, que exige roles de
     * equipo y ni siquiera aparece en la lista de un prospecto. Callejón sin salida en el
     * único camino que convierte un interesado en un aviso al equipo.
     *
     * Darle contexto al prospecto habría arreglado el síntoma abriéndole de paso las siete
     * skills acotadas por contexto. Por eso son dos datos y no uno.
     */
    public function conversacionId(): ?string;

    /**
     * Qué vínculo comercial tiene con nosotros. Ver {@see VinculoComercial}.
     *
     * Es lo que distingue a quien ya reservó de quien está decidiendo si reserva, y no se
     * puede deducir de `contextoTipo()`: una consulta de OTA cuelga de una reserva igual que
     * una estancia confirmada.
     */
    public function vinculo(): VinculoComercial;

    /**
     * Qué NO puede salir por el canal de esta conversación. Ver {@see RestriccionCanal}.
     *
     * Ortogonal a todo lo demás: no depende de cuánta confianza merezca quien pregunta, sino
     * de por qué tubo va la respuesta.
     */
    public function restriccion(): RestriccionCanal;

    public function esDelEquipo(): bool;

    /**
     * ¿Pregunta sin ser todavía cliente de nada?
     *
     * En la interfaz y no sólo en `AgentActor` por el mismo motivo que {@see self::usuario()}:
     * `PerfilConversacion::deActor()` recibe el contrato, no la clase concreta, y sin esto una
     * segunda implementación reventaría con un método indefinido.
     */
    public function esProspecto(): bool;

    /**
     * El usuario del equipo que está detrás, o `null` si quien pregunta es un huésped.
     *
     * Lo necesitan las skills que registran QUIÉN hizo algo, no sólo qué se hizo:
     * `registrar_pago` resuelve con esto el «me pagó a mí» del operador. Está en la interfaz
     * y no sólo en `AgentActor` porque una skill recibe el contrato, no la clase concreta, y
     * la alternativa era un `instanceof` en cada una.
     *
     * ⚠️ Es quien MANEJA el chat, que no siempre es quien hizo la acción del mundo real: el
     * cobrador de un pago sólo coincide con él si el operador dice explícitamente que cobró él.
     */
    public function usuario(): ?User;

    /** @param list<string> $roles Vacío ⇒ basta con ser un actor cualquiera. */
    public function tieneAlguno(array $roles): bool;

    /** Para los logs, sin volcar la entidad entera. */
    public function etiqueta(): string;
}
