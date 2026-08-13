<?php

declare(strict_types=1);

namespace App\Agent\Entity;

use App\Agent\Conversation\PerfilConversacion;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Una respuesta corta a algo que preguntan todo el rato.
 *
 * ── Qué problema resuelve ───────────────────────────────────────────────────
 * Hoy, cuando ninguna skill encaja, el agente escala al equipo. Y buena parte de lo que escala
 * son preguntas repetidas cuya respuesta no cambia nunca —«¿hay estacionamiento?», «¿a qué hora
 * abre la puerta?», «¿aceptan Yape?»—. Cada una de ésas es una persona interrumpida para decir
 * lo mismo otra vez.
 *
 * Esto es **la última red antes del escalado por desconocimiento**: si aquí hay respuesta, se
 * contesta; si no, entonces sí, que lo vea una persona. No sustituye a ninguna skill —lo que se
 * calcula o se consulta en la base sigue siendo cosa de ellas— y no es la guía del huésped, que
 * vive en `PmsGuiaItem` y es del alojamiento: esto es transversal y sirve a cualquier
 * interlocutor.
 *
 * ── Se consulta en DOS fases, y por eso hay categoría y etiquetas ───────────
 * La tabla está pensada para crecer mucho: se alimenta con el tiempo, cada vez que alguien nota
 * que una pregunta se repite. Mandársela entera al modelo en cada turno sería insostenible.
 *
 * ```
 *   fase 1   se le enseñan las CATEGORÍAS (unas líneas) y elige de qué va la pregunta
 *   fase 2   se le enseñan las ETIQUETAS de esa categoría y elige el ítem
 *   → se devuelve el CONTENIDO, que es lo único que se le dice al que pregunta
 * ```
 *
 * ── Vacío = sin acotar, igual que en el resto del agente ────────────────────
 * ⚠️ `dominios` y `perfiles` vacíos significan **«vale para todos»**, no «para ninguno». Es la
 * misma convención que {@see \App\Agent\Skill\SkillDominioInterface} y que
 * `ActorInterface::dominios()`, y por el mismo motivo: un olvido al clasificar deja el ítem de
 * más —inofensivo, se ve alguna vez de sobra— en vez de dejarlo invisible sin que nada lo
 * delate. Lo segundo no se descubre nunca.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_conocimiento')]
#[ORM\Index(columns: ['categoria_id'], name: 'idx_conocimiento_categoria')]
#[ORM\HasLifecycleCallbacks]
class AgentConocimiento
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: AgentConocimientoCategoria::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'categoria_id', referencedColumnName: 'id', nullable: false)]
    private ?AgentConocimientoCategoria $categoria = null;

    /**
     * Cómo se llama esto para quien lo administra. No se le enseña al modelo.
     *
     * Existe porque en una tabla de cientos de filas hay que poder encontrar la que se quiere
     * corregir, y buscar por el cuerpo del texto es incómodo.
     */
    #[ORM\Column(type: 'string', length: 160)]
    private string $nombreInterno = '';

    /**
     * Las palabras con las que el modelo lo reconoce en la segunda fase: «estacionamiento,
     * parking, dónde dejo el auto, cochera».
     *
     * Se escriben pensando en **cómo pregunta la gente**, no en cómo lo llamamos nosotros. Es lo
     * único de este ítem que viaja en el prompt junto al id, así que cuanto más pegado esté al
     * lenguaje real del huésped, menos vueltas hace falta dar.
     */
    #[ORM\Column(type: 'text')]
    private string $etiquetas = '';

    /**
     * Lo que se dirá. Corto y ya redactado: esto no es documentación interna, es la respuesta.
     *
     * El modelo lo adapta al idioma y al tono de quien pregunta, pero no debería tener que
     * inventar nada — si hace falta inventar, es que el contenido está incompleto.
     */
    #[ORM\Column(type: 'text')]
    private string $contenido = '';

    /**
     * A qué negocios aplica: `hotelero`, `turistico`. **Vacío = a todos.**
     *
     * Un mismo ítem puede pertenecer a más de uno —el horario de la oficina o los medios de pago
     * valen para las dos cosas— y por eso es una lista y no una columna.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dominios = [];

    /**
     * A qué interlocutores se les puede contar: los valores de {@see PerfilConversacion}.
     * **Vacío = a todos.**
     *
     * Es lo que evita que una respuesta pensada para el equipo —un procedimiento interno, un
     * código— acabe leyéndosele a un huésped.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $perfiles = [];

    /**
     * Aunque haya respuesta, esto lo tiene que ver una persona.
     *
     * Para lo que se puede explicar pero no resolver: «sí, se puede cambiar la fecha; te paso con
     * el equipo para verlo». Contesta y escala, en vez de escoger entre las dos.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requiereHumano = false;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    public function __construct()
    {
        $this->initializeId();
    }

    public function __toString(): string
    {
        return $this->nombreInterno;
    }

    /**
     * La línea que ve el modelo en la segunda fase: su id y por qué palabras se reconoce.
     *
     * ⚠️ **Sin el contenido.** Mandarlo aquí sería mandar la tabla entera y perder el sentido de
     * las dos fases; el cuerpo se devuelve sólo cuando el modelo ha elegido este ítem.
     */
    public function comoLinea(): string
    {
        return sprintf('- %s · %s', $this->getId(), trim($this->etiquetas));
    }

    /**
     * ¿Se le puede contar esto a este actor?
     *
     * Las dos listas vacías dicen «sin acotar». Ver el docblock de la clase: es la convención del
     * resto del agente, y el fallo seguro es el permisivo.
     *
     * @param list<string> $dominiosDelActor
     */
    public function visiblePara(array $dominiosDelActor, PerfilConversacion $perfil): bool
    {
        if (!$this->activo) {
            return false;
        }

        if (($this->dominios ?? []) !== [] && $dominiosDelActor !== []
            && array_intersect($this->dominios, $dominiosDelActor) === []
        ) {
            return false;
        }

        return ($this->perfiles ?? []) === [] || in_array($perfil->value, $this->perfiles, true);
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getCategoria(): ?AgentConocimientoCategoria { return $this->categoria; }
    public function setCategoria(?AgentConocimientoCategoria $categoria): self { $this->categoria = $categoria; return $this; }

    public function getNombreInterno(): string { return $this->nombreInterno; }
    public function setNombreInterno(string $nombre): self { $this->nombreInterno = $nombre; return $this; }

    public function getEtiquetas(): string { return $this->etiquetas; }
    public function setEtiquetas(string $etiquetas): self { $this->etiquetas = $etiquetas; return $this; }

    public function getContenido(): string { return $this->contenido; }
    public function setContenido(string $contenido): self { $this->contenido = $contenido; return $this; }

    /** @return list<string> */
    public function getDominios(): array { return $this->dominios ?? []; }
    /** @param list<string> $dominios */
    public function setDominios(array $dominios): self { $this->dominios = array_values($dominios); return $this; }

    /** @return list<string> */
    public function getPerfiles(): array { return $this->perfiles ?? []; }
    /** @param list<string> $perfiles */
    public function setPerfiles(array $perfiles): self { $this->perfiles = array_values($perfiles); return $this; }

    public function isRequiereHumano(): bool { return $this->requiereHumano; }
    public function setRequiereHumano(bool $v): self { $this->requiereHumano = $v; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }
}
