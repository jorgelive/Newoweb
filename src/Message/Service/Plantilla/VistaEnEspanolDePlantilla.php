<?php

declare(strict_types=1);

namespace App\Message\Service\Plantilla;

use App\Message\Entity\MessageTemplate;

/**
 * Lo que se lee de una plantilla en la ficha «Ver»: su texto en ESPAÑOL, por piezas.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * La ficha enseñaba el JSON crudo de cada canal, con los siete idiomas, los `origenHash` y el
 * `buttons_map` dentro. Para saber qué dice una plantilla había que leer un bloque de código con
 * barra horizontal en el móvil, y el español estaba en medio.
 *
 * Se muestra **sólo el español**, que es el original: los otros seis los escribe `AutoTranslate` a
 * partir de él, así que leer el español es leer la plantilla. Quien necesite el JSON entero lo
 * tiene al editar, que es donde se toca.
 *
 * ── Piezas, no un churro de texto ───────────────────────────────────────────
 * Devuelve una lista de partes con su etiqueta —cabecera, pie, botón— en vez de una cadena con
 * `[Cabecera]` escrito dentro. La primera versión lo hacía así y se leía mal: quien pinta necesita
 * saber qué es cada trozo para darle su sitio, y meter el rótulo en el texto obliga a que el
 * cuerpo del mensaje y el nombre de la pieza compartan tipografía y peso.
 *
 * ⚠️ Es una VISTA: no toca nada y no resuelve marcadores —`{{guest_name}}` se enseña tal cual—.
 * Para verla hidratada con una reserva real está `msg:plantilla:ver`.
 */
final readonly class VistaEnEspanolDePlantilla
{
    private const string IDIOMA = 'es';

    /**
     * Fuera de la ventana de 24 h: cabecera, cuerpo, pie y botones, que es lo que aprueba Meta.
     *
     * @return list<array{etiqueta: ?string, texto: string}>
     */
    public function whatsappMeta(MessageTemplate $plantilla): array
    {
        $partes = [];
        $cabecera = $plantilla->getWhatsappMetaHeader(self::IDIOMA);

        $partes[] = ['etiqueta' => 'Cabecera', 'texto' => (string) ($cabecera['content'] ?? '')];
        $partes[] = ['etiqueta' => null, 'texto' => (string) $plantilla->getWhatsappMetaBody(self::IDIOMA)];
        $partes[] = ['etiqueta' => 'Pie', 'texto' => (string) $plantilla->getWhatsappMetaFooter(self::IDIOMA)];

        foreach ($plantilla->getWhatsappMetaButtons(self::IDIOMA) as $boton) {
            // El destino también: un botón sin su enlace no se puede revisar, y es justo lo que
            // más se equivoca —apunta a la guía, a la cuenta o a un ancla distinta.
            $destino = ($boton['resolver_key'] ?? '') !== ''
                ? $boton['resolver_key']
                : $boton['content'];

            $partes[] = [
                'etiqueta' => 'Botón',
                'texto' => sprintf('%s → %s', $boton['button_text'] ?? '¿?', $destino),
            ];
        }

        return $this->limpiar($partes);
    }

    /**
     * Dentro de la ventana de 24 h, y el que se manda a mano desde el calendario.
     *
     * @return list<array{etiqueta: ?string, texto: string}>
     */
    public function whatsappDentro(MessageTemplate $plantilla): array
    {
        return $this->limpiar([['etiqueta' => null, 'texto' => (string) $plantilla->getWhatsappLinkBody(self::IDIOMA)]]);
    }

    /**
     * El chat de la OTA.
     *
     * @return list<array{etiqueta: ?string, texto: string}>
     */
    public function beds24(MessageTemplate $plantilla): array
    {
        return $this->limpiar([['etiqueta' => null, 'texto' => (string) $plantilla->getBeds24Body(self::IDIOMA)]]);
    }

    /** @return list<array{etiqueta: ?string, texto: string}> */
    public function correo(MessageTemplate $plantilla): array
    {
        return $this->limpiar([
            ['etiqueta' => 'Asunto', 'texto' => (string) $plantilla->getEmailSubject(self::IDIOMA)],
            ['etiqueta' => null, 'texto' => (string) $plantilla->getEmailBody(self::IDIOMA)],
        ]);
    }

    /**
     * Fuera las piezas vacías: un canal a medias enseña lo que tiene, no huecos con rótulo.
     *
     * @param list<array{etiqueta: ?string, texto: string}> $partes
     *
     * @return list<array{etiqueta: ?string, texto: string}> Vacío si no hay nada escrito: quien lo
     *         pinta decide qué poner entonces.
     */
    private function limpiar(array $partes): array
    {
        $utiles = [];

        foreach ($partes as $parte) {
            $texto = trim($parte['texto']);

            if ($texto !== '') {
                $utiles[] = ['etiqueta' => $parte['etiqueta'], 'texto' => $texto];
            }
        }

        return $utiles;
    }
}
