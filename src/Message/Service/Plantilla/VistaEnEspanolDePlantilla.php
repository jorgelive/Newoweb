<?php

declare(strict_types=1);

namespace App\Message\Service\Plantilla;

use App\Message\Entity\MessageTemplate;

/**
 * Lo que se lee de una plantilla en la ficha «Ver»: su texto en ESPAÑOL, y nada más.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * La ficha enseñaba el JSON crudo de cada canal, con los siete idiomas, los `origenHash` y el
 * `buttons_map` dentro. Para saber qué dice una plantilla había que leer un bloque de código con
 * barra horizontal en el móvil, y el español estaba en medio.
 *
 * Aquí se muestra **sólo el español**, que es el original: los otros seis los escribe
 * `AutoTranslate` a partir de él, así que leer el español es leer la plantilla. Quien necesite el
 * JSON entero lo tiene al editar, que es donde se toca.
 *
 * ⚠️ Es una VISTA: no toca nada y no resuelve marcadores —`{{guest_name}}` se enseña tal cual—.
 * Para verla hidratada con una reserva real está `msg:plantilla:ver`.
 */
final readonly class VistaEnEspanolDePlantilla
{
    private const string IDIOMA = 'es';

    /** Fuera de la ventana de 24 h: cabecera, cuerpo, pie y botones, que es lo que aprueba Meta. */
    public function whatsappMeta(MessageTemplate $plantilla): string
    {
        $partes = [];
        $cabecera = $plantilla->getWhatsappMetaHeader(self::IDIOMA);

        if ($cabecera !== null && ($cabecera['content'] ?? null) !== null) {
            $partes[] = '[Cabecera] ' . $cabecera['content'];
        }

        $partes[] = (string) $plantilla->getWhatsappMetaBody(self::IDIOMA);

        if (($pie = $plantilla->getWhatsappMetaFooter(self::IDIOMA)) !== null && $pie !== '') {
            $partes[] = '[Pie] ' . $pie;
        }

        foreach ($plantilla->getWhatsappMetaButtons(self::IDIOMA) as $boton) {
            // El destino también: un botón sin su enlace no se puede revisar, y es justo lo que
            // más se equivoca —apunta a la guía, a la cuenta o a un ancla distinta.
            $destino = (string) ($boton['resolver_key'] ?? '') !== ''
                ? (string) $boton['resolver_key']
                : (string) ($boton['content'] ?? '');

            $partes[] = sprintf('[Botón] %s → %s', (string) ($boton['button_text'] ?? '¿?'), $destino);
        }

        return $this->limpiar($partes);
    }

    /** Dentro de la ventana de 24 h, y el que se manda a mano desde el calendario. */
    public function whatsappDentro(MessageTemplate $plantilla): string
    {
        return $this->limpiar([(string) $plantilla->getWhatsappLinkBody(self::IDIOMA)]);
    }

    /** El chat de la OTA. */
    public function beds24(MessageTemplate $plantilla): string
    {
        return $this->limpiar([(string) $plantilla->getBeds24Body(self::IDIOMA)]);
    }

    public function correo(MessageTemplate $plantilla): string
    {
        $asunto = (string) $plantilla->getEmailSubject(self::IDIOMA);

        return $this->limpiar([
            $asunto !== '' ? '[Asunto] ' . $asunto : '',
            (string) $plantilla->getEmailBody(self::IDIOMA),
        ]);
    }

    /**
     * @param list<string> $partes
     *
     * @return string Vacío si no hay nada escrito: quien lo pinta decide qué poner entonces.
     */
    private function limpiar(array $partes): string
    {
        $utiles = array_filter(array_map('trim', $partes), static fn (string $parte): bool => $parte !== '');

        return implode("\n\n", $utiles);
    }
}
