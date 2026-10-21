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

    /** Cualquiera con el localizador. Normas, cómo llegar, contacto. */
    case Cliente = 'cliente';

    /** Además, pago confiable. Detalles de estancia que no se dan por adelantado. */
    case ClienteConfirmado = 'cliente-confirmado';

    /** Además, ventana abierta. Códigos de puerta, caja fuerte, WiFi. */
    case SoloVentana = 'solo-ventana';

    /**
     * FUERA de la escalera: sólo con un enlace que el equipo manda (`PmsGuiaEnlaceDelEquipo`).
     *
     * Para lo que no es de cualquier huésped aunque tenga la ventana abierta: cómo dejar un pago
     * en la caja del DINERO o sacar la llave de emergencia. Decide una persona, cada vez. Un ítem
     * así no sale nunca en la guía —ni con candado—, ni en el catálogo, ni en el índice del
     * agente: `PmsGuiaAcceso::permite()` sólo lo deja pasar con el acceso del enlace. Ver
     * `docs/PmsGuiaHuesped.md` §7 sexies.
     */
    case PorEnlace = 'por-enlace';

    public function getLabel(): string
    {
        return match ($this) {
            self::Publico           => '🌐 Público (catálogo)',
            self::Cliente           => '👤 Cliente (con localizador)',
            self::ClienteConfirmado => '✅ Cliente confirmado (con pago)',
            self::SoloVentana       => '🔑 Solo en ventana (30 h antes del check-in)',
            self::PorEnlace         => '🔗 Sólo con enlace del equipo (no sale en la guía)',
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
     * Niveles que exigen algo MÁS que tener el localizador (pago o ventana).
     *
     * Son los que, cuando no se cumplen, se anuncian con candado en vez de
     * desaparecer: al portador del localizador siempre se le puede decir qué le
     * falta. `Publico` y `Cliente` nunca llegan aquí, porque para un huésped
     * identificado están siempre permitidos.
     */
    public function exigeCondicionExtra(): bool
    {
        return self::ClienteConfirmado === $this || self::SoloVentana === $this;
    }
}
