<?php

declare(strict_types=1);

namespace App\Pms\Service\Agent;

use App\Message\Contract\Frente;
use App\Message\Contract\FrentesPorDominioInterface;
use App\Message\Contract\MomentoDeFrente;
use App\Pms\Entity\PmsReserva;
use App\Pms\Repository\PmsReservaRepository;
use App\Pms\Service\Message\PmsReservaMessageContext;
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
 * ── El momento sale del mismo sitio que el vínculo ──────────────────────────
 * Una consulta sin confirmar —el `inquiry` de una OTA, un bloqueo— es un asunto en VENTA: hay
 * alguien decidiendo. Una reserva confirmada es un asunto en OPERACIÓN: hay una estancia que
 * atender. Es exactamente el mismo corte que hace `PmsReservaMessageContext::getVinculo()` para
 * decidir entre `Interesado` y `Cliente`, y se mantiene igual a propósito: dos definiciones de
 * «esto ya está vendido» acabarían separándose.
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
     * @return list<Frente>
     */
    public function frentesVivos(?string $telefono): array
    {
        $frentes = [];

        foreach ($this->reservas->findVivasByTelefono($telefono, $this->telefonos) as $reserva) {
            // Una reserva cancelada no es un asunto abierto: no hay nada que atender ni nada
            // que vender ahí. Quien quiera volver a reservar usa la puerta de venta.
            if ($reserva->isCancelada()) {
                continue;
            }

            $frentes[] = new Frente(
                negocio: self::NEGOCIO,
                momento: $this->momentoDe($reserva),
                etiqueta: $this->etiquetaDe($reserva),
                entidadTipo: 'pms_reserva',
                entidadId: (string) $reserva->getId(),
            );
        }

        return $frentes;
    }

    /**
     * Sin confirmar = todavía se está vendiendo; confirmada = hay estancia que atender.
     *
     * ⚠️ **No se reimplementa el corte, se reutiliza.** `PmsReservaMessageContext` es la clase
     * que decide si una reserva es una consulta (`inquiry`/bloqueo) o algo vendido, y es la
     * misma que ya alimenta `VinculoComercial` para todo el sistema de mensajería. Escribir
     * aquí un segundo `foreach` sobre los estados de los eventos habría dado dos definiciones
     * de «esto ya está vendido» destinadas a separarse — y el día que se separaran, el agente
     * le hablaría con voz de cliente a quien el resto del sistema tiene por interesado.
     *
     * Se instancia a mano y no se inyecta porque es un envoltorio de una entidad, no un
     * servicio: el mismo uso que le dan `Beds24ReceivePersister` y `PmsReservaRecalculoService`.
     * La información financiera se omite: `isAbiertoOrBloqueo()` sólo mira los eventos.
     */
    private function momentoDe(PmsReserva $reserva): MomentoDeFrente
    {
        return (new PmsReservaMessageContext($reserva))->isAbiertoOrBloqueo()
            ? MomentoDeFrente::Venta
            : MomentoDeFrente::Operacion;
    }

    /**
     * «Tu reserva Casita 3, 12–15 mar» — lo justo para reconocerla de un vistazo.
     *
     * Sin localizador ni importes: esto se le enseña al modelo para que pueda preguntar «¿me
     * hablas de ésta o de la otra?», y para eso basta con la casita y los días. Cuanto menos
     * viaje, menos hay que vigilar.
     */
    private function etiquetaDe(PmsReserva $reserva): string
    {
        $unidades = [];

        foreach ($reserva->getEventosCalendario() as $evento) {
            $nombre = $evento->getPmsUnidad()?->getNombre();

            if ($nombre !== null && !in_array($nombre, $unidades, true)) {
                $unidades[] = $nombre;
            }
        }

        $llegada = $reserva->getFechaLlegada();
        $salida = $reserva->getFechaSalida();

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
