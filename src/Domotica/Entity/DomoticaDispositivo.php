<?php

declare(strict_types=1);

namespace App\Domotica\Entity;

use App\Domotica\Repository\DomoticaDispositivoRepository;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Entity\PmsUnidad;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Un aparato inteligente colgado de la nube de Tuya.
 *
 * Es el dispositivo físico, con dos capacidades independientes: **medir** (`mideConsumo`) y
 * **conmutar** (`conmutable`). No todos hacen las dos cosas, y ninguna es obligatoria.
 *
 * ## La medición es UNA parte, no el todo
 *
 * El módulo nació para cobrar la calefacción por kW·h, pero lo que se registra aquí es el parque
 * de aparatos: los que miden alimentan `DomoticaSuscripcion` y `DomoticaLectura`; los que sólo
 * conmutan se registran igual, porque saber que algo quedó encendido en una casita vacía ya es
 * accionable por sí solo —es la otra mitad de la fricción que originó todo esto—.
 *
 * ⚠️ Por eso las consultas del muestreo y de la alerta filtran por `mideConsumo`. Un aparato sin
 * contómetro no está mudo: no da lectura porque no la tiene.
 *
 * El accionamiento (encender/apagar desde el sistema) **todavía no existe**: hoy el estado sólo se
 * lee. `conmutable` está para que el día que se accione no haya que adivinar cuáles aceptan la
 * orden. No sabe nada de reservas — eso lo pone `DomoticaSuscripcion`.
 *
 * ## Vive atado a una unidad, no a una reserva
 *
 * Los aparatos **no se mueven**: uno por dormitorio, y cada casita tiene su propio wifi, así que
 * cambiar uno de sitio obligaría a reconfigurarlo. Por eso la unidad es un dato del dispositivo y
 * no algo que se decida en cada estancia.
 *
 * ⚠️ Esto es una dependencia dura de `App\Pms` a propósito, y se sale de la pauta de Finanzas
 * —que evita la FK con `origenTipo`/`origenId`—. El motivo es que allí había un segundo consumidor
 * previsible (tours, ventas sueltas) y aquí no: un enchufe está clavado en un dormitorio de una
 * casita concreta, y quien sabe qué es un dormitorio es el PMS. Inventar una relación blanda para
 * un único dueño real sería complicarlo sin comprar nada.
 *
 * ## `lecturaActual` es caché, no verdad
 *
 * La verdad de lo consumido está en `DomoticaLectura`. Estas dos columnas son la foto del último
 * muestreo, para pintar el panel sin recorrer la bitácora. Si alguna vez discrepan, manda la
 * bitácora — ver `docs/Domotica.md` §3.
 */
#[ORM\Entity(repositoryClass: DomoticaDispositivoRepository::class)]
#[ORM\Table(name: 'domotica_dispositivo')]
// El id de Tuya es la clave natural: es con lo que llega cualquier respuesta de la nube, y dos
// filas con el mismo id harían que un muestreo se escribiera en la que tocara por azar.
#[ORM\UniqueConstraint(name: 'uniq_domotica_dispositivo_tuya', columns: ['tuya_device_id'])]
#[ORM\Index(name: 'idx_domotica_dispositivo_unidad', columns: ['unidad_id'])]
#[ORM\HasLifecycleCallbacks]
class DomoticaDispositivo
{
    use IdTrait;
    use TimestampTrait;

    /**
     * El identificador del aparato en la nube de Tuya (`eb4776d865f5b3351angr3`).
     *
     * No es un secreto —sin el par client_id/secret no sirve de nada—, pero sí es lo único que
     * permite volver a encontrar el aparato si alguien lo renombra en la app del móvil.
     */
    #[ORM\Column(type: 'string', length: 64)]
    #[Groups(['domotica_dispositivo:read'])]
    private string $tuyaDeviceId = '';

    /** Cómo lo llamamos nosotros: «Casa 3 · dormitorio 1». Es lo que ve el operador en el panel. */
    #[ORM\Column(type: 'string', length: 120)]
    #[Groups(['domotica_dispositivo:read'])]
    private string $nombre = '';

    /**
     * La habitación donde está enchufado, en texto.
     *
     * Suelto y no normalizado a propósito: hoy no hay entidad de dormitorio, y crear una para
     * escribir «dormitorio 1» sería inventar un modelo antes de necesitarlo.
     */
    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    #[Groups(['domotica_dispositivo:read'])]
    private ?string $ubicacion = null;

