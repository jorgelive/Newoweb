<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Monta una estancia (`PmsEventoCalendario`) sobre una reserva. **No hace flush.**
 *
 * Existe porque crear una reserva y añadirle una estancia después son **la misma operación** en
 * su parte difícil: resolver las horas del establecimiento, comprobar que la casita está libre,
 * poner los estados maestros y marcar el canal para que los cargos automáticos se disparen.
 * `CrearReservaSkill` lo hacía por dentro y `CrearEstanciaSkill` habría tenido que repetirlo;
 * dos copias de esto se separan a la primera —basta que una olvide el `setChannel()`— y el
 * síntoma sería una reserva sin cargos que nadie relaciona con la causa.
 *
 * ### Las horas vienen del establecimiento
 *
 * `getHoraCheckIn()` / `getHoraCheckOut()` de la unidad. Sólo se cae a 14:00/10:00 si no están
 * puestas, y eso es un respaldo, no la regla.
 *
 * ### El canal es DIRECTO aunque la reserva sea de una OTA
 *
 * Una estancia añadida a mano es una venta directa: la OTA no sabe nada de ella. Marcarla así
 * es además lo que hace que `PmsCargosAutomaticosService::aplica()` le genere los cargos del
 * tarifario — con el canal de la OTA se quedaría sin ninguno, en silencio.
 *
 * Ver docs/Mensajeria.md §11.
 */
final readonly class PmsEstanciaCreator
{
    private const string HORA_ENTRADA_RESPALDO = '14:00';
    private const string HORA_SALIDA_RESPALDO = '10:00';

    public function __construct(
        private EntityManagerInterface $em,
        private PmsDisponibilidadService $disponibilidad,
    ) {}

    /**
     * ¿Está libre esa casita en ese rango? Devuelve el motivo si no, o `null` si sí.
     *
     * Se expone aparte de {@see crear()} para poder decir «está ocupada» en una previsualización
     * sin haber construido nada.
     */
    public function motivoNoDisponible(
        PmsUnidad $unidad,
        DateTimeInterface $desde,
        DateTimeInterface $hasta
    ): ?string {
        $ocupacion = $this->disponibilidad->ocupacion($desde, $hasta, (string) $unidad->getId());

        if ($ocupacion === []) {
            return null;
        }

        return sprintf(
            '%s NO está libre del %s al %s: %s.',
            $unidad->getNombre(),
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
            implode('; ', array_map(
                static fn ($o) => sprintf(
                    '%s del %s al %s',
                    $o->huesped ?? 'bloqueo',
                    $o->entra,
                    $o->sale
                ),
                $ocupacion
            ))
        );
    }

    /**
     * Las horas con las que nacerá la estancia, para poder enseñarlas antes de crearla.
     *
     * @return array{entrada: DateTimeImmutable, salida: DateTimeImmutable}
     */
    public function conHorario(
        PmsUnidad $unidad,
        DateTimeInterface $desde,
        DateTimeInterface $hasta
    ): array {
        $est = $unidad->getEstablecimiento();

        $horaIn = $est?->getHoraCheckIn()?->format('H:i') ?? self::HORA_ENTRADA_RESPALDO;
        $horaOut = $est?->getHoraCheckOut()?->format('H:i') ?? self::HORA_SALIDA_RESPALDO;

        [$hIn, $mIn] = array_map('intval', explode(':', $horaIn));
        [$hOut, $mOut] = array_map('intval', explode(':', $horaOut));

        return [
            'entrada' => DateTimeImmutable::createFromInterface($desde)->setTime($hIn, $mIn),
            'salida' => DateTimeImmutable::createFromInterface($hasta)->setTime($hOut, $mOut),
        ];
    }

    /**
     * Crea la estancia y la engancha a la reserva. **El flush lo hace quien llama**, para que
     * pueda meter la reserva y su primera estancia en la misma transacción.
     *
     * @throws InvalidArgumentException si la casita está ocupada o faltan los estados maestros.
     */
    public function crear(
        PmsReserva $reserva,
        PmsUnidad $unidad,
        DateTimeInterface $desde,
        DateTimeInterface $hasta,
        int $adultos,
        int $ninos = 0,
        string $estadoId = PmsEventoEstado::CODIGO_PENDIENTE
    ): PmsEventoCalendario {
        if (($motivo = $this->motivoNoDisponible($unidad, $desde, $hasta)) !== null) {
            throw new InvalidArgumentException($motivo);
        }

        $estado = $this->em->getRepository(PmsEventoEstado::class)->find($estadoId);
        $estadoPago = $this->em->getRepository(PmsEventoEstadoPago::class)->find('no-pagado');

        if ($estado === null || $estadoPago === null) {
            throw new InvalidArgumentException('Faltan los estados maestros en la base de datos.');
        }

        $horario = $this->conHorario($unidad, $desde, $hasta);

        $evento = new PmsEventoCalendario();
        $evento->setReserva($reserva);
        $evento->setPmsUnidad($unidad);
        $evento->setEstado($estado);
        $evento->setEstadoPago($estadoPago);
        $evento->setInicio($horario['entrada']);
        $evento->setFin($horario['salida']);
        $evento->setCantidadAdultos(max(1, $adultos));
        $evento->setCantidadNinos(max(0, $ninos));
        $evento->setIsOta(false);

        // Ver el docblock de la clase: sin esto se queda sin cargos, y sin ruido.
        $directo = $this->em->getRepository(PmsChannel::class)->find(PmsChannel::CODIGO_DIRECTO);
        if ($directo !== null) {
            $evento->setChannel($directo);
        }

        $reserva->addEventosCalendario($evento);
        $this->em->persist($evento);

        return $evento;
    }
}
