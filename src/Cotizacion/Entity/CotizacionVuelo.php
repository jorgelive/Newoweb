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
 * ## Los segmentos van en JSON; los códigos NO
 *
 * Un vuelo puede ser un salto o dos: Copa hace `LIM → PTY → PUJ` bajo un mismo billete. Esos
 * segmentos **se leen siempre enteros y no se filtran**, así que una tabla propia sólo serviría
 * para volver a juntarlos en cada consulta. Van en JSON, como `detallesOperativos`.
 *
 * ⚠️ El vínculo con los localizadores es lo contrario: **tabla con clave foránea**. Un vuelo lo
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

    /**
     * El itinerario, entero. Uno o dos saltos.
     *
     * `salida` y `llegada` son fecha-hora completas a propósito: una bandera «llega al día
     * siguiente» junto a las dos fechas es el mismo hecho dos veces, y el día que alguien
     * corrija una y no la otra el dato se contradice sin que nada falle.
     *
     * @var list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}>
     */
    #[Groups(['file:item:read'])]
    #[ORM\Column(type: 'json')]
    private array $segmentos = [];

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

    public function getFecha(): ?\DateTimeImmutable { return $this->fecha; }
    public function setFecha(?\DateTimeImmutable $fecha): self { $this->fecha = $fecha; return $this; }

    public function getAerolinea(): ?string { return $this->aerolinea; }
    public function setAerolinea(?string $aerolinea): self { $this->aerolinea = $aerolinea; return $this; }

    /** @return list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}> */
    public function getSegmentos(): array { return $this->segmentos; }

    /** @param list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}> $segmentos */
    public function setSegmentos(array $segmentos): self { $this->segmentos = $segmentos; return $this; }

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
