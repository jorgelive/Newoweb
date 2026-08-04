<?php

declare(strict_types=1);

namespace App\Pms\Guia;

/**
 * Textos que ocupan el lugar de un dato que el huésped todavía no puede ver.
 *
 * Portados tal cual de GuiaHelperResponseTrait::traducirEstado(). Viven aquí
 * como mapa `idioma => texto` en vez de como lista `[{language, content}]`
 * porque el interpolador sustituye entrada por entrada: necesita el mensaje
 * del idioma de ESA traducción, no la lista entera.
 *
 * No pasan por el sistema de traducción de Symfony a propósito: son textos de
 * cara al huésped, cuyos idiomas los fija MaestroIdioma (los que el selector
 * de la guía ofrece), no el locale del panel.
 */
final class PmsGuiaMensajes
{
    /** Idioma al que se cae si el contenido está en uno que no tenemos cubierto. */
    public const IDIOMA_FALLBACK = 'en';

    private const PENDIENTE_CON_FECHA = [
        'es' => '[Disponible el %1$s a las %2$s]',
        'en' => '[Available on %1$s at %2$s]',
        'pt' => '[Disponível em %1$s às %2$s]',
        'fr' => '[Disponible le %1$s à %2$s]',
        'it' => '[Disponibile il %1$s alle %2$s]',
        'de' => '[Verfügbar am %1$s um %2$s]',
        'nl' => '[Beschikbaar op %1$s om %2$s]',
    ];

    private const PENDIENTE_SIN_FECHA = [
        'es' => '[Disponible pronto]',
        'en' => '[Available soon]',
        'pt' => '[Disponível em breve]',
        'fr' => '[Bientôt disponible]',
        'it' => '[Disponibile a breve]',
        'de' => '[Bald verfügbar]',
        'nl' => '[Binnenkort beschikbaar]',
    ];

    private const NO_CONFIRMADA = [
        'es' => '[Disponible al confirmar]',
        'en' => '[Available upon confirmation]',
        'pt' => '[Disponível mediante confirmação]',
        'fr' => '[Disponible après confirmation]',
        'it' => '[Disponibile dopo conferma]',
        'de' => '[Verfügbar nach Bestätigung]',
        'nl' => '[Beschikbaar na bevestiging]',
    ];

    private const EXPIRADA = [
        'es' => '[Reserva finalizada]',
        'en' => '[Booking ended]',
        'pt' => '[Reserva finalizada]',
        'fr' => '[Réservation terminée]',
        'it' => '[Prenotazione terminata]',
        'de' => '[Buchung beendet]',
        'nl' => '[Boeking beëindigd]',
    ];

    private const PROTEGIDA = [
        'es' => '[Info protegida]',
        'en' => '[Protected info]',
        'pt' => '[Informação protegida]',
        'fr' => '[Info protégée]',
        'it' => '[Info protetta]',
        'de' => '[Geschützte Info]',
        'nl' => '[Beschermde info]',
    ];

    /**
     * Mensaje que sustituye a un dato bloqueado, en el idioma pedido.
     *
     * @param string $idioma Código del idioma de la traducción que se está interpolando.
     */
    public static function bloqueo(PmsGuiaAcceso $acceso, string $idioma): string
    {
        $tabla = match ($acceso->estado) {
            PmsGuiaAccesoEstado::Pendiente    => null !== $acceso->liberaEn ? self::PENDIENTE_CON_FECHA : self::PENDIENTE_SIN_FECHA,
            PmsGuiaAccesoEstado::SinPago      => self::NO_CONFIRMADA,
            PmsGuiaAccesoEstado::Expirada     => self::EXPIRADA,
            default                           => self::PROTEGIDA,
        };

        $plantilla = $tabla[$idioma] ?? $tabla[self::IDIOMA_FALLBACK];

        if ($tabla === self::PENDIENTE_CON_FECHA && null !== $acceso->liberaEn) {
            return sprintf($plantilla, $acceso->liberaEn->format('d/m/Y'), $acceso->liberaEn->format('H:i'));
        }

        return $plantilla;
    }
}
