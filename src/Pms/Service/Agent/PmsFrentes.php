<?php

declare(strict_types=1);

namespace App\Pms\Service\Agent;

use App\Message\Contract\Frente;
use App\Message\Contract\FrentesPorDominioInterface;
use App\Message\Contract\MomentoDeFrente;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Repository\PmsReservaRepository;
use App\Service\Phone\PhoneSanitizer;

/**
 * Los asuntos abiertos del alojamiento para un teléfono.
 *
 * ── No reinventa el desempate ───────────────────────────────────────────────
 * Quién es «la reserva que toca» cuando un número tiene tres vivas ya está resuelto, probado y
 * documentado en `PmsReservaRepository::findVivasByTelefono()` (actual antes que próxima, con
 * el rastro en el log de que había más de una). Esta clase **envuelve** esa lista; si el
 * criterio cambia alguna vez, cambia allí y aquí no hay nada que tocar.
 *
 * ── El momento lo decide la CONSULTA, no un cálculo ────────────────────────
 * Un `inquiry` de una OTA es un asunto en VENTA; una reserva con tramos que identifican a un
 * huésped es un asunto en OPERACIÓN. Cada uno viene de su propia consulta al repositorio, así
 * que el momento no se deduce: se sabe por de dónde salió la fila.
 *
 * ── La etiqueta es lo único que ve el modelo ────────────────────────────────
 * Por eso la escribe el dominio y no el núcleo: aquí se sabe que la casita y las fechas se
 * pueden decir, y que el localizador, el saldo o el teléfono no pintan nada en un desplegable
 * de desambiguación. Va sin uuids: el id del frente es un hash opaco.
 */
