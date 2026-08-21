<?php

declare(strict_types=1);

namespace App\Message\Service\Conversacion;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;

/**
 * Qué direcciones de un hilo son de una PLATAFORMA y no de la persona.
 *
 * ── Por qué es un servicio y no dos comprobaciones sueltas ──────────────────
 * La misma pregunta la hacen dos sitios con consecuencias distintas, y tenían que responderla
 * igual o se contradicen:
 *
 * - {@see EditorDeIdentidades::marcarPrincipal()} — para NEGARSE a marcarla como salida por
 *   defecto: Booking emite un alias por reserva, y marcarlo mandaría la estancia de Airbnb del
 *   mes que viene al buzón de la de Booking del mes pasado.
 * - {@see \App\Message\Service\Queue\EmailSendEnqueuer::destino()} — para no cogerla como
 *   destino del «régimen persona», que es por donde se colaba aunque nadie la hubiera marcado.
 *
 * ⚠️ **Se deduce, no se guarda.** El alias ya ES el `correoDeContacto()` de un asunto que se
 * declara exclusivo; una copia en la identidad crearía un segundo autor sobre el mismo hecho, y
 * el día que una reserva se reclasifique mentiría sin que nada lo dijera.
 *
 * ⚠️ **Se compara con {@see IdentidadTipo::EMAIL}`->normalizar()`, no con `strcasecmp`.** El
 * valor de la identidad ya está normalizado con esa función —`trim` + `mb_strtolower`—, y
 * `strcasecmp` sólo baja ASCII: una mayúscula acentuada haría que el lado que MANDA dejara pasar
 * lo que el panel esconde, que es la divergencia al revés de como conviene.
 */
final readonly class AliasDePlataforma
{
    public function __construct(private EnlacesDeConversacion $enlaces)
    {
    }

    /** ¿Este identificador es un alias que emitió una OTA para una de sus reservas? */
    public function esAlias(MessageConversation $hilo, MessageIdentidad $identidad): bool
    {
        if ($identidad->getTipo() !== IdentidadTipo::EMAIL) {
            return false;
        }

        return in_array($identidad->getValor(), $this->de($hilo), true);
    }

    /**
     * Los alias de plataforma del hilo, ya normalizados.
     *
     * @return list<string>
     */
    public function de(MessageConversation $hilo): array
    {
        $alias = [];

        foreach ($this->enlaces->de($hilo) as $asunto) {
            if (!$asunto->correoEsExclusivo()) {
                continue;
            }

            $correo = $asunto->correoDeContacto();

            if ($correo !== null && ($normalizado = IdentidadTipo::EMAIL->normalizar($correo)) !== '') {
                $alias[] = $normalizado;
            }
        }

        return array_values(array_unique($alias));
    }

    /** ¿Hay en el hilo algún asunto cuyo correo sea exclusivo suyo? */
    public function elHiloTieneAsuntosExclusivos(MessageConversation $hilo): bool
    {
        foreach ($this->enlaces->de($hilo) as $asunto) {
            if ($asunto->correoEsExclusivo()) {
                return true;
            }
        }

        return false;
    }
}
