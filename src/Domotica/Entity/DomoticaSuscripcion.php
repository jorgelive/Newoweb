<?php

declare(strict_types=1);

namespace App\Domotica\Entity;

use App\Domotica\Repository\DomoticaSuscripcionRepository;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Entity\PmsEventoCalendario;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Lo que un aparato le imputa a una estancia concreta.
 *
 * Es el estado actual: cuánto marcaba el contador cuando entró el huésped, cuánto marca ahora y
 * cuánto de eso es suyo. Todo lo de aquí es **proyección de `DomoticaLectura`** —se puede volver a
 * calcular entero recorriendo la bitácora—, y existe para no tener que recorrerla cada vez que se
 * abre la app.
 *
 * ```
 *   suscripción   dispositivo ↔ evento   consumoTotal · consumoCliente   ← estado actual
 *   bitácora      una fila por hora      consumoTotal · consumoCliente   ← histórico y auditoría
 * ```
 *
 * ⚠️ **La proyección nunca es la fuente.** Si las dos discrepan, la buena es la bitácora: se
 * recalcula desde ella y se corrige aquí, nunca al revés. Es la regla que hace defendible cobrar
 * por consumo, porque cada sol facturado se puede rastrear hasta una lectura con su hora.
 *
 * ## Por qué la tarifa y el crédito están congelados aquí
 *
 * El precio del kW·h de Electro Sur Este lleva años quieto, pero el día que se mueva no puede
 * recalcular hacia atrás lo que ya se le dijo a un huésped que iba a pagar. Se copian al abrir la
 * suscripción y se quedan — mismo criterio que `FinEnlacePago` con sus importes.
 *
 * ## El contador total NO se reinicia nunca
 *
 * Reiniciar es poner `consumoCliente` a cero dejando `consumoTotal` donde estaba. El aparato
 * siguió marcando lo que marcaba; lo único que se perdona es lo que se le imputaba a esta
 * persona. Así el huésped puede seguir viendo el número del aparato y comprobar que cuadra.
 */
