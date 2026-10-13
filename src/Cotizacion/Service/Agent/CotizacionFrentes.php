<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Agent;

use App\Contract\Frente;
use App\Cotizacion\Entity\CotizacionConversacionEnlace;
use App\Message\Contract\FrentesPorDominioInterface;

/**
 * El negocio de TURISMO (tours, excursiones, viajes) ante el agente: la puerta de venta.
 *
 * ── El fallo que cierra (11/10/2026) ────────────────────────────────────────
 * Un desconocido preguntó por WhatsApp «¿organizan transporte entre Cusco y Machu Picchu?» y el
 * agente contestó «No organizamos transporte directo a Machu Picchu», con el catálogo «Machu
 * Picchu: Full day en tren turístico» publicado en openperu.pe. Sólo el alojamiento se declaraba
 * vendible, así que un prospecto llegaba únicamente a `hotelero`: las skills de turismo
 * (`consultar_tours`, `anotar_pedido_cotizacion`) no existían para él, y el único dato de
 * transporte que encontró fue «No ofrecemos traslado propio» de la llegada a las casitas.
 *
 * Declararse vendible es lo que mete `turistico` en los dominios de CUALQUIER actor
 * ({@see \App\Message\Service\EnumeradorDeFrentes::dominiosPara()}): prospecto, huésped de una
 * casita que pregunta por excursiones, y equipo.
 *
 * ── `frentesVivos()` vacío, de momento ──────────────────────────────────────
 * Los expedientes de un teléfono se enlazan a su conversación por
 * {@see \App\Cotizacion\Service\Message\CotizacionProveedorDeEnlaces}, pero la enumeración de
 * frentes todavía no la lee nadie para el prompt (`EnumeradorDeFrentes::paraTelefono()` no tiene
 * llamadas). Vacío es una respuesta válida del contrato —«nada vivo en este negocio»— y no
 * esconde nada que hoy se use. Cuando el triaje elija frente, aquí van los expedientes vivos.
 */
final readonly class CotizacionFrentes implements FrentesPorDominioInterface
{
    public const string NEGOCIO = 'turistico';

    public function negocio(): string
    {
        return self::NEGOCIO;
    }

    public function supports(?string $contextType): bool
    {
        return $contextType === CotizacionConversacionEnlace::CONTEXT_TYPE;
    }

    /** Sí: los tours se le venden a cualquiera que escriba, tenga o no algo comprado. */
    public function esVendible(): bool
    {
        return true;
    }

    public function etiquetaDeVenta(): string
    {
        return 'Tours, excursiones y viajes';
    }

    /** @return list<Frente> */
    public function frentesVivos(?string $telefono): array
    {
        return [];
    }
}
