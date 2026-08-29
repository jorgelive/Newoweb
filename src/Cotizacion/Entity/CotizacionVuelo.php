<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un vuelo real de un expediente: el JA7013 del 23 de septiembre, con sus horarios.
 *
 * ## Por qué existe: los horarios cambian, y el texto no se puede consultar
 *
 * Hasta ahora un vuelo era una línea dentro de `CotizacionFileGrupo::$detalle`:
 *
 *     * Retorno JA7013 · LIM 23/09 06:30 → CUZ 23/09 07:55
 *
 * Con eso, comprobar si una aerolínea movió un horario es **comparar cadenas**, y «¿a quién
 * afecta este cambio?» no se puede preguntar. Pasó de verdad el 28/08/2026: JetSMART adelantó
 * el JA7013 veinte minutos y hubo que cotejar 16 segmentos a mano para encontrar el único que
 * había cambiado.
 *
 * ## La identidad es `(numero, fecha)`, y la fecha no sobra
 *
 * Medido sobre el itinerario real: 14 claves para 14 vuelos. **El número solo no basta** —el
 * `JA7027` vuela el 25 y el 27 de septiembre—, y de ahí que la unicidad lleve la fecha.
 *
 * ## ⚠️ Un vuelo es UN TRAMO, no un billete
 *
 * Al principio guardé Copa como un vuelo «CM264 / CM177» con dos segmentos en JSON, y estaba
 * mal: eso no es un vuelo, es un **billete**. `CM264` va de Lima a Panamá y `CM177` de Panamá a
 * Punta Cana; son dos vuelos, cada uno con su número, su avión y su horario.
 *
 * Lo que los une —que se compraron juntos y hay que llegar a la conexión— **es el PNR**, y eso
 * ya está en el puente. No hacía falta inventar un nivel intermedio.
 *
 * Por eso no hay `segmentos`: el origen, el destino y las horas son columnas, y así se puede
 * preguntar «¿qué sale de LIM el 23?», que con un JSON no se podía.
 *
 * ## Los códigos van a tabla, no a JSON
 *
 * El vínculo con los localizadores es **tabla con clave foránea**. Un vuelo lo
 * comparten varios códigos —ocho comparten el `DM6771`— y un código lleva ida y vuelta, así que
 * es N:M. Guardarlo como un array de cadenas dentro del JSON dejaría que un localizador mal
 * tecleado casara con cero grupos **sin dar error**, que es justo la familia de fallo que este
 * modelo viene a cerrar.
 *
 * La regla, por si vuelve a plantearse: *va a JSON lo que se lee entero y no se filtra; va a
 * tabla lo que el motor tiene que garantizar.*
 *
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_vuelo')]
// Un mismo número puede repetirse en otro expediente: son reservas distintas, negociadas aparte.
#[ORM\UniqueConstraint(name: 'uniq_vuelo_file_numero_fecha', columns: ['file_id', 'numero', 'fecha'])]
#[ORM\Index(name: 'idx_vuelo_fecha', columns: ['fecha'])]
#[ORM\HasLifecycleCallbacks]
class CotizacionVuelo
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionFile::class)]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    /** Tal como lo escribe la aerolínea. Copa manda los dos: «CM264 / CM177». */
    #[Assert\NotBlank(message: 'El vuelo necesita su número.')]
    #[Groups(['file:item:read'])]
    #[ORM\Column(type: 'string', length: 40)]
    private ?string $numero = null;

    /** La de SALIDA del primer segmento: es la mitad de la identidad del vuelo. */
    #[Assert\NotNull(message: 'El vuelo necesita su fecha de salida.')]
    #[Groups(['file:item:read'])]
    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $fecha = null;

    /**
     * Texto, no relación, **por ahora**.
     *
     * Lo suyo es que una aerolínea sea una `TravelOrganizacion`, como el resto de prestadores.
     * Hoy no existe ninguna —ni Sky ni JetSMART ni Arajet ni Copa—, así que exigir la ficha
     * bloquearía la carga. Cuando estén, esto pasa a `ManyToOne` y el nombre se queda de
     * respaldo histórico, igual que en el resto del sistema.
     */
    #[Groups(['file:item:read'])]
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $aerolinea = null;

    #[Groups(['file:item:read'])]
    #[ORM\Column(type: 'string', length: 3)]
    private ?string $origen = null;

    #[Groups(['file:item:read'])]
    #[ORM\Column(type: 'string', length: 3)]
    private ?string $destino = null;

    /**
     * Fecha-hora completas, no fecha + hora por separado.
     *
     * ⚠️ Una bandera «llega al día siguiente» junto a las dos fechas es el mismo hecho dos
     * veces: basta que alguien corrija una para que se contradigan. El `DM6770` sale el 22 y
     * llega el 23, y eso se lee solo.
     */
    #[Groups(['file:item:read'])]
    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $salida = null;

    #[Groups(['file:item:read'])]
    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $llegada = null;

    /**
     * De dónde salió el dato: «actualizado por JetSMART el 28/08», «pendiente de confirmar».
     *
     * @var list<string>
     */
    #[Groups(['file:item:read'])]
    #[ORM\Column(type: 'json')]
    private array $notas = [];

    /**
     * Los localizadores que viajan en este vuelo.
     *
     * @var Collection<int, CotizacionFileGrupo>
     */
    #[ORM\ManyToMany(targetEntity: CotizacionFileGrupo::class)]
    #[ORM\JoinTable(name: 'cotizacion_grupo_vuelo')]
    #[ORM\JoinColumn(name: 'vuelo_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'grupo_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $grupos;

    public function __construct()
    {
        $this->initializeId();
        $this->grupos = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s · %s', $this->numero ?? '?', $this->fecha?->format('d/m/Y') ?? '?');
    }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    public function getNumero(): ?string { return $this->numero; }
    public function setNumero(?string $numero): self { $this->numero = $numero; return $this; }

    /** Se deriva de `salida`; ver {@see setSalida()}. */
    public function getFecha(): ?\DateTimeImmutable { return $this->fecha; }

    public function getAerolinea(): ?string { return $this->aerolinea; }
    public function setAerolinea(?string $aerolinea): self { $this->aerolinea = $aerolinea; return $this; }

    public function getOrigen(): ?string { return $this->origen; }
    public function setOrigen(?string $origen): self { $this->origen = $origen; return $this; }

    public function getDestino(): ?string { return $this->destino; }
    public function setDestino(?string $destino): self { $this->destino = $destino; return $this; }

    public function getSalida(): ?\DateTimeImmutable { return $this->salida; }

    /**
     * ⚠️ Fija también `fecha`, que es la mitad de la identidad del vuelo.
     *
     * Guardar la fecha aparte y dejar que alguien la ponga a mano sería la misma redundancia
     * que se le quitó al archivo: dos campos para un hecho acaban discrepando. Aquí sólo hay
     * una forma de escribirla.
     */
    public function setSalida(?\DateTimeImmutable $salida): self
    {
        $this->salida = $salida;
        $this->fecha = $salida?->setTime(0, 0);

        return $this;
    }

    public function getLlegada(): ?\DateTimeImmutable { return $this->llegada; }
    public function setLlegada(?\DateTimeImmutable $llegada): self { $this->llegada = $llegada; return $this; }

    /** @return list<string> */
    public function getNotas(): array { return $this->notas; }

    /** @param list<string> $notas */
    public function setNotas(array $notas): self { $this->notas = $notas; return $this; }

    /** @return Collection<int, CotizacionFileGrupo> */
    public function getGrupos(): Collection { return $this->grupos; }

    public function addGrupo(CotizacionFileGrupo $grupo): self
    {
        if (!$this->grupos->contains($grupo)) {
            $this->grupos[] = $grupo;
        }

        return $this;
    }

    public function removeGrupo(CotizacionFileGrupo $grupo): self
    {
        $this->grupos->removeElement($grupo);

        return $this;
    }
}
