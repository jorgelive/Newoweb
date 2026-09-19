<?php

declare(strict_types=1);

namespace App\Agent\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Los enlaces internos de un dominio, traducidos a algo que el agente pueda seguir.
 *
 * ### Qué resuelve
 *
 * En la guía del alojamiento, `{{ ficha: calefactor }}` se pinta como un botón que lleva a otra
 * ficha. El agente no tiene botones: lo que necesita es enterarse de que **eso está escrito en
 * otro sitio y puede ir a buscarlo**, así que el marcador se convierte en una frase con el
 * título del destino. Eso ya lo hacía `ConsultarGuiaSkill` para el texto de la guía.
 *
 * Lo que faltaba era poder remitir DESDE OTRO SITIO. El conocimiento genérico —«¿hay mantas?»—
 * quiere decir «el calefactor está en tal tema de la guía» sin copiar el precio ni los horarios,
 * que viven en esa ficha y cambian ahí. Sin esto sólo quedaban dos salidas malas: duplicar el
 * texto —y que un día dejen de coincidir— o no remitir a nada.
 *
 * ### Por qué un contrato y no una llamada directa
 *
 * `ConsultarConocimientoSkill` vive en `Skill/` y **no conoce ningún negocio**: la misma tabla la
 * lee un huésped y, cuando exista, un pasajero de tours. Hacerla depender de la guía del PMS
 * sería meterle un dominio dentro, que es justo lo que el resto de contratos de `Agent/Contract`
 * existen para evitar. Cada dominio registra el suyo, entiende SUS marcadores y devuelve intacto
 * lo que no reconoce; el orden da igual porque nadie pisa la sintaxis de otro.
 *
 * ⚠️ **No es un filtro de visibilidad.** Lo que se entrega es un TÍTULO y una invitación a
 * consultar, no el contenido: quien decide si el destino se puede ver es la skill que lo sirva,
 * con sus propias reglas. Remitir a un tema que luego no le toque es inofensivo; darle el texto
 * sin mirar, no.
 */
#[AutoconfigureTag('app.agent.resolutor_enlaces')]
interface ResolutorDeEnlacesInterface
{
    /**
     * @param string $idioma El del destinatario, para el título del destino. El texto que llega
     *                       puede estar en otro: el modelo lo redacta entero al final.
     *
     * @return string El mismo texto con los enlaces de este dominio resueltos.
     */
    public function resolver(string $texto, string $idioma): string;
}
