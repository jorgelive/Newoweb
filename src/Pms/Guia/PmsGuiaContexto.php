<?php

declare(strict_types=1);

namespace App\Pms\Guia;

use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Enum\PmsGuiaVisibilidad;
use App\Pms\Enum\PmsEstablecimientoMediaTipo;
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
     * Los dos teléfonos del alojamiento, con el nombre y la forma que usa cada sitio.
     *
     * Son dos papeles distintos y el nombre lo dice: `whatsapp_numero` lo atiende el sistema y es
     * el que se publica; `emergencia_numero` lo contesta una persona y es para quien está en la
     * puerta sin poder entrar. Ver `PmsEstablecimiento` y `docs/Telefonos.md` §5 bis.
     *
     * La variante `_url` existe porque un botón de la guía necesita el enlace entero: `wa.me` no
     * admite espacios ni el `+`, así que el número tal cual no vale, y hacer la limpieza en el
     * texto del botón era lo que tenía el número escrito a mano dentro del enlace.
     *
     * @return array<string, string|null>
     */
    private static function telefonos(?PmsEstablecimiento $establecimiento): array
    {
        $wa = static fn (?string $numero): ?string => $numero === null || trim($numero) === ''
            ? null
            : 'https://wa.me/' . preg_replace('/\D/', '', $numero);

        return [
            'whatsapp_numero'   => $establecimiento?->getTelefonoPrincipal(),
            'whatsapp_url'      => $wa($establecimiento?->getTelefonoPrincipal()),
            'emergencia_numero' => $establecimiento?->getTelefonoEmergencia(),
            'emergencia_url'    => $wa($establecimiento?->getTelefonoEmergencia()),
        ];
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

        // ⚠️ **Los nombres son los MISMOS que en las plantillas** (`PmsMessageDataResolver`), y
        // manda aquel vocabulario aunque este sistema sea anterior. El motivo no es de gusto: el
        // nombre de un marcador de plantilla aprobada en Meta **no se puede cambiar** —hacerlo es
        // crear otra y esperar el bloqueo de 30 días, §18 de `docs/Mensajeria.md`—, así que el
        // lado que se mueve es éste. Hasta el 14/09/2026 el mismo dato se llamaba distinto en cada
        // sitio (`host_whatsapp` / `whatsapp_numero`, `booking_ref` / `locator`) y, peor,
        // `check_in` era la HORA mientras `checkin_date` era la FECHA: dos nombres casi iguales
        // para cosas distintas, en dos sistemas que el mismo editor usa el mismo día.
        $valores = array_filter([
            'room_name'     => $unidad->getNombre(),
            'property_name' => $establecimiento?->getNombreComercial(),
            // El nombre del anfitrión y su WhatsApp ya los pintaba
            // GuiaUnidadView.vue (tarjeta de contacto), pero buildResponse()
            // nunca los llegó a poner en text_fixed: la tarjeta jamás se
            // renderizaba. Se cablean aquí a los datos del establecimiento.
            'host_name'     => $establecimiento?->getNombreComercial(),
            'guest_name'    => $reserva?->getNombreCliente(),
            'locator'       => $reserva?->getLocalizador(),
            // La HORA lo dice el nombre, porque al lado viven las fechas.
            'hora_checkin'  => ($evento?->getInicio() ?? $establecimiento?->getHoraCheckIn())?->format('H:i'),
            'hora_checkout' => ($evento?->getFin() ?? $establecimiento?->getHoraCheckOut())?->format('H:i'),
            'checkin_date'  => $evento?->getInicio()?->format('d/m/Y'),
            'checkout_date' => $evento?->getFin()?->format('d/m/Y'),
        ] + self::telefonos($establecimiento), static fn (?string $v): bool => null !== $v && '' !== $v);

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

        // 🔒 LOS DEL EDIFICIO, Y AQUÍ SE DECIDE QUÉ ENTRA EN LA GUÍA.
        //
        // `PmsEstablecimientoMediaTipo::visibilidad()` devuelve `null` para lo que no es del
        // huésped —las dos fotos y vídeos de la caja del DINERO, que entrega un operador—, y ese
        // `null` se respeta **sólo aquí**. Es lo que lo hace seguro: ésta es la única puerta por
        // la que un medio entra en la guía, así que escribir `{{ foto_caja_dinero }}` en un ítem
        // no filtra nada — no hay valor que resolver y el marcador se quita.
        //
        // Si esta comprobación se copiara a un segundo sitio, el día que alguien añada un tipo
        // nuevo tendría que acordarse de los dos. Por eso no se copia.
        foreach (PmsEstablecimientoMediaTipo::cases() as $tipo) {
            $nivel = $tipo->visibilidad();

            if ($nivel === null) {
                continue;
            }

            $valor = $establecimiento?->medio($tipo)?->getValor();

            if ($valor !== null && $valor !== '') {
                $medios[$tipo->clave()] = ['valor' => $valor, 'nivel' => $nivel];
            }
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
            'codigo_caja_casita'   => $unidad->getCodigoCajaCasita(),
            'codigo_caja_llaves' => $establecimiento?->getCodigoCajaLlaves(),
            'codigo_caja_dinero'  => $establecimiento?->getCodigoCajaDinero(),
        ], static fn (?string $v): bool => null !== $v && '' !== $v);

        return new self($valores, $sensibles, $unidad->getWifiNetworks(), $medios);
    }
}
