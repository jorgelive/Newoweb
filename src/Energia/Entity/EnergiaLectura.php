<?php

declare(strict_types=1);

namespace App\Energia\Entity;

use App\Energia\Enum\EnergiaMotivoLectura;
use App\Energia\Repository\EnergiaLecturaRepository;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Una fila por muestreo: la bitácora de consumo, y la fuente de verdad del módulo.
 *
 * Es lo que se le enseña al huésped cuando pregunta de dónde sale su cuenta:
 *
 * ```
 *   14:00   contador 546.000   tu consumo 0.000
 *   15:00   contador 549.000   tu consumo 3.000
 *   16:00   contador 551.500   tu consumo 5.500
 * ```
 *
 * Nada se edita y nada se borra. Un reinicio no rebobina la tabla: escribe una fila más, con el
 * mismo `consumoTotal` y `consumoCliente` en cero, marcada con su motivo y su nota. El salto en la
 * cuenta queda explicado dentro del propio historial, que es la única forma de que el huésped
 * pueda comprobarlo sin fiarse de nosotros.
 *
 * ## Las dos columnas no miden lo mismo
 *
 * `consumoTotal` es lo que marca el aparato: sube siempre, no se reinicia jamás, y es
 * verificable contra la app de Tuya. `consumoCliente` es lo que se le imputa a quien está
 * alojado: se reinicia al entrar y a solicitud. Tener las dos es lo que permite decir «el aparato
 * marca 551 y tú llevas 5,5» sin que ninguno de los dos números tenga que mentir.
 *
 * ## `incremento` está por redundancia deliberada
 *
 * Se puede deducir restando filas consecutivas, y aun así se guarda: es lo que Tuya devuelve
 * (`add_ele` es un incremento por hora, no un acumulado) y guardarlo tal cual deja comprobar más
 * tarde si un salto raro venía de la nube o lo introdujimos nosotros al acumular.
 */
#[ORM\Entity(repositoryClass: EnergiaLecturaRepository::class)]
#[ORM\Table(name: 'energia_lectura')]
// La consulta de siempre: «el historial de esta estancia, en orden». Sin esto, pintar el
// desglose obliga a recorrer la tabla entera, que crece 24 filas por aparato y día.
#[ORM\Index(name: 'idx_energia_lectura_suscripcion', columns: ['suscripcion_id', 'leida_en'])]
#[ORM\Index(name: 'idx_energia_lectura_dispositivo', columns: ['dispositivo_id', 'leida_en'])]
// Dos muestreos de la misma hora serían consumo contado dos veces. La nube de Tuya devuelve
// cubos horarios y un reintento del cron pediría el mismo: lo impide la base, no el buen criterio.
//
// Va contra el DISPOSITIVO y no contra la suscripción por dos razones: un aparato sólo puede tener
// una cuenta abierta a la vez —así que es igual de estricto—, y las filas huérfanas (aparato entre
// estancias) tienen `suscripcion_id` a NULL, que en MySQL no colisiona con nada y las dejaría
// duplicarse sin que nadie se entere.
#[ORM\UniqueConstraint(name: 'uniq_energia_lectura_hora', columns: ['dispositivo_id', 'cubo_horario'])]
#[ORM\HasLifecycleCallbacks]
class EnergiaLectura
{
    use IdTrait;
    use TimestampTrait;

    /**
     * La cuenta a la que pertenece. **Nullable**: un aparato desocupado se sigue muestreando
     * —el contador corre igual y conviene saber si alguien lo enciende entre estancias—, y esas
     * filas no son de nadie.
     */
    #[ORM\ManyToOne(targetEntity: EnergiaSuscripcion::class)]
    #[ORM\JoinColumn(name: 'suscripcion_id', nullable: true, onDelete: 'CASCADE')]
    #[Groups(['energia_lectura:read'])]
    private ?EnergiaSuscripcion $suscripcion = null;

