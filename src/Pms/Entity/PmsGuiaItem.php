<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Message\Contract\CategoriaConocimiento;
use App\Pms\Enum\PmsGuiaVisibilidad;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_item')]
#[ORM\HasLifecycleCallbacks]
class PmsGuiaItem
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    public const TIPO_TARJETA = 'card';
    public const TIPO_ALBUM = 'album';
    public const TIPO_AVISO = 'alert';

    /** @var Collection<int, PmsGuiaSeccionHasItem> */
    #[ORM\OneToMany(mappedBy: 'item', targetEntity: PmsGuiaSeccionHasItem::class, cascade: ['persist', 'remove'])]
    private Collection $itemHasSecciones;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'El nombre interno es obligatorio')]
    private ?string $nombreInterno = null;

    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::TIPO_TARJETA, self::TIPO_ALBUM, self::TIPO_AVISO])]
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    private ?string $tipo = self::TIPO_TARJETA;

    /**
     * Quién puede ver este ítem, en cuatro niveles crecientes (ver
     * PmsGuiaVisibilidad y la matriz de PmsGuiaAcceso::permite()).
     *
     * El default es CLIENTE a propósito: es el comportamiento conservador para
     * todo el contenido que existía antes de este campo (la migración lo aplica
     * en bloque). Sacar algo al escaparate es una decisión editorial explícita,
     * nunca un efecto secundario.
     *
     * Vive en el ítem y NO en la sección: PmsGuiaSeccion deriva su visibilidad
     * de los ítems que le quedan tras filtrar (ver PmsGuiaArbolFiltro). Con el
     * flag en los dos sitios habría dos campos capaces de contradecirse y
     * secciones vacías en el catálogo.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: PmsGuiaVisibilidad::class, options: ['default' => 'cliente'])]
    #[Assert\NotNull]
    private PmsGuiaVisibilidad $visibilidad = PmsGuiaVisibilidad::Cliente;

    /**
     * DE QUÉ trata el ítem, para poder restarlo según el canal por el que se responde.
     *
     * Es un eje distinto de `visibilidad` y no lo sustituye. Aquélla dice CUÁNTA confianza
     * hace falta —la escalera público → cliente → confirmado → ventana—; ésta dice DE QUÉ
     * HABLA. Son independientes: la dirección del alojamiento y el número de camas están las
     * dos en el escalón `Publico`, y sin embargo por una OTA sin confirmar una se puede dar y
     * la otra no.
     *
     * Sin este campo, la única forma de tapar la dirección ante el partner era subirla de
     * escalón —y eso la habría escondido también en el catálogo público de la web, que es
     * justo donde debe estar—.
     *
     * El default es `General`, es decir «siempre se puede contar». Al revés dejaría mudo al
     * agente en cuanto alguien olvidara clasificar un ítem, y lo que se restringe es la
     * excepción, no la norma.
     */
    #[ORM\Column(
        name: 'categoria',
        type: 'string',
        length: 30,
        enumType: CategoriaConocimiento::class,
        options: ['default' => 'general']
    )]
    #[Assert\NotNull]
    private CategoriaConocimiento $categoria = CategoriaConocimiento::General;

    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull(message: 'Debe ingresar al menos el título en español')]
    #[Assert\Count(min: 1, minMessage: 'Debe ingresar al menos un título')]
    private array $titulo = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    private ?array $descripcion = [];

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'El icono no puede exceder los 50 caracteres')]
    private ?string $icono = null;

    /**
     * Con qué palabras PREGUNTA la gente por esto. Para que el agente lo encuentre.
     *
     * El título está escrito para leerse en una pantalla, no para buscarse: el ítem se llama
     * «Uso de la ducha» y el huésped escribe «no sale agua caliente», «el calentador no
     * enciende» o «hot water». Ninguna de las tres casa por texto con el título ni,
     * necesariamente, con el cuerpo — y el agente respondía que la guía no dice nada del tema
     * teniéndolo escrito.
     *
     * Mismo problema que tenían las plantillas antes de `MessageTemplate::$agenteUso`, con una
     * diferencia: allí la frase sirve para ELEGIR entre once, y aquí para ENCONTRAR entre
     * treinta. Por eso son términos sueltos y no una explicación.
     *
     * Formato: separados por comas, en los idiomas en que se pregunte, sin miedo a repetir.
     * `ducha, agua caliente, calentador, gas, temperatura, shower, hot water`
     *
     * Vacío no rompe nada: se sigue buscando en título y cuerpo. Sólo se encuentra peor.
     */
    #[ORM\Column(name: 'agente_terminos', type: 'text', nullable: true)]
    private ?string $agenteTerminos = null;

    /**
     * Sobre este tema el asistente **informa pero no concede**: hay que avisar a una persona.
     *
     * Nace de un caso que salía al revés de lo esperado. «Horario de ingreso y salida» explica
     * que la salida tardía está «sujeta a disponibilidad, con coste y coordinándolo con un día
     * de antelación». Con eso en la mano, el modelo se siente capaz de cerrar el tema solo — y
     * cerrarlo solo significa que el huésped cree que puede quedarse sin que nadie haya mirado
     * si esa noche está vendida. **Cuanta más información tiene el agente, más fácil es que no
     * escale**, y aquí es justo cuando más falta hace.
     *
     * Marcarlo saca la orden del prompt y la mete en el DATO: `consultar_guia` la devuelve junto
     * al contenido, así que el modelo la recibe pegada al texto que acaba de leer en vez de
     * tener que acordarse de una regla general. Y lo decide quien edita la guía, sin tocar
     * código.
     *
     * No es lo mismo que «no sé responder»: aquí sí sabe, y precisamente por eso responde y
     * además avisa. Lo que no puede es decidir.
     */
    #[ORM\Column(name: 'agente_requiere_humano', type: 'boolean', options: ['default' => false])]
    private bool $agenteRequiereHumano = false;

    /**
     * Lo que el asistente cuenta de este tema, cuando NO es lo mismo que se publica.
     *
     * Con contenido aquí, `consultar_guia` devuelve **esto en lugar del cuerpo**. La app del
     * huésped no se entera: sigue pintando la descripción de siempre.
     *
     * ### Por qué hace falta poder decir cosas distintas
     *
     * No es censura ni un atajo: es que **leer y que te lo digan no son lo mismo**. El ítem de
     * pagos explica que en las reservas de Booking se pide un depósito de garantía de S/ 300 al
     * check-in. Escrito en la guía está bien —el huésped lo lee cuando toca, junto a la
     * explicación de que se devuelve entero—; soltado por el chat a alguien que sólo preguntó
     * «¿el pago no es por Booking?» suena a peaje sorpresa y espanta.
     *
     * Sirve también para lo contrario: **contarle al agente lo que no cabe en la guía**. Las
     * preguntas recurrentes que no son contenido publicable —por qué el importe de la app de
     * Booking no cuadra con el nuestro, por ejemplo— tienen aquí su sitio, y así el modelo deja
     * de improvisar una explicación verosímil cada vez.
     *
     * ### Se escribe para el modelo, no para el huésped
     *
     * Texto plano y en español, como {@see \App\Message\Entity\MessageTemplate::$agenteUso}: no
     * lleva HTML —el chat no lo pinta— y no se traduce, porque el modelo lo redacta en el idioma
     * del huésped. Un diccionario más a cambio de nada.
     *
     * ⚠️ **Sustituye, no acompaña.** Si aquí escribes media explicación, media explicación es
     * todo lo que el agente sabrá del tema: lo que quede fuera no lo va a completar leyendo el
     * cuerpo, porque no lo recibe.
     */
    #[ORM\Column(name: 'agente_contenido', type: 'text', nullable: true)]
    private ?string $agenteContenido = null;

    /**
     * Lo que se dice **sólo si lo primero no resolvió**: un paso por peldaño, en orden.
     *
     * ### Por qué el tema no cabe en un solo texto
     *
     * Al que pregunta cómo funciona la ducha no se le contesta con avisos de avería, ni al que
     * pregunta cómo se paga se le suelta que hay un depósito de S/ 300 y que si los números no le
     * cuadran avisará al equipo. **Es anticomercial y genera mal ambiente**: le estamos
     * respondiendo a un problema que no tiene. Pero esa información hace falta —cuando de verdad
     * hay avería, o cuando el huésped dice que él ve otra cifra—, así que el contenido no sobra:
     * sobra *en el primer mensaje*.
     *
     * Hasta ahora todo iba junto en {@see $agenteContenido} con la escalera escrita en prosa
     * («si insiste, no repitas las instrucciones: avisa al equipo»), es decir, **pidiéndole al
     * modelo que se autocensure la mitad de lo que acaba de leer**. Eso ya falló otras veces en
     * este proyecto — con los teléfonos del personal de limpieza, una instrucción en mayúsculas
     * se ignoró tres veces. Lo que no debe salir todavía, no se manda todavía.
     *
     * ### Cómo se recorre
     *
     * ```
     *   paso 0   agenteContenido        uso normal, lo que se contesta a una pregunta blanca
     *   paso 1   pasos[0]               no le funcionó: lo que puede probar él
     *   paso 2   pasos[1]               sigue sin ir: la comprobación que decide qué mandamos
     *   → agotados los pasos, escala, con el rastro de lo ya intentado en el aviso
     * ```
     *
     * Es lo que hace una persona al responder: prueba esto, prueba lo otro, y si nada funciona
     * mandamos al técnico. Dos peldaños bastan; si hacen falta más, el tema está mal partido.
     *
     * ⚠️ **Vacío es lo normal.** La mayoría de los temas se contestan de una vez y no quieren
     * escalera: sin pasos, el ítem se comporta exactamente como antes de que esto existiera. Se
     * llena sólo donde el tema tiene de verdad un «y si no…» (ducha, pagos, horarios de entrada
     * y salida).
     *
     * ⚠️ **No es el sitio de lo que sólo se dice si lo preguntan.** El depósito de garantía no es
     * un peldaño: no depende de que insista, sino de que pregunte por él. Eso se resuelve
     * partiéndolo en su propio ítem, para que el índice de temas lo elija cuando toca y el resto
     * del tiempo ni exista.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'agente_pasos', type: 'json', nullable: true)]
    private ?array $agentePasos = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private ?array $labelBoton = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = [];

    /** @var Collection<int, PmsGuiaItemGaleria> */
    #[ORM\OneToMany(mappedBy: 'item', targetEntity: PmsGuiaItemGaleria::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    #[Assert\Valid]
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    private Collection $galeria;

    // ══════════════════════════════════════════════════════════════════════
    // PROPIEDADES VIRTUALES DE LA VISTA PÚBLICA (no persistidas)
    // Las llena PmsGuiaArbolFiltro; la entity no calcula ni consulta nada.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Título y cuerpo con los placeholders `{{ door_code }}` YA resueltos, en
     * todos los idiomas. Antes esa sustitución la hacía el navegador
     * (RichContentEngine.interpolateString), lo que obligaba a mandarle al
     * cliente el diccionario entero de valores sensibles para que eligiera
     * cuál pintar. Ahora el valor real solo sale del servidor si el acceso lo
     * permite; si no, en su lugar viaja el mensaje de bloqueo traducido.
     *
     * Se conserva la forma i18n `[{language, content}]` en vez de resolver el
     * idioma aquí a propósito: el selector de idioma de la guía cambia el
     * texto en caliente y no debe disparar una petición nueva.
     *
     * @var array<int, array{language: string, content: string}>
     */
    private array $tituloParaCliente = [];

    /** @var array<int, array{language: string, content: string}> */
    private array $contenidoParaCliente = [];

    /**
     * Momento en que este ítem deja de estar bloqueado, o null si no hay fecha
     * que prometer. Solo se rellena en estado `Pendiente`: es lo que permite
     * pintar "[Disponible el 12/08 a las 15:00]".
     *
     * OJO: null NO significa "visible". Un ítem bloqueado por falta de pago
     * (`SinPago`) o por estancia terminada (`Expirada`) se anuncia igual, pero
     * sin fecha. Para saber si está bloqueado está `$bloqueado`.
     */
    private ?\DateTimeImmutable $bloqueadoHasta = null;

    /**
     * Si el ítem viaja anunciado pero con el contenido sustituido por el
     * mensaje de bloqueo.
     *
     * Es un campo propio y no `null !== $bloqueadoHasta` (como era antes)
     * justamente por los estados sin fecha: ahí derivarlo de ella devolvía
     * `false` y el candado no se pintaba, dejando un ítem con pinta de normal
     * y un "[Disponible al confirmar]" por todo cuerpo.
     */
    private bool $bloqueado = false;

    public function __construct()
    {
        $this->galeria = new ArrayCollection();
        $this->itemHasSecciones = new ArrayCollection();
        $this->id = Uuid::v7();
        $this->titulo = [];
        $this->descripcion = [];
        $this->labelBoton = [];
        $this->tipo = self::TIPO_TARJETA;
        $this->nombreInterno = '';
        $this->metadata = [];
    }

    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    public function getUrlBoton(): ?string { return $this->metadata['urlBoton'] ?? null; }
    public function setUrlBoton(?string $val): self {
        if ($this->metadata === null) $this->metadata = [];
        if (empty($val)) unset($this->metadata['urlBoton']);
        else $this->metadata['urlBoton'] = $val;
        return $this;
    }

    public function getNombreInterno(): ?string { return $this->nombreInterno; }
    public function setNombreInterno(string $nombreInterno): self { $this->nombreInterno = $nombreInterno; return $this; }

    public function getTipo(): string { return $this->tipo ?? self::TIPO_TARJETA; }
    public function setTipo(?string $tipo): self { $this->tipo = $tipo; return $this; }

    public function getVisibilidad(): PmsGuiaVisibilidad { return $this->visibilidad; }

    public function setVisibilidad(PmsGuiaVisibilidad|string|null $visibilidad): self
    {
        $this->visibilidad = is_string($visibilidad)
            ? (PmsGuiaVisibilidad::tryFrom($visibilidad) ?? PmsGuiaVisibilidad::Cliente)
            : ($visibilidad ?? PmsGuiaVisibilidad::Cliente);

        return $this;
    }

    /**
     * Expuesto al cliente para que la UI pueda pintar el candado sin recalcular
     * la regla: si el ítem llega bloqueado, ya viene con `bloqueadoHasta`.
     */
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    #[SerializedName('visibilidad')]
    public function getVisibilidadApi(): string { return $this->visibilidad->value; }

    public function getCategoria(): CategoriaConocimiento { return $this->categoria; }

    public function setCategoria(CategoriaConocimiento|string|null $categoria): self
    {
        $this->categoria = is_string($categoria)
            ? (CategoriaConocimiento::tryFrom($categoria) ?? CategoriaConocimiento::General)
            : ($categoria ?? CategoriaConocimiento::General);

        return $this;
    }

    /* ======================================================
     * VISTA PÚBLICA (pax) — las llena PmsGuiaArbolFiltro
     * ====================================================== */

    public function setTituloParaCliente(array $titulo): self
    {
        $this->tituloParaCliente = $titulo;
        return $this;
    }

    /** @return array<int, array{language: string, content: string}> */
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    #[SerializedName('titulo')]
    public function getTituloParaCliente(): array { return $this->tituloParaCliente; }

    public function setContenidoParaCliente(array $contenido): self
    {
        $this->contenidoParaCliente = $contenido;
        return $this;
    }

    /** @return array<int, array{language: string, content: string}> */
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    #[SerializedName('descripcion')]
    public function getContenidoParaCliente(): array { return $this->contenidoParaCliente; }

    public function setBloqueadoHasta(?\DateTimeImmutable $bloqueadoHasta): self
    {
        $this->bloqueadoHasta = $bloqueadoHasta;
        return $this;
    }

    #[Groups(['pax_guia:read'])]
    public function getBloqueadoHasta(): ?\DateTimeImmutable { return $this->bloqueadoHasta; }

    public function setBloqueado(bool $bloqueado): self
    {
        $this->bloqueado = $bloqueado;
        return $this;
    }

    /** Atajo para la UI: pinta el candado sin recalcular la regla de acceso. */
    #[Groups(['pax_guia:read'])]
    public function isBloqueado(): bool { return $this->bloqueado; }

    // Contenido CRUDO, con los `{{ placeholders }}` sin resolver. No se
    // serializa nunca: solo lo lee PmsGuiaArbolFiltro para pasárselo al
    // interpolador. Lo que ve el cliente es getTituloParaCliente() /
    // getContenidoParaCliente(). Si algún día alguien le pone un grupo a estos
    // dos, el huésped verá `{{ door_code }}` literal en pantalla.
    public function getTitulo(): array { return MaestroIdioma::ordenarParaFormulario($this->titulo); }
    public function setTitulo(array $titulo): self { $this->titulo = MaestroIdioma::normalizarParaDB($titulo); return $this; }

    public function getDescripcion(): ?array { return MaestroIdioma::ordenarParaFormulario($this->descripcion ?? []); }
    public function setDescripcion(?array $descripcion): self { $this->descripcion = MaestroIdioma::normalizarParaDB($descripcion ?? []); return $this; }

    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    public function getIcono(): ?string
    {
        return $this->icono;
    }

    public function setIcono(?string $icono): self
    {
        $this->icono = $icono;
        return $this;
    }

    public function isAgenteRequiereHumano(): bool { return $this->agenteRequiereHumano; }

    public function setAgenteRequiereHumano(bool $requiere): self
    {
        $this->agenteRequiereHumano = $requiere;
        return $this;
    }

    public function getAgenteContenido(): ?string { return $this->agenteContenido; }

    public function setAgenteContenido(?string $contenido): self
    {
        $contenido = $contenido !== null ? trim($contenido) : null;
        $this->agenteContenido = ($contenido === null || $contenido === '') ? null : $contenido;

        return $this;
    }

    /** @return list<string> */
    public function getAgentePasos(): array { return $this->agentePasos ?? []; }

    /**
     * Se guardan sin huecos: un paso en blanco en medio dejaría un peldaño mudo, y un peldaño
     * mudo es una respuesta vacía justo cuando el huésped ya insistió una vez.
     *
     * @param list<string>|null $pasos
     */
    public function setAgentePasos(?array $pasos): self
    {
        $limpios = [];

        foreach ($pasos ?? [] as $paso) {
            $paso = trim((string) $paso);

            if ($paso !== '') {
                $limpios[] = $paso;
            }
        }

        $this->agentePasos = $limpios;

        return $this;
    }

    /** Cuántas veces se puede volver sobre el tema antes de que no quede nada que intentar. */
    public function pasosDisponibles(): int
    {
        return count($this->getAgentePasos());
    }

    /**
     * El texto que toca en este peldaño, o `null` si ya no queda nada que contar.
     *
     * `$peldano` 0 es la primera respuesta —el contenido de siempre— y de ahí en adelante se
     * recorren los pasos. El `null` **no es un error**: es la señal de que se agotó lo que se
     * podía intentar por chat y toca una persona. Quien llame decide qué hacer con eso; aquí no
     * se inventa un último mensaje de relleno.
     */
    public function agenteTextoParaPeldano(int $peldano): ?string
    {
        if ($peldano <= 0) {
            return $this->agenteContenido;
        }

        return $this->getAgentePasos()[$peldano - 1] ?? null;
    }

    public function getAgenteTerminos(): ?string { return $this->agenteTerminos; }

    public function setAgenteTerminos(?string $terminos): self
    {
        $terminos = trim((string) $terminos);
        $this->agenteTerminos = $terminos !== '' ? $terminos : null;

        return $this;
    }

    /**
     * Los términos ya partidos y limpios, listos para comparar.
     *
     * @return list<string>
     */
    public function getAgenteTerminosLista(): array
    {
        if ($this->agenteTerminos === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $t): string => trim($t),
            explode(',', $this->agenteTerminos)
        ), static fn (string $t): bool => $t !== ''));
    }

    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    public function getLabelBoton(): ?array { return MaestroIdioma::ordenarParaFormulario($this->labelBoton ?? []); }
    public function setLabelBoton(?array $labelBoton): self { $this->labelBoton = MaestroIdioma::normalizarParaDB($labelBoton ?? []); return $this; }

    public function getMetadata(): array { return $this->metadata ?? []; }
    public function setMetadata(?array $metadata): self { $this->metadata = $metadata; return $this; }

    public function getGaleria(): Collection { return $this->galeria; }
    public function addGaleria(PmsGuiaItemGaleria $galeria): self { if (!$this->galeria->contains($galeria)) { $this->galeria->add($galeria); $galeria->setItem($this); } return $this; }
    public function removeGaleria(PmsGuiaItemGaleria $galeria): self { if ($this->galeria->removeElement($galeria)) { if ($galeria->getItem() === $this) { $galeria->setItem(null); } } return $this; }

    public function getItemHasSecciones(): Collection { return $this->itemHasSecciones; }

    public function __toString(): string { return $this->nombreInterno ?: ($this->titulo['es'] ?? 'Ítem sin nombre'); }

    public function getVirtualGaleria(): string { return ''; }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $espanolEncontrado = false;
        if (!empty($this->titulo)) {
            foreach ($this->titulo as $item) {
                if (($item['language'] ?? '') === 'es' && !empty(trim($item['content'] ?? ''))) {
                    $espanolEncontrado = true;
                    break;
                }
            }
        }
        if (!$espanolEncontrado) $context->buildViolation('El título en español es obligatorio.')->atPath('titulo')->addViolation();

        $hasUrl = !empty($this->getUrlBoton());
        $hasLabel = false;
        if (!empty($this->labelBoton)) {
            foreach ($this->labelBoton as $item) {
                if (!empty(trim($item['content'] ?? ''))) {
                    $hasLabel = true;
                    break;
                }
            }
        }
        if ($hasUrl && !$hasLabel) $context->buildViolation('Si pones una URL, el botón debe tener texto.')->atPath('labelBoton')->addViolation();
    }

    public function getVirtualTitulo(): string { return ''; }
    public function getVirtualDescripcion(): string { return ''; }

    public function getVirtualLabelBoton(): string { return ''; }

}