    /**
     * La casita. **Nullable**: un aparato recién dado de alta puede existir antes de que se
     * decida dónde va, y un aparato retirado conserva su historial sin seguir atado.
     */
    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(name: 'unidad_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['domotica_dispositivo:read'])]
    private ?PmsUnidad $unidad = null;

    /**
     * ¿Este aparato lleva contómetro?
     *
     * No todos los enchufes inteligentes miden: los hay que sólo conmutan, y son más baratos. Un
     * aparato sin contómetro se registra igual —interesa saber qué hay y en qué estado está— pero
     * NO se muestrea ni se le abre suscripción, y sobre todo NO cuenta como mudo cuando no da
     * lectura: no la da porque no la tiene, y avisar de eso cada tres horas quemaría el canal.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['domotica_dispositivo:read'])]
    private bool $mideConsumo = true;

    /**
     * ¿Se puede encender y apagar desde el sistema?
     *
     * Hoy sólo se LEE el estado. La columna existe para que el día que se accione de verdad no
     * haya que adivinar qué aparatos aceptan la orden — y para que el panel no ofrezca un botón
     * que no va a funcionar, que es peor que no ofrecerlo.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['domotica_dispositivo:read'])]
    private bool $conmutable = true;

    /**
     * Último estado leído: encendido o apagado. `null` mientras no se haya consultado nunca.
     *
     * Sirve por sí solo, sin contómetro de por medio: saber que un aparato quedó encendido en una
     * casita vacía ya es información accionable, y es la mitad de la fricción que originó todo
     * esto —el calefactor que se queda ardiendo a pleno sol—.
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['domotica_dispositivo:read'])]
    private ?bool $encendido = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['domotica_dispositivo:read'])]
    private ?DateTimeImmutable $estadoTomadoEn = null;

    /**
     * ¿Se le pide lectura al cron?
     *
     * Apagar esto NO borra nada: el aparato deja de muestrearse pero su bitácora sigue en pie.
     * Es la vía para retirar un enchufe averiado sin perder lo que se le cobró a nadie —la regla
     * de «no se borra: se marca» de CLAUDE.md.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['domotica_dispositivo:read'])]
    private bool $activo = true;

    /**
     * Última lectura del contador físico, en kW·h.
     *
     * Es el número que el huésped ve como «el contador marcaba X cuando entraste». Sube siempre
     * y no se reinicia jamás: los reinicios sólo tocan el consumo imputado en la suscripción.
     */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, nullable: true)]
    #[Groups(['domotica_dispositivo:read'])]
    private ?string $lecturaTotal = null;

    /** Cuándo se tomó `lecturaTotal`. Si se queda atrás, el cron dejó de correr: hay que avisar. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['domotica_dispositivo:read'])]
    private ?DateTimeImmutable $lecturaTomadaEn = null;

    /**
     * Potencia instantánea en vatios de la última consulta en vivo.
     *
     * No la escribe el cron horario sino la vista cuando alguien la tiene abierta: sirve para el
     * «ahora mismo está gastando», que es lo que convence de verdad al que dice que no lo usa.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['domotica_dispositivo:read'])]
    private ?int $potenciaVatios = null;

    /**
     * Muestreos seguidos que no trajeron nada.
     *
     * Se pone a cero en cuanto llega una lectura buena, así que es «cuántas veces seguidas ha
     * fallado», no un total histórico. De aquí sale la decisión de avisar: se avisa cada N fallos,
     * no cada fallo, porque un enchufe roto un fin de semana generaría sesenta avisos idénticos y
     * el aviso número sesenta ya no lo lee nadie.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['domotica_dispositivo:read'])]
    private int $fallosConsecutivos = 0;

    /** Cuándo se avisó por última vez. Sólo informativo: quien decide es el contador. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['domotica_dispositivo:read'])]
    private ?DateTimeImmutable $ultimaAlertaEn = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notas = null;

    public function getTuyaDeviceId(): string
    {
        return $this->tuyaDeviceId;
    }

    public function setTuyaDeviceId(string $tuyaDeviceId): self
    {
        $this->tuyaDeviceId = trim($tuyaDeviceId);

        return $this;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getUbicacion(): ?string
    {
        return $this->ubicacion;
    }

    public function setUbicacion(?string $ubicacion): self
    {
        $this->ubicacion = $ubicacion;

        return $this;
    }

    public function getUnidad(): ?PmsUnidad
    {
        return $this->unidad;
    }

    public function setUnidad(?PmsUnidad $unidad): self
    {
        $this->unidad = $unidad;

        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;

        return $this;
    }

    public function getLecturaTotal(): ?string
    {
        return $this->lecturaTotal;
    }

    public function setLecturaTotal(?string $lecturaTotal): self
    {
        $this->lecturaTotal = $lecturaTotal;

        return $this;
    }

    public function getLecturaTomadaEn(): ?DateTimeImmutable
    {
        return $this->lecturaTomadaEn;
    }

    public function setLecturaTomadaEn(?DateTimeImmutable $lecturaTomadaEn): self
    {
        $this->lecturaTomadaEn = $lecturaTomadaEn;

        return $this;
    }

    public function getPotenciaVatios(): ?int
    {
        return $this->potenciaVatios;
    }

    public function setPotenciaVatios(?int $potenciaVatios): self
    {
        $this->potenciaVatios = $potenciaVatios;

        return $this;
    }

    public function getNotas(): ?string
    {
        return $this->notas;
    }

    public function setNotas(?string $notas): self
    {
        $this->notas = $notas;

        return $this;
    }

    public function mideConsumo(): bool
    {
        return $this->mideConsumo;
    }

    public function setMideConsumo(bool $mideConsumo): self
    {
        $this->mideConsumo = $mideConsumo;

        return $this;
    }

    public function isConmutable(): bool
    {
        return $this->conmutable;
    }

    public function setConmutable(bool $conmutable): self
    {
        $this->conmutable = $conmutable;

        return $this;
    }

    public function getEncendido(): ?bool
    {
        return $this->encendido;
    }

    public function getEstadoTomadoEn(): ?DateTimeImmutable
    {
        return $this->estadoTomadoEn;
    }

    /**
     * Anota el estado leído del aparato.
     *
     * Separado de `registrarLectura()` a propósito: el estado se puede leer de un aparato que no
     * mide, y se lee por otra vía —el `switch_1` del estado del dispositivo, no la API de
     * energía—. Mezclarlos obligaría a fingir un consumo para poder guardar un on/off.
     */
    public function registrarEstado(DateTimeImmutable $momento, ?bool $encendido): self
    {
        $this->encendido = $encendido;
        $this->estadoTomadoEn = $momento;

        return $this;
    }

    public function getFallosConsecutivos(): int
    {
        return $this->fallosConsecutivos;
    }

    public function getUltimaAlertaEn(): ?DateTimeImmutable
    {
        return $this->ultimaAlertaEn;
    }

    public function setUltimaAlertaEn(?DateTimeImmutable $ultimaAlertaEn): self
    {
        $this->ultimaAlertaEn = $ultimaAlertaEn;

        return $this;
    }

    /**
     * El muestreo trajo dato: se olvida lo anterior.
     *
     * Sin este cero, un aparato que se recupera seguiría arrastrando el contador y volvería a
     * avisar al siguiente tropiezo suelto, cuando en realidad está bien.
     */
    public function registrarLectura(DateTimeImmutable $momento, string $lecturaTotal): self
    {
        $this->lecturaTotal = $lecturaTotal;
        $this->lecturaTomadaEn = $momento;
        $this->fallosConsecutivos = 0;

        return $this;
    }

    /** El muestreo no trajo nada. Devuelve el número de fallos seguidos que lleva ya. */
    public function registrarFallo(): int
    {
        return ++$this->fallosConsecutivos;
    }

    /**
     * ¿Toca avisar en este fallo?
     *
     * El módulo es lo que hace que un aparato muerto avise cada N fallos y no cada hora: con
     * `$cada = 3` salta en el 3, el 6, el 9… Repite —porque un enchufe roto sigue estándolo y
     * conviene que moleste— pero espaciado, que es la diferencia entre un aviso y un ruido de
     * fondo que se aprende a ignorar.
     *
     * Ojo: el primer aviso llega al fallo N, no al primero. Un muestreo suelto que se pierde por
     * un wifi con hipo no es nada, y avisar de eso quemaría el canal para cuando importe.
     */
    public function debeAlertar(int $cada): bool
    {
        if ($cada < 1) {
            return false;
        }

        return $this->fallosConsecutivos > 0 && $this->fallosConsecutivos % $cada === 0;
    }

    /**
     * ¿Hace demasiado que no da señales?
     *
     * El cron corre cada hora; con más de dos vencidas hay algo roto —el aparato desenchufado, el
     * wifi de la casita caído o la cuota de Tuya agotada— y hay que enterarse ANTES de que llegue
     * la hora de cobrarle a alguien un consumo que no se midió.
     */
    public function lecturaVencida(int $horas = 2): bool
    {
        // Un aparato que no mide no puede estar mudo: no da lectura porque no la tiene.
        if (!$this->activo || !$this->mideConsumo) {
            return false;
        }

        if ($this->lecturaTomadaEn === null) {
            return true;
        }

        return $this->lecturaTomadaEn < new DateTimeImmutable(sprintf('-%d hours', $horas));
    }

    public function __toString(): string
    {
        return $this->nombre !== '' ? $this->nombre : $this->tuyaDeviceId;
    }
}