final readonly class PmsFrentes implements FrentesPorDominioInterface
{
    public const NEGOCIO = 'hotelero';

    public function __construct(
        private PmsReservaRepository $reservas,
        private PhoneSanitizer $telefonos,
    ) {}

    public function negocio(): string
    {
        return self::NEGOCIO;
    }

    /**
     * El `context_type` de una conversación de alojamiento.
     *
     * `manual` NO entra a propósito: es el contexto de quien escribió sin tener nada —el
     * walk-in—, y no es de este negocio ni de ningún otro. Ese caso se cubre por la puerta de
     * venta, que está abierta para todos, y no colgándolo del alojamiento porque históricamente
     * fuera el único negocio que había.
     */
    public function supports(?string $contextType): bool
    {
        return $contextType === 'pms_reserva';
    }

    /**
     * Sí: el alojamiento se le vende a cualquiera que escriba.
     *
     * Es lo que pone «Reservar o ampliar alojamiento» en la lista de TODO el mundo, tenga o no
     * reserva. Con eso, el huésped que quiere quedarse dos noches más y el cliente de tours que
     * pregunta «¿qué casitas tenéis?» eligen el mismo frente, y ninguno de los dos necesita un
     * caso especial en el triaje.
     */
    public function esVendible(): bool
    {
        return true;
    }

    public function etiquetaDeVenta(): string
    {
        return 'Reservar o ampliar alojamiento';
    }

    /**
     * ── DOS fuentes, y hacen falta las dos ──────────────────────────────────
     * `findVivasByTelefono()` sólo devuelve reservas con algún tramo en
     * {@see PmsEventoEstado::IDENTIFICAN_HUESPED}, donde `abierto` NO está. Con ella sola,
     * preguntar «¿esto es una consulta?» daba siempre que no y el momento `Venta` con entidad
     * era **inalcanzable**: el caso que motivó el modelo entero —un huésped alojado con una
     * consulta de ampliación pendiente— no podía darse.
     *
     * Por eso la venta viene de su hermana, `findConsultasAbiertasByTelefono()`. Están
     * separadas a propósito y no unidas en una consulta con más estados: la primera decide
     * **de quién es un número** —una frontera de autorización— y la segunda sólo dice qué se le
     * está vendiendo. Mezclarlas convertiría una consulta de Airbnb en un huésped identificado,
     * que es exactamente el incidente que motivó `VinculoComercial`.
     *
     * @return list<Frente>
     */
    public function frentesVivos(?string $telefono): array
    {
        $frentes = [];

        foreach ($this->reservas->findVivasByTelefono($telefono, $this->telefonos) as $reserva) {
            $frentes[] = $this->frenteDe($reserva, MomentoDeFrente::Operacion);
        }

        foreach ($this->reservas->findConsultasAbiertasByTelefono($telefono, $this->telefonos) as $consulta) {
            $frentes[] = $this->frenteDe($consulta, MomentoDeFrente::Venta);
        }

        return $frentes;
    }

    private function frenteDe(PmsReserva $reserva, MomentoDeFrente $momento): Frente
    {
        return new Frente(
            negocio: self::NEGOCIO,
            momento: $momento,
            etiqueta: $this->etiquetaDe($reserva, $momento),
            entidadTipo: 'pms_reserva',
            entidadId: (string) $reserva->getId(),
        );
    }

    /**
     * «Tu reserva Casita 3, 12/03–15/03» — lo justo para reconocerla de un vistazo.
     *
     * Sin localizador ni importes: esto se le enseña al modelo para que pueda preguntar «¿me
     * hablas de ésta o de la otra?», y para eso basta con la casita y los días. Cuanto menos
     * viaje, menos hay que vigilar.
     *
     * ⚠️ **Se mira sólo los tramos que cuentan para ESTE momento**, no la colección entera ni
     * `getFechaLlegada()`/`getFechaSalida()`. Esos dos son agregados mín/máx de todos los
     * tramos, y el propio repositorio ya avisa del fallo: una reserva con la Casita 1 cancelada
     * y la Casita 3 viva daba «Casita 1 + Casita 3, 05/09–20/09» cuando la estancia real es del
     * 12 al 15 en la 3. Esa etiqueta es justo lo que el modelo le lee al cliente para
     * desambiguar, así que una ventana inventada ahí es peor que no tener etiqueta.
     */
    private function etiquetaDe(PmsReserva $reserva, MomentoDeFrente $momento): string
    {
        $estados = $momento === MomentoDeFrente::Venta
            ? [PmsEventoEstado::CODIGO_ABIERTO]
            : PmsEventoEstado::IDENTIFICAN_HUESPED;

        $unidades = [];
        $llegada = null;
        $salida = null;

        foreach ($reserva->getEventosCalendario() as $evento) {
            // Las extensiones fuera: son la noche fantasma de un horario extra, y estirarían la
            // ventana un día por su cuenta.
            if ($evento->getEventoOrigen() !== null
                || !in_array($evento->getEstado()?->getId(), $estados, true)
            ) {
                continue;
            }

            $nombre = $evento->getPmsUnidad()?->getNombre();

            if ($nombre !== null && !in_array($nombre, $unidades, true)) {
                $unidades[] = $nombre;
            }

            $inicio = $evento->getInicio();
            $fin = $evento->getFin();

            if ($inicio !== null && ($llegada === null || $inicio < $llegada)) {
                $llegada = $inicio;
            }

            if ($fin !== null && ($salida === null || $fin > $salida)) {
                $salida = $fin;
            }
        }

        $partes = [];

        if ($unidades !== []) {
            $partes[] = implode(' + ', $unidades);
        }

        if ($llegada !== null && $salida !== null) {
            // Numérico y no `d M`: ese formato escupe el mes en INGLÉS («07 Aug»), porque
            // `DateTime::format()` no mira la configuración regional. Meter inglés en una
            // etiqueta que el modelo puede acabar citándole al huésped no compensa el
            // capricho tipográfico, y traer `IntlDateFormatter` para tres caracteres, menos.
            $partes[] = sprintf('%s–%s', $llegada->format('d/m'), $salida->format('d/m'));
        }

        $detalle = implode(', ', $partes);

        // Sin casita ni fechas —una consulta recién llegada de un canal— la etiqueta genérica
        // sigue sirviendo para distinguirla de un tour, que es para lo único que se usa.
        return $detalle === ''
            ? 'Tu reserva de alojamiento'
            : sprintf('Tu reserva %s', $detalle);
    }
}