    /** El aparato. Se guarda aunque la suscripción ya lo diga: las filas huérfanas también son suyas. */
    #[ORM\ManyToOne(targetEntity: EnergiaDispositivo::class)]
    #[ORM\JoinColumn(name: 'dispositivo_id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['energia_lectura:read'])]
    private ?EnergiaDispositivo $dispositivo = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['energia_lectura:read'])]
    private ?DateTimeImmutable $leidaEn = null;

    /**
     * La hora que pidió el cron, como `YYYYMMDDHH`.
     *
     * Es la clave contra la que se deduplica, y va en formato de Tuya a propósito: es literalmente
     * el `start_time` de la llamada, así que comparar lo pedido con lo guardado no exige traducir
     * nada. `null` en las filas que no vienen de un cubo horario —un reinicio, un ajuste—, que por
     * eso no colisionan entre sí.
     */
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    #[Groups(['energia_lectura:read'])]
    private ?string $cuboHorario = null;

    /** Lo que marcaba el contador del aparato. Nunca baja. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Groups(['energia_lectura:read'])]
    private string $consumoTotal = '0.000';

    /** Lo acumulado a cargo del huésped en ese instante. Vuelve a cero en los reinicios. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    #[Groups(['energia_lectura:read'])]
    private string $consumoCliente = '0.000';

    /** Los kW·h de este tramo: lo que devolvió Tuya para esa hora, sin tocar. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, options: ['default' => '0.000'])]
    #[Groups(['energia_lectura:read'])]
    private string $incremento = '0.000';

    #[ORM\Column(type: 'string', length: 20, enumType: EnergiaMotivoLectura::class)]
    #[Groups(['energia_lectura:read'])]
    private EnergiaMotivoLectura $motivo = EnergiaMotivoLectura::Poll;

    /** Por qué lo hizo una persona. Obligatoria de hecho en reinicios y ajustes; ver el enum. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['energia_lectura:read'])]
    private ?string $nota = null;

    /** Quién lo pidió, cuando no fue el cron. Sin esto, un reinicio no se le puede preguntar a nadie. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'registrada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $registradaPor = null;

    public function getSuscripcion(): ?EnergiaSuscripcion
    {
        return $this->suscripcion;
    }

    public function setSuscripcion(?EnergiaSuscripcion $suscripcion): self
    {
        $this->suscripcion = $suscripcion;

        return $this;
    }

    public function getDispositivo(): ?EnergiaDispositivo
    {
        return $this->dispositivo;
    }

    public function setDispositivo(?EnergiaDispositivo $dispositivo): self
    {
        $this->dispositivo = $dispositivo;

        return $this;
    }

    public function getLeidaEn(): ?DateTimeImmutable
    {
        return $this->leidaEn;
    }

    public function setLeidaEn(?DateTimeImmutable $leidaEn): self
    {
        $this->leidaEn = $leidaEn;

        return $this;
    }

    public function getCuboHorario(): ?string
    {
        return $this->cuboHorario;
    }

    public function setCuboHorario(?string $cuboHorario): self
    {
        $this->cuboHorario = $cuboHorario;

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

    public function getIncremento(): string
    {
        return $this->incremento;
    }

    public function setIncremento(string $incremento): self
    {
        $this->incremento = $incremento;

        return $this;
    }

    public function getMotivo(): EnergiaMotivoLectura
    {
        return $this->motivo;
    }

    public function setMotivo(EnergiaMotivoLectura $motivo): self
    {
        $this->motivo = $motivo;

        return $this;
    }

    public function getNota(): ?string
    {
        return $this->nota;
    }

    public function setNota(?string $nota): self
    {
        $this->nota = $nota;

        return $this;
    }

    public function getRegistradaPor(): ?User
    {
        return $this->registradaPor;
    }

    public function setRegistradaPor(?User $registradaPor): self
    {
        $this->registradaPor = $registradaPor;

        return $this;
    }

    /**
     * La línea tal como se le enseña al huésped.
     *
     * Vive en la entidad y no en la plantilla porque la app del huésped y el asistente tienen que
     * decir exactamente lo mismo: si uno redondea distinto que el otro, el que gana la discusión
     * es el huésped, y con razón.
     */
    public function comoLinea(): string
    {
        return sprintf(
            '%s · contador %s · tu consumo %s kW·h%s',
            $this->leidaEn?->format('H:i') ?? '—',
            number_format((float) $this->consumoTotal, 3),
            number_format((float) $this->consumoCliente, 3),
            $this->motivo === EnergiaMotivoLectura::Poll ? '' : ' (' . $this->motivo->etiqueta() . ')'
        );
    }
}
