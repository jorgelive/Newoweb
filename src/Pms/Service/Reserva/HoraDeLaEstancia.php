<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use DateTimeImmutable;

/**
 * La hora de entrada y de salida de una estancia: contra qué se compara y cómo se escribe.
 *
 * La usan las dos puertas por las que entra una hora: la del equipo
 * (`AplicarCambioHorarioSkill`, que además puede marcar el horario extra) y la del huésped
 * (`ConfirmarHoraSkill`, que sólo apunta lo que cabe en el horario). Una sola regla para las dos:
 * si el equipo y el huésped decidieran distinto qué es «fuera del horario», la misma hora se
 * apuntaría por una puerta y se rechazaría por la otra.
 */
final readonly class HoraDeLaEstancia
{
    /** Si el establecimiento no tiene su horario configurado. */
    public const string CHECK_IN_POR_DEFECTO = '14:00';
    public const string CHECK_OUT_POR_DEFECTO = '10:00';

    /** La hora `HH:MM` de 24 h, o `null` si no lo es. */
    public static function normalizar(string $hora): ?string
    {
        $hora = trim($hora);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) === 1 ? $hora : null;
    }

    /** El check-in o el check-out del alojamiento, `HH:MM`. */
    public function limite(PmsEventoCalendario $evento, bool $esSalida): string
    {
        $est = $evento->getPmsUnidad()?->getEstablecimiento();
        $hora = $esSalida ? $est?->getHoraCheckOut() : $est?->getHoraCheckIn();

        return $hora?->format('H:i') ?? ($esSalida ? self::CHECK_OUT_POR_DEFECTO : self::CHECK_IN_POR_DEFECTO);
    }

    /**
     * ¿La hora se sale del horario normal?
     *
     * Asimétrico a propósito: salir DESPUÉS del check-out ocupa la casita más tiempo, y entrar
     * ANTES del check-in la ocupa antes. Salir a las 09:00 o entrar a las 18:00 no molestan a
     * nadie — son datos útiles para el equipo de limpieza, no horarios extra.
     *
     * Se comparan cadenas `HH:MM`, que en formato 24 h ordenan igual que el reloj.
     */
    public function excede(PmsEventoCalendario $evento, string $hora, bool $esSalida): bool
    {
        $limite = $this->limite($evento, $esSalida);

        return $esSalida ? $hora > $limite : $hora < $limite;
    }

    /**
     * Escribe la hora conservando el DÍA, y la deja como CONFIRMADA.
     *
     * ⚠️ Nunca se toca la fecha, sólo la hora de pared (§12.5.5 del doc de sync). Mover el día
     * de una estancia es otra operación —prohibida en OTA— y se hace en el calendario del panel.
     * Cambiar sólo la hora es seguro incluso en reservas de OTA porque el push al canal manda
     * `Y-m-d` (`BookingsPushMappingStrategy`): el portal no ve las horas.
     *
     * 🕐 **Confirmada**, porque la hora por sí sola no lo dice: una salida a las 10:00 es la de
     * todos, la haya confirmado el huésped o no haya dicho nada (Jorge, 01/10/2026).
     */
    public function registrar(PmsEventoCalendario $evento, string $hora, bool $esSalida, ?DateTimeImmutable $ahora = null): void
    {
        $actual = $esSalida ? $evento->getFin() : $evento->getInicio();

        if ($actual === null) {
            return;
        }

        $nueva = DateTimeImmutable::createFromInterface($actual)
            ->setTime((int) substr($hora, 0, 2), (int) substr($hora, 3, 2));

        // Si no cambia, el mismo objeto: Doctrine no ve cambio y no sale un push por nada.
        if ($nueva->format('Y-m-d H:i') !== $actual->format('Y-m-d H:i')) {
            $esSalida ? $evento->setFin($nueva) : $evento->setInicio($nueva);
        }

        $ahora ??= new DateTimeImmutable();
        $esSalida ? $evento->setSalidaConfirmadaAt($ahora) : $evento->setLlegadaConfirmadaAt($ahora);
    }
}
