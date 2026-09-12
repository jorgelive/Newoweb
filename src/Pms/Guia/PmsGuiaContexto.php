<?php

declare(strict_types=1);

namespace App\Pms\Guia;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Enum\PmsGuiaVisibilidad;
use App\Pms\Enum\PmsUnidadMediaTipo;

/**
 * Diccionario de valores con los que se resuelven los `{{ placeholders }}` del
 * cuerpo de un ítem, partido en dos por sensibilidad.
 *
 * El corte importa: `$valores` viaja siempre; `$sensibles` solo se sustituye
 * cuando PmsGuiaAcceso::estaAbierto(). Antes esta distinción no existía en el
 * transporte —el back mandaba TODAS las claves en `text_fixed` y el navegador
 * decidía cuál pintar—, así que bastaba abrir las herramientas de desarrollo
 * para leer el código de la puerta.
 */
final readonly class PmsGuiaContexto
{
    /**
     * @param array<string, string> $valores   Claves siempre sustituibles (nombre, fechas, unidad).
     * @param array<string, string> $sensibles Claves que exigen ventana abierta (códigos de acceso).
     *
     * `$redesWifi` tiene la misma forma que declara `PmsUnidad::getWifiNetworks()`, que es de
     * donde viene: una sola verdad. Declararla aquí con las claves obligatorias prometía que
     * siempre están, y son opcionales — una red guardada sin contraseña es un caso real.
     *
     * @param list<array{ssid?: string|null, password?: string|null, ubicacion?: list<array{language?: string, content?: string|null}>}> $redesWifi
     *
     * @param array<string, array{valor: string, nivel: PmsGuiaVisibilidad}> $medios
     *        El croquis, la foto de la puerta y los vídeos, **cada uno con el nivel desde el que se
     *        puede ver**. Van aparte de `valores`/`sensibles` porque esos dos cubos sólo distinguen
     *        «siempre» de «con ventana», y la guía clasifica por cuatro niveles.
     */
    public function __construct(
        public array $valores = [],
        public array $sensibles = [],
        public array $redesWifi = [],
        public array $medios = [],
    ) {
    }

    /**
     * Construye el contexto de una estancia. Con $evento a null sale el
     * contexto del catálogo público: sin nombre de huésped, sin fechas y —lo
     * importante— sin ninguna clave sensible cargada, ni siquiera enmascarada.
     */
    public static function construir(PmsUnidad $unidad, ?PmsEventoCalendario $evento): self
    {
        $establecimiento = $unidad->getEstablecimiento();
        $reserva = $evento?->getReserva();

        $valores = array_filter([
            'unit_name'   => $unidad->getNombre(),
            'hotel_name'  => $establecimiento?->getNombreComercial(),
            // El nombre del anfitrión y su WhatsApp ya los pintaba
            // GuiaUnidadView.vue (tarjeta de contacto), pero buildResponse()
            // nunca los llegó a poner en text_fixed: la tarjeta jamás se
            // renderizaba. Se cablean aquí a los datos del establecimiento.
            'host_name'     => $establecimiento?->getNombreComercial(),
            'host_whatsapp' => $establecimiento?->getTelefonoPrincipal(),
            'guest_name'  => $reserva?->getNombreCliente(),
            'booking_ref' => $reserva?->getLocalizador(),
            'check_in'    => ($evento?->getInicio() ?? $establecimiento?->getHoraCheckIn())?->format('H:i'),
            'check_out'   => ($evento?->getFin() ?? $establecimiento?->getHoraCheckOut())?->format('H:i'),
            'start_date'  => $evento?->getInicio()?->format('d/m/Y'),
            'end_date'    => $evento?->getFin()?->format('d/m/Y'),
        ], static fn (?string $v): bool => null !== $v && '' !== $v);

        // ⚠️ **Los medios van en su propio cajón, con su NIVEL**, no repartidos entre `valores` y
        // `sensibles`: la guía clasifica por cuatro niveles y esos dos cubos sólo distinguen dos.
        // Quién puede ver cada tipo lo dice `PmsUnidadMediaTipo::visibilidad()` y lo resuelve
        // `PmsGuiaAcceso::permite()`, el mismo juez que para los ítems.
        $medios = [];

        foreach (PmsUnidadMediaTipo::cases() as $tipo) {
            $valor = $unidad->medio($tipo)?->getValor();

            if ($valor !== null && $valor !== '') {
                $medios[$tipo->clave()] = ['valor' => $valor, 'nivel' => $tipo->visibilidad()];
            }
        }

        $videoCaja = $establecimiento?->getVideoCajaFuerteUrl();

        if ($videoCaja !== null && $videoCaja !== '') {
            // El de la caja es del establecimiento, no de la casita, pero se sirve igual y con el
            // nivel más alto: enseña cómo se abre.
            $medios['video_caja_fuerte'] = ['valor' => $videoCaja, 'nivel' => PmsGuiaVisibilidad::SoloVentana];
        }

        // Sin estancia no se cargan credenciales en memoria siquiera: el
        // catálogo público no tiene por qué poder equivocarse. Los MEDIOS sí viajan: su nivel ya
        // dice quién puede verlos, y el catálogo resuelve con el mismo `permite()`.
        if (null === $evento) {
            return new self($valores, [], [], $medios);
        }

        $sensibles = array_filter([
            // `door_code` es el smart lock, hoy vacío en todas las casitas: `array_filter` lo
            // deja fuera solo. `numero` es el de la casita: el que lleva su llave y el que
            // identifica su puerta en su croquis.
            'door_code'    => $unidad->getCodigoPuerta(),
            'numero'       => $unidad->getNumero() !== null ? (string) $unidad->getNumero() : null,
            'safe_code'   => $unidad->getCodigoCaja(),
            'keybox_main' => $establecimiento?->getCodigoCajaPrincipal(),
            'keybox_sec'  => $establecimiento?->getCodigoCajaSecundaria(),
        ], static fn (?string $v): bool => null !== $v && '' !== $v);

        return new self($valores, $sensibles, $unidad->getWifiNetworks(), $medios);
    }
}
