<?php

declare(strict_types=1);

namespace App\Agent\Access;

use App\Message\Contract\CategoriaConocimiento;
use App\Message\Contract\VinculoComercial;

/**
 * Qué NO puede salir por este canal, con independencia de a quién se le hable.
 *
 * ### Por qué es un eje aparte y no un escalón más
 *
 * La visibilidad de la guía ({@see \App\Pms\Enum\PmsGuiaVisibilidad}) es una ESCALERA: a más
 * confianza, más contenido. Esto es lo contrario — una RESTA que se aplica encima, sea cual
 * sea el escalón.
 *
 * Se ve claro con la interesada que llega por Airbnb. Merece MÁS que el escaparate público
 * —cuántas habitaciones, cuántas camas, cómo se distribuye, qué hay en la cocina, cómo es el
 * check-in: todo eso hay que contestarlo o se pierde la venta— y a la vez MENOS que el
 * escaparate en una dimensión concreta: la dirección exacta, que el partner prohíbe dar antes
 * de confirmar.
 *
 * Modelarlo como un peldaño entre `Publico` y `Cliente` sale mal: para poder cerrarle la
 * dirección habría que cerrarle también las camas.
 *
 * ### Dónde se aplica
 *
 * En la FUENTE, al recuperar el contenido, no en el prompt. Un prompt es una petición; si el
 * dato llega al modelo, puede acabar escrito. Al prompt solo le llega
 * {@see self::avisoParaElPrompt()} para que sepa explicarlo con gracia en vez de inventarse
 * una excusa —o de prometer que «el equipo te lo envía», que es lo que hizo cuando no podía
 * cumplirlo—.
 *
 * ⚠️ El filtro estructural NO cubre lo que el modelo escribe de su cosecha: puede ofrecer un
 * número de WhatsApp que no salió de ninguna fuente. Esa segunda capa es una guarda léxica en
 * el envío, no aquí.
 */
enum RestriccionCanal: string
{
    /** Canal propio: no hay nada que restar. */
    case Ninguna = 'ninguna';

    /**
     * Reserva no confirmada de una OTA. La plataforma prohíbe todo lo que permita cerrar el
     * trato por fuera: dirección exacta, teléfonos, correos y cualquier invitación a seguir
     * la conversación en otro canal.
     */
    case OtaPreReserva = 'ota_pre_reserva';

    /**
     * Deduce la restricción del canal de origen y del vínculo.
     *
     * `directo` es el canal propio y nunca restringe. Cualquier otro origen —`airbnb`,
     * `booking`…— es una OTA, y restringe SOLO mientras no esté confirmada: una vez
     * confirmada la reserva, la plataforma ya permite dar dirección y contacto, que es
     * justamente lo que el huésped necesita para llegar.
     */
    public static function deOrigenYVinculo(?string $origen, VinculoComercial $vinculo): self
    {
        $esOta = $origen !== null && $origen !== '' && $origen !== 'directo';

        return $esOta && VinculoComercial::Cliente !== $vinculo
            ? self::OtaPreReserva
            : self::Ninguna;
    }

    /**
     * Categorías de contenido que no pueden salir. Las consume el filtro de la fuente.
     *
     * @return list<string>
     */
    public function categoriasBloqueadas(): array
    {
        return match ($this) {
            self::Ninguna => [],
            self::OtaPreReserva => [
                CategoriaConocimiento::DireccionExacta->value,
                CategoriaConocimiento::Contacto->value,
                CategoriaConocimiento::CanalAlternativo->value,
            ],
        };
    }

    public function restringe(): bool
    {
        return self::Ninguna !== $this;
    }

    /**
     * Variables del resolver que no pueden viajar al modelo por este canal.
     *
     * Son enlaces a sitio propio. Para una OTA sin confirmar es de lo más sensible que hay:
     * no es sólo un dato, es sacar la conversación —y con ella la venta— de la plataforma,
     * que es precisamente lo que penalizan. Y la guía que hay al otro lado del enlace lleva
     * dentro la dirección, el wifi y los teléfonos, así que un solo enlace se salta de golpe
     * toda la resta por categorías.
     *
     * La lista vive aquí y no en la skill porque es política de canal, no de reserva: el día
     * que se añada otra URL al resolver, se bloquea en un sitio y vale para todas.
     *
     * @return list<string>
     */
    public function variablesBloqueadas(): array
    {
        return match ($this) {
            self::Ninguna => [],
            self::OtaPreReserva => [
                'guide_url',
                'guide_path',
                'tours_catalog_url',
                'tours_catalog_path',
            ],
        };
    }

    /**
     * La línea que viaja en el bloque VOLÁTIL del prompt.
     *
     * Va fuera del bloque cacheado, igual que
     * {@see \App\Agent\Conversation\PerfilConversacion::enUnaLinea()}: corrige el
     * comportamiento sin tirar el ahorro de caché.
     */
    public function avisoParaElPrompt(): string
    {
        return match ($this) {
            self::Ninguna => '',
            self::OtaPreReserva => 'CANAL RESTRINGIDO: esta conversación va por la plataforma '
                . 'donde reservó y AÚN NO ESTÁ CONFIRMADA. Está prohibido dar la dirección '
                . 'exacta, teléfonos, correos, y mencionar WhatsApp o cualquier otro canal '
                . 'para seguir hablando: la plataforma penaliza el alojamiento por ello. '
                . 'Puedes describir la zona y las distancias sin dar la calle ni el número. '
                . 'Tampoco puedes enviar fotos ni archivos por aquí: NO prometas que se los '
                . 'envías tú ni que «el equipo se los manda», porque no va a llegar; remite a '
                . 'las fotos publicadas en la plataforma y describe con palabras lo que te '
                . 'pregunten. Todo lo demás —distribución, habitaciones, camas, equipamiento, '
                . 'normas, check-in— se contesta con normalidad.',
        };
    }
}
