<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Agent;

use App\Agent\Access\ActorInterface;
use App\Agent\Contract\InstruccionesDeDominioInterface;
use App\Agent\Conversation\PerfilConversacion;
use App\Contract\MapaDeHitos;
use App\Cotizacion\Entity\CotizacionConversacionEnlace;

/**
 * El bloque de prompt de TURISMO: que el agente sepa que también vendemos tours.
 *
 * ── Dos puertas ─────────────────────────────────────────────────────────────
 * 1. **Sin contexto de ningún negocio** —el prospecto, un hilo `manual`— entra por la puerta que
 *    {@see \App\Agent\Conversation\InstruccionesDominioRegistry::para()} abre a todos: a ése se
 *    le vende de todo, y cada negocio pone su bloque detrás del de por defecto.
 * 2. **Un expediente (`cotizacion_file`) es SUYO** desde el 16/10/2026. Antes caía al camino sin
 *    contexto, y a un interesado (expediente sin pagos) le llegaban las reglas de consulta de OTA
 *    del alojamiento —«NO DES PRECIOS, lo ve en el anuncio de la plataforma»— en vez de la venta.
 *    Y la fase de la estancia («LLEGA HOY… la casita puede no estar lista») por los hitos que
 *    también rellena Turismo. Con el equipo no dice nada, y el registro cede el turno al resto.
 *
 * El pasajero de un expediente merece algún día su propio bloque —su viaje, sus fechas, su punto
 * de encuentro—; mientras no exista, recibe el de la venta.
 *
 * ── Por qué no nombra ningún tour ───────────────────────────────────────────
 * El catálogo cambia desde `util` sin desplegar, y este texto va en el prefijo cacheado. Los
 * tours los trae `consultar_tours` en cada pregunta; aquí sólo va CUÁNDO llamarla y qué no decir.
 */
final readonly class CotizacionInstruccionesDominio implements InstruccionesDeDominioInterface
{
    public function supports(?string $contextType): bool
    {
        return $contextType === CotizacionConversacionEnlace::CONTEXT_TYPE;
    }

    public function esPorDefecto(): bool
    {
        return false;
    }

    public function para(PerfilConversacion $perfil): string
    {
        return match ($perfil) {
            // El interesado que llega aquí es el de un EXPEDIENTE sin pagos: la consulta de una OTA
            // cuelga de una reserva, y ésa la habla el alojamiento sin pasar por este dominio. Hasta
            // el 16/10/2026 devolvía '' y el expediente recibía las reglas de OTA del alojamiento.
            PerfilConversacion::Prospecto, PerfilConversacion::Interesado, PerfilConversacion::Huesped => self::VENTA,
            // El equipo ya sabe lo que hay: '' cede el turno a su bloque de siempre (ver el registro).
            PerfilConversacion::Personal, PerfilConversacion::Colaborador => '',
        };
    }

    public function contextoVolatil(ActorInterface $actor, MapaDeHitos $hitos): string
    {
        return '';
    }

    /**
     * Qué zona se vende (Jorge, 12/10/2026): **todo el Perú y el Caribe**. Fuera de ahí no se
     * niega —puede salir—, pero tampoco se promete: se anota y lo valora el equipo. Sin la zona
     * escrita, el modelo afirmaba «sí, organizamos» de cualquier destino que se le preguntara.
     *
     * Y anotar no es escalar (Jorge, 13/10/2026): en la simulación de un traslado a cinco semanas
     * vista hizo las dos cosas, y escalar es un WhatsApp al equipo. Sólo lo inmediato lo justifica.
     */
    private const string VENTA = <<<PROMPT
        TAMBIÉN VENDEMOS TOURS Y VIAJES. Además del alojamiento somos agencia de viajes (OpenPeru
        Travel): tours, excursiones y traslados por TODO EL PERÚ —Cusco y alrededores, y el resto
        del país— y viajes al CARIBE con nuestra división Open World Travel. Si preguntan por un
        tour, una excursión, un viaje, cómo llegar a un sitio turístico, un tren, una entrada o «qué
        se puede hacer», llama a «consultar_tours» ANTES de contestar. Nunca digas que no lo
        organizamos sin haberla llamado.

        - Si está en el catálogo, cuéntalo: qué es, cuántos días, el precio «desde» y su «enlace».
          Si quieren saber qué incluye, vuelve a llamarla con «tour» para traer su itinerario.
        - Si NO está pero es en el Perú o en el Caribe, lo armamos a medida: pide fechas y cuántos
          son, y anótalo con «anotar_pedido_cotizacion» para que el equipo le prepare la propuesta.
        - Si es OTRO destino, no lo prometas ni lo niegues: dile que lo consultas con el equipo y
          anótalo igual con «anotar_pedido_cotizacion».
        - Los precios del catálogo son «desde», por persona y referenciales. La fecha y el precio
          final los confirma el equipo: para reservar, anota el pedido.
        - Anotar el pedido YA avisa a Cotizaciones. Llama además a «escalar_al_equipo» sólo si es
          para HOY o MAÑANA; si es para más adelante, anotarlo basta.
        PROMPT;
}
