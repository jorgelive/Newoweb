<?php

declare(strict_types=1);

namespace App\Pms\Enum;

/**
 * Clasifica cada ítem de la guía por quién puede verlo y desde cuándo.
 *
 * Es el campo que separa el escaparate público de la guía del huésped: antes
 * ambos servían el MISMO árbol de secciones (getSeccionesApi() no filtraba
 * nada), así que cualquiera con el UUID de una unidad veía las normas de la
 * casa y las instrucciones de la caja fuerte. Las credenciales nunca viajaron
 * —codigoPuerta y wifiNetworks no tienen Groups— pero la estructura sí.
 *
 * La regla de "quién ve qué" NO vive aquí: vive en PmsGuiaAcceso::permite(),
 * que cruza esta visibilidad con el estado de la estancia. Aquí solo está el
 * eje que no depende del huésped (esPublico), espejo de
 * App\Cotizacion\Enum\ArchivoTipoEnum::esPublico().
 */
enum PmsGuiaVisibilidad: string
{
    /** Escaparate: visible sin reserva. Fotos, descripción, ubicación, servicios. */
    case Publico = 'publico';

    /** Solo el huésped con una estancia vigente, desde que reserva. Normas, cómo llegar. */
    case Privado = 'privado';

    /** Solo dentro de la ventana de llegada. Códigos de puerta, caja fuerte, WiFi. */
    case Llegada = 'llegada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Publico => '🌐 Público (catálogo)',
            self::Privado => '🔒 Privado (huésped con reserva)',
            self::Llegada => '🔑 A la llegada (24 h antes del check-in)',
        };
    }

    /**
     * Define si el ítem se sirve en el catálogo público, sin ninguna reserva
     * de por medio. Único punto donde se decide: el provider del catálogo
     * filtra con esto en vez de con una lista de casos hardcodeada.
     */
    public function esPublico(): bool
    {
        return self::Publico === $this;
    }

    /**
     * Ítems cuyo contenido depende de la ventana temporal. Se usa para decidir
     * si un ítem oculto merece mostrarse "bloqueado con fecha" en vez de
     * desaparecer: solo tiene sentido prometer algo que de verdad va a llegar.
     */
    public function esTemporal(): bool
    {
        return self::Llegada === $this;
    }
}
