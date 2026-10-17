<?php

declare(strict_types=1);

namespace App\Agent\Contract;

use App\Agent\Conversation\PerfilConversacion;

/**
 * Una frase con la que un negocio se PRESENTA en conversaciones que son de otro (17/10/2026).
 *
 * Con un contexto reconocido sólo habla su dominio (ver
 * {@see \App\Agent\Conversation\InstruccionesDominioRegistry::para()}): al huésped de una casita le
 * habla el alojamiento. Tenía la herramienta de los tours, pero nada en el prompt le decía que
 * también somos agencia, y la regla que lo cubría era una negación —«nunca digas que no lo
 * organizamos»—, que el modelo incumple. **Lo que funciona es darle el HECHO en positivo**: con él
 * delante no tiene nada que negar.
 *
 * Es opcional, y la implementa el negocio que quiere presentarse (Turismo). El registro añade la
 * frase de los OTROS negocios detrás del bloque del dominio propio, y sólo si no está vacía: cada
 * negocio decide a qué perfiles se presenta.
 */
interface PresentacionDeNegocioInterface
{
    /** La frase, o `''` si a ese perfil no se presenta. */
    public function presentacion(PerfilConversacion $perfil): string;
}
