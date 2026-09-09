<?php

declare(strict_types=1);

namespace App\Domotica\Entity;

use App\Domotica\Repository\DomoticaCambioEstadoRepository;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Cuándo se encendió y cuándo se apagó un aparato.
 *
 * ── Por qué hace falta una tabla más ────────────────────────────────────────
 *
 * De un aparato que sólo conmuta no se sabía NADA del pasado: `DomoticaDispositivo::$encendido` es
 * una sola casilla que el muestreo siguiente pisa. Si una estufa estuvo encendida seis horas, no
 * quedaba constancia de ninguna clase — y son **nueve de los doce calefactores**, porque sólo dos
 * tienen contómetro.
 *
 * `DomoticaLectura` no servía para esto: sus tres columnas de consumo son `NOT NULL` y un
 * interruptor no consume nada que contar. Mezclarlos habría obligado a inventar ceros y a que la
 * bitácora —que es la prueba de lo que se cobra— llevara dentro filas que no prueban nada.
 *
 * ── Sólo se escribe cuando CAMBIA ───────────────────────────────────────────
 *
 * No es un registro del muestreo: es un registro de transiciones. Guardar cada ciclo daría 288
 * filas diarias por aparato diciendo lo mismo, y la pregunta que se le hace a esta tabla —«¿cuánto
 * llevaba encendida?»— se contesta con dos filas, no con doscientas.
 *
 * ── ⚠️ Y esto NO se factura ─────────────────────────────────────────────────
 *
 * Horas encendido × vatios nominales da una cifra plausible que **no cuadra con ningún contador**,
 * y en cuanto el huésped la discuta no hay con qué defenderla. Es la misma trampa que integrar
 * `cur_power` (§12.1). Sirve para informar al huésped, para operar —«quedó encendida en una casa
 * vacía desde las 11:00»— y para decidir en qué casitas compensa comprar un contómetro. Para
 * cobrar, sólo `DomoticaLectura`.
 */
#[ORM\Entity(repositoryClass: DomoticaCambioEstadoRepository::class)]
#[ORM\Table(name: 'domotica_cambio_estado')]
#[ORM\Index(name: 'idx_domotica_cambio_dispositivo', columns: ['dispositivo_id', 'ocurrido_en'])]
#[ORM\Index(name: 'idx_domotica_cambio_suscripcion', columns: ['suscripcion_id', 'ocurrido_en'])]
#[ORM\HasLifecycleCallbacks]
class DomoticaCambioEstado
{
    use IdTrait;
    use TimestampTrait;

    public function __construct()
    {
        $this->initializeId();
    }

    #[ORM\ManyToOne(targetEntity: DomoticaDispositivo::class)]
    #[ORM\JoinColumn(name: 'dispositivo_id', nullable: false, onDelete: 'CASCADE')]
    private ?DomoticaDispositivo $dispositivo = null;

    /**
     * La estancia a la que cae este cambio, si había alguna.
     *
     * **Nullable**: los aparatos se encienden y se apagan también con la casa vacía —el personal de
     * limpieza, un técnico, nosotros mismos—, y esos cambios son justamente los que más interesa
     * tener registrados.
     */
    #[ORM\ManyToOne(targetEntity: DomoticaSuscripcion::class)]
    #[ORM\JoinColumn(name: 'suscripcion_id', nullable: true, onDelete: 'SET NULL')]
    private ?DomoticaSuscripcion $suscripcion = null;

    /** El estado al que pasó: `true` encendido. */
    #[ORM\Column(type: 'boolean')]
    #[Groups(['domotica_cambio:read'])]
    private bool $encendido = false;

    /**
     * Cuándo ocurrió, lo mejor que se sepa. Ver `$horaExacta`.
     */
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['domotica_cambio:read'])]
    private DateTimeImmutable $ocurridoEn;

    /**
     * ⚠️ ¿La hora la dijo el APARATO, o es la del muestreo que lo notó?
     *
     * El lote de estado no trae marcas de tiempo, así que un cambio detectado ahí ocurrió en algún
     * momento entre el ciclo anterior y éste. Cuando se detecta uno se le pregunta al aparato su
     * hora exacta (`shadow/properties`), que es barato porque los cambios son raros — pero si el
     * aparato está desconectado o no la da, se guarda la del muestreo y **se dice**.
     *
     * Un cuarto de hora de imprecisión no cambia nada operativamente. Fingir exactitud sí: es
     * exactamente el fallo del que este módulo está lleno de cicatrices.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['domotica_cambio:read'])]
    private bool $horaExacta = false;

    public function getDispositivo(): ?DomoticaDispositivo
    {
        return $this->dispositivo;
    }

    public function setDispositivo(?DomoticaDispositivo $dispositivo): self
    {
        $this->dispositivo = $dispositivo;

        return $this;
    }

    public function getSuscripcion(): ?DomoticaSuscripcion
    {
        return $this->suscripcion;
    }

    public function setSuscripcion(?DomoticaSuscripcion $suscripcion): self
    {
        $this->suscripcion = $suscripcion;

        return $this;
    }

    public function isEncendido(): bool
    {
        return $this->encendido;
    }

    public function setEncendido(bool $encendido): self
    {
        $this->encendido = $encendido;

        return $this;
    }

    public function getOcurridoEn(): DateTimeImmutable
    {
        return $this->ocurridoEn;
    }

    public function setOcurridoEn(DateTimeImmutable $ocurridoEn): self
    {
        $this->ocurridoEn = $ocurridoEn;

        return $this;
    }

    public function isHoraExacta(): bool
    {
        return $this->horaExacta;
    }

    public function setHoraExacta(bool $horaExacta): self
    {
        $this->horaExacta = $horaExacta;

        return $this;
    }

    /**
     * La línea que se le puede enseñar a alguien.
     *
     * Vive en la entidad por lo mismo que `DomoticaLectura::comoLinea()`: para que la app, el panel
     * y el asistente cuenten lo mismo. El «≈» no es decorativo — avisa de que la hora es la del
     * muestreo y no la del aparato.
     */
    public function comoLinea(): string
    {
        return sprintf(
            '%s%s · %s',
            $this->horaExacta ? '' : '≈',
            $this->ocurridoEn->format('d/m H:i'),
            $this->encendido ? 'encendido' : 'apagado'
        );
    }
}