#[ORM\Entity(repositoryClass: DomoticaSuscripcionRepository::class)]
#[ORM\Table(name: 'domotica_suscripcion')]
// Un aparato no puede tener dos cuentas abiertas contra la misma estancia: sería cobrar dos veces
// el mismo consumo, y el cron no sabría en cuál de las dos escribir.
#[ORM\UniqueConstraint(name: 'uniq_domotica_suscripcion_dispositivo_evento', columns: ['dispositivo_id', 'evento_id'])]
// El cron pregunta siempre lo mismo: «¿qué suscripciones están vivas ahora?».
#[ORM\Index(name: 'idx_domotica_suscripcion_activa', columns: ['activa'])]
#[ORM\Index(name: 'idx_domotica_suscripcion_evento', columns: ['evento_id'])]
#[ORM\HasLifecycleCallbacks]
class DomoticaSuscripcion
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: DomoticaDispositivo::class)]
    #[ORM\JoinColumn(name: 'dispositivo_id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['domotica_suscripcion:read'])]
    private ?DomoticaDispositivo $dispositivo = null;

    /**
     * El evento de calendario, no la reserva.
     *
     * Es lo que de verdad ocupa la casita entre dos fechas: una reserva puede partirse en varios
     * eventos, y quien está durmiendo ahí —y encendiendo el calefactor— pertenece a uno concreto.
     */
    #[ORM\ManyToOne(targetEntity: PmsEventoCalendario::class)]
    #[ORM\JoinColumn(name: 'evento_id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['domotica_suscripcion:read'])]
    private ?PmsEventoCalendario $evento = null;

    /** Desde cuándo se le imputa consumo: la hora de check-in, no la medianoche. */
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['domotica_suscripcion:read'])]
    private ?DateTimeImmutable $iniciaEn = null;

    /** Hasta cuándo. La hora de check-out; después de esto el cron ya no la toca. */
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['domotica_suscripcion:read'])]
    private ?DateTimeImmutable $terminaEn = null;

    /**
     * Lo que marcaba el aparato al entrar.
     *
     * Éste es el número de la frase que se le enseña al huésped: «cuando entraste a las 14:00 el
     * contador marcaba 546». Se guarda aparte de `consumoTotal` porque el reinicio mueve la
     * referencia pero no debe borrar de dónde se partió.
     */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, options: ['default' => '0.000'])]
    #[Groups(['domotica_suscripcion:read'])]
    private string $lecturaInicial = '0.000';

    /** Última lectura del contador físico. Espejo de `DomoticaDispositivo::$lecturaTotal`. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, options: ['default' => '0.000'])]
    #[Groups(['domotica_suscripcion:read'])]
    private string $consumoTotal = '0.000';

    /**
     * Lo que se le imputa al huésped, en kW·h.
     *
     * No es `consumoTotal - lecturaInicial`: los reinicios rompen esa resta a propósito. Se
     * acumula sumando incrementos, que es lo que sobrevive a que se ponga a cero a mitad de
     * estancia.
     */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, options: ['default' => '0.000'])]
    #[Groups(['domotica_suscripcion:read'])]
    private string $consumoCliente = '0.000';

    /** Soles por kW·h, según el tarifario de Electro Sur Este vigente al abrir la cuenta. */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 4)]
    #[Groups(['domotica_suscripcion:read'])]
    private string $tarifaSolesKwh = '0.0000';

    /**
     * Soles regalados por día y por aparato.
     *
     * Es lo que evita la discusión con quien usa el calefactor con cabeza: si no se pasa del
     * crédito, no paga nada y ni se entera de que existe un contador. Lo que cobra de verdad es
     * el exceso.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['domotica_suscripcion:read'])]
    private string $creditoDiarioSoles = '0.00';

    /** ¿La sigue muestreando el cron? Se apaga al cerrar la estancia, no se borra. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['domotica_suscripcion:read'])]
    private bool $activa = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['domotica_suscripcion:read'])]
    private ?DateTimeImmutable $cerradaEn = null;

    public function getDispositivo(): ?DomoticaDispositivo
    {
        return $this->dispositivo;
    }

    public function setDispositivo(?DomoticaDispositivo $dispositivo): self
    {
        $this->dispositivo = $dispositivo;

        return $this;
    }

    public function getEvento(): ?PmsEventoCalendario
    {
        return $this->evento;
    }

    public function setEvento(?PmsEventoCalendario $evento): self
    {
        $this->evento = $evento;

        return $this;
    }

    public function getIniciaEn(): ?DateTimeImmutable
    {
        return $this->iniciaEn;
    }

    public function setIniciaEn(?DateTimeImmutable $iniciaEn): self
    {
        $this->iniciaEn = $iniciaEn;

        return $this;
    }

    public function getTerminaEn(): ?DateTimeImmutable
    {
        return $this->terminaEn;
    }

    public function setTerminaEn(?DateTimeImmutable $terminaEn): self
    {
        $this->terminaEn = $terminaEn;

        return $this;
    }

    public function getLecturaInicial(): string
    {
        return $this->lecturaInicial;
    }

    public function setLecturaInicial(string $lecturaInicial): self
    {
        $this->lecturaInicial = $lecturaInicial;

        return $this;
    }

    public function getConsumoTotal(): string
    {
        return $this->consumoTotal;
    }

    public function setConsumoTotal(string $consumoTotal): self
    {
        $this->consumoTotal = $consumoTotal;

        return $this;
    }

    public function getConsumoCliente(): string
    {
        return $this->consumoCliente;
    }

    public function setConsumoCliente(string $consumoCliente): self
    {
        $this->consumoCliente = $consumoCliente;

        return $this;
    }

    public function getTarifaSolesKwh(): string
    {
        return $this->tarifaSolesKwh;
    }

    public function setTarifaSolesKwh(string $tarifaSolesKwh): self
    {
        $this->tarifaSolesKwh = $tarifaSolesKwh;

        return $this;
    }

    public function getCreditoDiarioSoles(): string
    {
        return $this->creditoDiarioSoles;
    }

    public function setCreditoDiarioSoles(string $creditoDiarioSoles): self
    {
        $this->creditoDiarioSoles = $creditoDiarioSoles;

        return $this;
    }

    public function isActiva(): bool
    {
        return $this->activa;
    }

    public function setActiva(bool $activa): self
    {
        $this->activa = $activa;

        return $this;
    }

    public function getCerradaEn(): ?DateTimeImmutable
    {
        return $this->cerradaEn;
    }

    public function setCerradaEn(?DateTimeImmutable $cerradaEn): self
    {
        $this->cerradaEn = $cerradaEn;

        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cálculo. Todo derivado: aquí no se guarda ni un sol, se deduce del consumo.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Días de crédito devengados a fecha `$hasta`.
     *
     * Cuenta **días empezados**, no completos: quien entra a las 14:00 tiene su crédito del primer
     * día entero desde el minuto uno. Cobrarle un día a prorrata por haber llegado tarde sería
     * exactamente la clase de detalle que arruina la sensación de trato justo que busca todo esto.
     *
     * Se topa en la duración de la estancia para que una suscripción que se quedó abierta por un
     * fallo del cierre no siga regalando crédito indefinidamente.
     */
    public function diasDeCredito(?DateTimeImmutable $hasta = null): int
    {
        if ($this->iniciaEn === null) {
            return 0;
        }

        $hasta ??= new DateTimeImmutable();

        if ($this->terminaEn !== null && $hasta > $this->terminaEn) {
            $hasta = $this->terminaEn;
        }

        if ($hasta < $this->iniciaEn) {
            return 0;
        }

        $transcurridos = $this->iniciaEn->diff($hasta)->days ?? 0;

        return $transcurridos + 1;
    }

    /** Soles de cortesía acumulados hasta ahora. */
    public function creditoAcumuladoSoles(?DateTimeImmutable $hasta = null): float
    {
        return $this->diasDeCredito($hasta) * (float) $this->creditoDiarioSoles;
    }

    /** Lo que valdría el consumo si no hubiera crédito. Es el número «bruto». */
    public function consumoClienteSoles(): float
    {
        return (float) $this->consumoCliente * (float) $this->tarifaSolesKwh;
    }

    /**
     * Lo que de verdad se le cobra: el exceso sobre el crédito, nunca negativo.
     *
     * El crédito no sobrante no se acumula ni se devuelve — es una cortesía diaria, no un saldo.
     */
    public function importeACobrarSoles(?DateTimeImmutable $hasta = null): float
    {
        return max(0.0, $this->consumoClienteSoles() - $this->creditoAcumuladoSoles($hasta));
    }

    /**
     * Qué parte del crédito del periodo lleva gastada, de 0 a 1.
     *
     * Es lo que pinta la barra en la app del huésped. Se topa en 1 porque una barra al 240 % no
     * dice nada que no diga el importe en soles, y asusta sin informar.
     */
    public function proporcionDeCreditoUsada(?DateTimeImmutable $hasta = null): float
    {
        $credito = $this->creditoAcumuladoSoles($hasta);

        if ($credito <= 0.0) {
            return $this->consumoClienteSoles() > 0.0 ? 1.0 : 0.0;
        }

        return min(1.0, $this->consumoClienteSoles() / $credito);
    }

    /** ¿Le toca muestreo al cron en este momento? */
    public function vigenteEn(?DateTimeImmutable $momento = null): bool
    {
        $momento ??= new DateTimeImmutable();

        return $this->activa
            && $this->iniciaEn !== null && $this->iniciaEn <= $momento
            && $this->terminaEn !== null && $this->terminaEn >= $momento;
    }
}
