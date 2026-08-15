<?php

declare(strict_types=1);

namespace App\Message\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Los bloques por canal (`emailTmpl`, `beds24Tmpl`…) son todos la misma forma: una clave por
 * pieza del mensaje —`body`, `subject`, `header`, `footer`, `buttons_map`— y dentro, una entrada
 * por idioma. Es lo que espera `AutoTranslate` con sus `nestedFields` y lo que lee `extract()`.
 *
 * ⚠️ No son sólo listas por idioma: llevan también banderas escalares (`is_active`,
 * `is_official_meta`…). Un tipo que sólo describiera las listas sería MENTIRA, y un tipo
 * mentiroso es peor que ninguno — el analizador se lo cree y deja de mirar. Esta forma está
 * derivada de las claves realmente usadas en `src/`, no supuesta.
 *
 * ⚠️ **`buttons_map` NO es una lista de textos: son botones**, y su `button_text` es a su vez
 * una lista de traducciones. Se descubrió tipando: al declararlo como texto, PHPStan avisó de
 * que el `foreach` de `Beds24SendMappingStrategy` recorría algo que nunca podía tener elementos.
 * Es la tercera corrección a este tipo, y la razón de que estas anotaciones se escriban leyendo
 * el uso real y comprobando, no en tandas.
 *
 * @phpstan-type TextoTraducido array{
 *     language?: string,
 *     content?: string|null,
 *     format?: string|null,
 *     status?: string|null
 * }
 * @phpstan-type BotonDeMenu array{
 *     type?: string|null,
 *     index?: int|string|null,
 *     resolver_key?: string|null,
 *     payload?: mixed,
 *     button_text?: list<TextoTraducido>
 * }
 * @phpstan-type BloqueDeCanal array{
 *     body?: list<TextoTraducido>,
 *     subject?: list<TextoTraducido>,
 *     header?: list<TextoTraducido>,
 *     footer?: list<TextoTraducido>,
 *     buttons_map?: list<BotonDeMenu>,
 *     is_active?: bool,
 *     is_official_meta?: bool,
 *     disable_meta_buttons?: bool,
 *     meta_template_name?: string|null,
 *     category?: string|null
 * }
 */
#[ORM\Entity]
#[ORM\Table(name: 'msg_template')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'], message: 'Este código de plantilla ya existe. El código debe ser único.')]
#[ApiResource(
    shortName: 'Template', // 🔥 Le dice a API Platform que el recurso base es "templates"
    operations: [
        // API Platform infiere automáticamente: GET /message/templates
        new GetCollection()
    ], // 🔥 Define el módulo o contexto
    routePrefix: '/message',
    normalizationContext: ['groups' => ['template:read']]
)]
class MessageTemplate
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    public const string FIELD_EMAIL = 'emailTmpl';
    public const string FIELD_BEDS24 = 'beds24Tmpl';
    public const string FIELD_WHATSAPP_META = 'whatsappMetaTmpl';
    public const string FIELD_WHATSAPP_LINK = 'whatsappLinkTmpl';

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank(message: 'El código de la plantilla es obligatorio.')]
    #[Assert\Length(
        max: 50,
        maxMessage: 'El código no puede superar los {{ limit }} caracteres.'
    )]
    #[Groups(['template:read'])]
    private ?string $code = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'El nombre de la plantilla es obligatorio.')]
    #[Assert\Length(
        max: 150,
        maxMessage: 'El nombre no puede superar los {{ limit }} caracteres.'
    )]
    #[Groups(['template:read'])]
    private ?string $name = null;

    /** @var list<string> Marcadores que la plantilla espera: «nombre_huesped», «fecha_llegada»… */
    #[ORM\Column(type: 'json')]
    #[Assert\Type(type: 'array')]
    private array $parameters = [];

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Length(
        max: 50,
        maxMessage: 'El tipo de contexto no puede superar los {{ limit }} caracteres.'
    )]
    #[Groups(['template:read'])]
    private ?string $contextType = null;

    /**
     * Cuándo usar esta plantilla, escrito **para el agente de IA**.
     *
     * `name` no vale para esto: es una etiqueta de panel, y algunas son referencias internas
     * («Autorespuesta ER130497») que a un modelo no le dicen nada. Aquí va la frase que le
     * permite ELEGIR: en qué situación se manda y en cuál no.
     *
     * Sin esto la plantilla es inalcanzable para el agente, igual que un campo que se devuelve
     * y no se anuncia. Ver docs/Mensajeria.md §11.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['template:read'])]
    private ?string $agenteUso = null;

    /**
     * ¿Puede el huésped pedírsela a sí mismo por el chat (autoenvío)?
     *
     * ⚠️ Cambió de significado (antes `agenteHabilitada`): **ya no acota lo que el agente puede
     * mandar** — el agente puede mandar cualquiera que tenga `agenteUso` escrito, a petición de
     * un operador y con la correspondencia de OTA como guardia (`allowedSources`: la bienvenida
     * de Booking va al de Booking, la de Airbnb al de Airbnb). Este flag acota lo OTRO: qué
     * plantillas puede dispararse el huésped él solo («mándame mi guía», «el menú de tours»),
     * donde no hay ningún operador que confirme.
     *
     * Por defecto **no**, y se enciende una a una desde el panel: es una decisión de negocio.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['template:read'])]
    private bool $autoenvioHabilitada = false;

    /** @var list<string>|null Códigos de canal: `booking`, `airbnb`, `directo`… */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array')]
    #[Groups(['template:read'])]
    private ?array $allowedSources = [];

    /** @var list<string>|null Identificadores de agencia mayorista. */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array')]
    #[Groups(['template:read'])]
    private ?array $allowedAgencies = [];

    /** @var BloqueDeCanal|null */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array', message: 'La configuración de Email debe ser un arreglo o estructura JSON válida.')]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['subject', 'body'])]
    private ?array $emailTmpl = [];

    /** @var BloqueDeCanal|null */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array', message: 'La configuración de Beds24 debe ser un arreglo o estructura JSON válida.')]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['body'])]
    private ?array $beds24Tmpl = [];

    /** @var BloqueDeCanal|null */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array', message: 'La configuración de WhatsApp Meta debe ser un arreglo o estructura JSON válida.')]
    // OPTIMIZACIÓN: Se añaden header y footer a los campos auto-traducibles
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['body', 'header', 'footer', 'buttons_map->button_text'], preventOverwriteIf: 'isWhatsappMetaOfficial')]
    private ?array $whatsappMetaTmpl = [];

    /** @var BloqueDeCanal|null */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array', message: 'La configuración de WhatsApp Link debe ser un arreglo o estructura JSON válida.')]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['body'])]
    private ?array $whatsappLinkTmpl = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->emailTmpl = [];
        $this->beds24Tmpl = [];
        $this->whatsappMetaTmpl = [
            'is_official_meta' => true
        ];
        $this->whatsappLinkTmpl = [];
        $this->allowedSources = [];
        $this->allowedAgencies = [];
    }

    /**
     * Obtiene los nombres de los campos que almacenan configuraciones de plantillas.
     * ¿Por qué existe? Útil para iterar dinámicamente sobre los canales disponibles
     * en factorías o validadores sin quemar strings mágicos en la lógica.
     *
     * Ejemplo de uso: foreach (MessageTemplate::getTemplateFields() as $field) { ... }
     *
     * @return array<int, string> Arreglo con las constantes de los canales.
     */
    public static function getTemplateFields(): array
    {
        return [self::FIELD_EMAIL, self::FIELD_BEDS24, self::FIELD_WHATSAPP_META, self::FIELD_WHATSAPP_LINK];
    }

    public function __toString(): string
    {
        return $this->name ?? $this->code ?? 'New Template';
    }

    #[Groups(['template:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getAgenteUso(): ?string { return $this->agenteUso; }
    public function setAgenteUso(?string $agenteUso): self { $this->agenteUso = $agenteUso; return $this; }

    public function isAutoenvioHabilitada(): bool { return $this->autoenvioHabilitada; }
    public function setAutoenvioHabilitada(bool $autoenvioHabilitada): self { $this->autoenvioHabilitada = $autoenvioHabilitada; return $this; }

    /**
     * Utilizable por el agente (a petición de un operador): basta con que tenga escrito para
     * qué sirve.
     *
     * Sin el `agenteUso` el modelo elegiría por el `code`, que es adivinar — la plantilla sin
     * frase queda inalcanzable a propósito. El flag de autoenvío NO se mira aquí: al operador
     * lo protege su propia confirmación, y la correspondencia de OTA la valida la skill.
     */
    public function disponibleParaAgente(): bool
    {
        return trim((string) $this->agenteUso) !== '';
    }

    /**
     * Pedible por el propio huésped: habilitada para autoenvío Y con su uso escrito.
     *
     * Se exigen las dos para que habilitar una plantilla a medias no la ponga en circulación.
     */
    public function disponibleParaAutoenvio(): bool
    {
        return $this->autoenvioHabilitada && $this->disponibleParaAgente();
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = trim($code);
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /** @return list<string> */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /** @param list<string> $parameters */
    public function setParameters(array $parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }

    public function getContextType(): ?string
    {
        return $this->contextType;
    }

    public function setContextType(?string $contextType): self
    {
        $this->contextType = $contextType;
        return $this;
    }

    /** @return list<string> */
    public function getAllowedSources(): array
    {
        return $this->allowedSources ?? [];
    }

    /** @param list<string>|null $allowedSources */
    public function setAllowedSources(?array $allowedSources): self
    {
        $this->allowedSources = $allowedSources;
        return $this;
    }

    /** @return list<string> */
    public function getAllowedAgencies(): array
    {
        return $this->allowedAgencies ?? [];
    }

    /** @param list<string>|null $allowedAgencies */
    public function setAllowedAgencies(?array $allowedAgencies): self
    {
        $this->allowedAgencies = $allowedAgencies;
        return $this;
    }

    /** @return BloqueDeCanal|null */
    public function getEmailTmpl(): ?array
    {
        return $this->emailTmpl;
    }

    /** @param BloqueDeCanal|null $val */
    public function setEmailTmpl(?array $val): self
    {
        $this->emailTmpl = $val;
        return $this;
    }

    /** @return BloqueDeCanal|null */
    public function getBeds24Tmpl(): ?array
    {
        return $this->beds24Tmpl;
    }

    /** @param BloqueDeCanal|null $val */
    public function setBeds24Tmpl(?array $val): self
    {
        $this->beds24Tmpl = $val;
        return $this;
    }

    /** @return BloqueDeCanal|null */
    public function getWhatsappMetaTmpl(): ?array
    {
        return $this->whatsappMetaTmpl;
    }

    /** @param BloqueDeCanal|null $val */
    public function setWhatsappMetaTmpl(?array $val): self
    {
        $this->whatsappMetaTmpl = $val;
        return $this;
    }

    /** Stub para la columna «Canales» del listado. Ver {@see self::getVirtualEstadoMeta()}. */
    public function getVirtualCanales(): string
    {
        return '';
    }

    /**
     * En qué canales está redactada esta plantilla y en cuáles está encendida.
     *
     * Son **dos cosas distintas** y por eso se devuelven por separado:
     *
     * - `creado`: hay texto escrito para ese canal (`body` con contenido).
     * - `habilitado`: el interruptor `is_active` del JSON está en `true`, que es lo que mira
     *   {@see \App\Message\Service\Queue\MessageDispatcher} para decidir si sale por ahí.
     *
     * Una plantilla redactada pero apagada es el caso que más confunde al leer la lista:
     * el texto está, se ve al editar, y sin embargo no se envía por ese canal.
     *
     * No se usa `is_active` a secas como prueba de existencia porque el constructor deja
     * `whatsappMetaTmpl` con `is_official_meta` puesto: el array no está vacío aunque nadie
     * haya escrito nada. La prueba honesta es que `body` tenga contenido.
     *
     * @return array<string, array{creado: bool, habilitado: bool}> Etiqueta => estado.
     */
    public function getCanales(): array
    {
        $canales = [
            'Correo' => $this->emailTmpl,
            'Beds24' => $this->beds24Tmpl,
            'WhatsApp' => $this->whatsappMetaTmpl,
            'WA enlace' => $this->whatsappLinkTmpl,
        ];

        $estado = [];

        foreach ($canales as $etiqueta => $tmpl) {
            $body = is_array($tmpl) ? ($tmpl['body'] ?? null) : null;

            $estado[$etiqueta] = [
                'creado' => is_array($body) && $body !== [],
                'habilitado' => is_array($tmpl) && ($tmpl['is_active'] ?? false) === true,
            ];
        }

        return $estado;
    }

    /**
     * Stub para la columna «Estado Meta» del listado.
     *
     * Existe por una limitación de EasyAdmin, no por diseño: `TextField` valida el valor
     * CRUDO antes de pasarlo por `formatValue()`, así que apuntarlo directamente a
     * {@see self::getEstadoMetaPorIdioma()} —que devuelve un array— revienta. El campo se
     * ancla aquí y el contenido real lo pinta el `formatValue` del CRUD.
     *
     * Mismo truco que `PmsCatalogo::$virtualContenidos`.
     */
    public function getVirtualEstadoMeta(): string
    {
        return '';
    }

    /**
     * Estado de aprobación en Meta, por idioma. Campo VIRTUAL: no hay columna detrás.
     *
     * Meta aprueba **cada idioma por separado**, así que una plantilla no está «aprobada» o
     * «pendiente» sin más: puede tener el español aprobado y el italiano en revisión, y
     * entonces sirve para casi todo. Por eso se devuelve el recuento por estado y no una
     * sola etiqueta, que obligaría a decidir cuál de los siete manda.
     *
     * El orden importa: primero lo que bloquea (`REJECTED`), luego lo que aún no se puede
     * usar (`PENDING`), y al final lo aprobado. Quien mira la lista busca problemas.
     *
     * Vacío cuando la plantilla no tiene bloque de WhatsApp: no es un error, es que esa
     * plantilla no sale por ese canal.
     *
     * @return array<string, int> Estado => cuántos idiomas, ej. `['APPROVED' => 6, 'PENDING' => 1]`
     */
    public function getEstadoMetaPorIdioma(): array
    {
        $cuerpos = $this->whatsappMetaTmpl['body'] ?? null;

        if (!is_array($cuerpos) || $cuerpos === []) {
            return [];
        }

        $conteo = [];

        foreach ($cuerpos as $cuerpo) {
            // Un idioma recién añadido a mano puede no traer `status` todavía; cuenta como
            // no enviado, que es justo lo que es.
            $estado = is_array($cuerpo) ? (string) ($cuerpo['status'] ?? 'SIN ENVIAR') : 'SIN ENVIAR';
            $conteo[$estado] = ($conteo[$estado] ?? 0) + 1;
        }

        $prioridad = ['REJECTED' => 0, 'PENDING' => 1, 'SIN ENVIAR' => 2, 'APPROVED' => 3];
        uksort($conteo, static fn (string $a, string $b): int => ($prioridad[$a] ?? 9) <=> ($prioridad[$b] ?? 9));

        return $conteo;
    }

    /** @return BloqueDeCanal|null */
    public function getWhatsappLinkTmpl(): ?array
    {
        return $this->whatsappLinkTmpl;
    }

    /** @param BloqueDeCanal|null $val */
    public function setWhatsappLinkTmpl(?array $val): self
    {
        $this->whatsappLinkTmpl = $val;
        return $this;
    }
    #[Groups(['template:read'])]
    public function isEmailActive(): bool
    {
        return ($this->emailTmpl['is_active'] ?? false) === true;
    }

    #[Groups(['template:read'])]
    public function isBeds24Active(): bool
    {
        return ($this->beds24Tmpl['is_active'] ?? false) === true;
    }

    /**
     * Verifica si el usuario ha decidido ocultar explícitamente los botones de Meta
     * al momento de enviar este mensaje a través de Beds24 (OTAs).
     *
     * @return bool
     */
    #[Groups(['template:read'])]
    public function isBeds24MetaButtonsDisabled(): bool
    {
        return ($this->beds24Tmpl['disable_meta_buttons'] ?? false) === true;
    }

    #[Groups(['template:read'])]
    public function isWhatsappMetaActive(): bool
    {
        return ($this->whatsappMetaTmpl['is_active'] ?? false) === true;
    }

    /**
     * Determina si esta plantilla es oficial de Meta (aprobada por ellos) o es un "Quick Reply" interno del PMS.
     * ¿Por qué existe? Meta es estricto: no podemos enviar plantillas "no oficiales" fuera de la ventana de 24h.
     * El Mapper usa esto como barrera de seguridad para bloquear intentos inválidos.
     *
     * @return bool True si es oficial y sincronizada, False si es interna/quick reply.
     */
    #[Groups(['template:read'])]
    public function isWhatsappMetaOfficial(): bool
    {
        return ($this->whatsappMetaTmpl['is_official_meta'] ?? true) === true;
    }

    /**
     * Define si la plantilla es oficial o interna.
     * * @param bool $isOfficial
     * @return self
     */
    public function setWhatsappMetaOfficial(bool $isOfficial): self
    {
        $this->whatsappMetaTmpl['is_official_meta'] = $isOfficial;
        return $this;
    }

    /**
     * Extrae los canales de comunicación activos configurados en la plantilla.
     * ¿Por qué existe? Este método se expone en la API mediante el grupo de serialización para que el
     * frontend (PWA o Panel) pueda determinar dinámicamente qué canales ofrecer en el selector
     * omnicanal al momento de enviar un mensaje al huésped.
     *
     * @return array<int, string> Arreglo de identificadores de canal (ej. ['beds24', 'whatsapp_meta'])
     */
    #[Groups(['template:read'])]
    public function getChannels(): array
    {
        $channels = [];

        if ($this->isBeds24Active()) {
            $channels[] = 'beds24';
        }

        if ($this->isWhatsappMetaActive()) {
            $channels[] = 'whatsapp_meta';
        }

        if ($this->isEmailActive()) {
            $channels[] = 'email';
        }

        return $channels;
    }

    // =========================================================================
    // MÉTODOS DE EXTRACCIÓN DE DATOS POR IDIOMA (Lógica de Negocio)
    // =========================================================================

    public function getEmailSubject(string $lang): ?string
    {
        return $this->extract($this->emailTmpl['subject'] ?? [], $lang, 'content');
    }

    public function getEmailBody(string $lang): ?string
    {
        return $this->extract($this->emailTmpl['body'] ?? [], $lang, 'content');
    }

    public function getBeds24Body(string $lang): ?string
    {
        return $this->extract($this->beds24Tmpl['body'] ?? [], $lang, 'content');
    }

    /**
     * Obtiene el nombre maestro de la plantilla en Meta (ej. 'welcome_booking').
     * ¿Por qué existe? La API de WhatsApp requiere este nombre base nativo
     * combinado con el código ISO-2 de idioma para enviar la plantilla oficial.
     *
     * @return string|null
     */
    public function getWhatsappMetaName(): ?string
    {
        return $this->whatsappMetaTmpl['meta_template_name'] ?? null;
    }

    /**
     * Si la plantilla sale por WhatsApp, tiene que tener «Nombre en Meta». Sin excepciones.
     *
     * Es la clave por la que Meta identifica la plantilla, y sin ella no falla nada de forma
     * visible — falla TODO de forma invisible:
     *
     * - `WhatsappMetaTemplatePushService` la manda como `name` del payload: con `null` la
     *   plantilla nunca llega a Meta, así que nunca entra en revisión y nadie puede aprobarla.
     * - `WhatsappMetaTemplateSyncService` empareja por ese mismo nombre: sin él, el
     *   sincronizador de las 03:15 pasa de largo cada noche.
     *
     * Y mientras tanto el panel enseña el `status` que alguien escribió a mano, que parece una
     * revisión en curso y no lo es. `menu_tours` estuvo así cinco meses: 9 huéspedes pidieron
     * el menú de tours y no recibieron nada.
     *
     * Se valida aquí y no en el CRUD porque la entidad se toca desde el panel, desde el
     * sincronizador y desde las migraciones: la regla tiene que ser la misma en las tres.
     */
    #[Assert\Callback]
    public function validarNombreDeMeta(ExecutionContextInterface $contexto): void
    {
        if (!$this->isWhatsappMetaActive()) {
            return;
        }

        $nombre = $this->getWhatsappMetaName();

        if (is_string($nombre) && trim($nombre) !== '') {
            return;
        }

        $contexto->buildViolation(
            'Esta plantilla está activa para WhatsApp Meta, así que necesita un «Nombre en Meta» '
            . '(«meta_template_name» dentro del JSON). Sin él no se puede subir a Meta ni reconocer '
            . 'lo que Meta devuelva, y el estado que verías aquí sería falso. Suele ser el mismo código: «%code%».'
        )
            ->setParameter('%code%', (string) $this->code)
            ->atPath('whatsappMetaTmpl')
            ->addViolation();
    }

    public function getWhatsappMetaCategory(): ?string
    {
        return $this->whatsappMetaTmpl['category'] ?? null;
    }

    /**
     * Obtiene el estado de aprobación de Meta para un idioma específico.
     * ¿Por qué existe? Meta aprueba traducciones de forma individual. Este método
     * lee el array 'body' para confirmar si ese idioma exacto está 'APPROVED', 'PENDING', etc.
     *
     * @param string $lang Código del idioma.
     * @return string|null Retorna el estado (ej. 'APPROVED') o null si no se encuentra.
     */
    public function getWhatsappMetaStatus(string $lang): ?string
    {
        return $this->extract($this->whatsappMetaTmpl['body'] ?? [], $lang, 'status');
    }

    /**
     * Verifica si existe configuración válida y aprobada de Meta para el idioma dado.
     * Dependencia crítica: La estrategia de mapeo usa esto para decidir si puede
     * enviar la plantilla por la API oficial o debe hacer un fallback a texto libre.
     *
     * @param string $lang Código del idioma.
     * @return bool
     */
    public function hasWhatsappMetaOfficialData(string $lang): bool
    {
        $status = $this->getWhatsappMetaStatus($lang);
        return $status === 'APPROVED';
    }

    public function getWhatsappMetaBody(string $lang): ?string
    {
        return $this->extract($this->whatsappMetaTmpl['body'] ?? [], $lang, 'content');
    }

    /**
     * Extrae y ensambla los botones configurados para un idioma específico.
     * Modificado para incluir el 'resolver_key' necesario para hidratar URLs dinámicas.
     *
     * @param string $lang Código del idioma (ej. 'es').
     * @return array<int, array<string, mixed>> Lista de botones.
     */
    public function getWhatsappMetaButtons(string $lang): array
    {
        $buttonsMap = $this->whatsappMetaTmpl['buttons_map'] ?? [];
        $resolvedButtons = [];

        foreach ($buttonsMap as $btnConfig) {
            $translatedLabel = $this->extract($btnConfig['button_text'] ?? [], $lang, 'content');

            $resolvedButtons[] = [
                'index'        => $btnConfig['index'] ?? 0,
                'type'         => $btnConfig['type'] ?? 'url',
                'content'      => $btnConfig['content'] ?? '',
                'resolver_key' => $btnConfig['resolver_key'] ?? null,
                'button_text'  => $translatedLabel,
            ];
        }

        return $resolvedButtons;
    }

    public function getWhatsappLinkBody(string $lang): ?string
    {
        return $this->extract($this->whatsappLinkTmpl['body'] ?? [], $lang, 'content');
    }

    /**
     * Indica si la plantilla tiene contenido "link" de WhatsApp (api.whatsapp.com)
     * en español o inglés. Usado por el frontend para filtrar qué plantillas
     * ofrecer en el selector de "Enviar WhatsApp" de una reserva.
     */
    #[Groups(['template:read'])]
    public function hasWhatsappLinkContent(): bool
    {
        return $this->getWhatsappLinkBody('es') !== null || $this->getWhatsappLinkBody('en') !== null;
    }

    /**
     * Obtiene la configuración del Header para un idioma específico.
     * @return array{format: string, content: string|null}|null
     */
    public function getWhatsappMetaHeader(string $lang): ?array
    {
        $headers = $this->whatsappMetaTmpl['header'] ?? [];
        foreach ($headers as $h) {
            if (($h['language'] ?? '') === $lang) {
                return [
                    'format'  => strtoupper($h['format'] ?? 'TEXT'),
                    'content' => $h['content'] ?? null
                ];
            }
        }
        return null;
    }

    /**
     * Obtiene el texto del pie de página para un idioma específico.
     */
    public function getWhatsappMetaFooter(string $lang): ?string
    {
        return $this->extract($this->whatsappMetaTmpl['footer'] ?? [], $lang, 'content');
    }

    public function setWhatsappMetaBodyForLanguage(string $lang, string $content): self
    {
        $body = $this->whatsappMetaTmpl['body'] ?? [];
        $found = false;

        foreach ($body as &$item) {
            if (($item['language'] ?? '') === $lang) {
                $item['content'] = $content;
                $found = true;
                break;
            }
        }

        if (!$found) {
            // Se asume estado PENDING por defecto al crear programáticamente
            $body[] = ['language' => $lang, 'status' => 'PENDING', 'content' => $content];
        }

        $this->whatsappMetaTmpl['body'] = $body;
        return $this;
    }

    // =========================================================================
    // GETTERS Y SETTERS ESPECÍFICOS PARA FORMULARIOS (Casting a \stdClass)
    // =========================================================================

    /**
     * Obtiene los cuerpos de WhatsApp Meta como objetos para el formulario de EasyAdmin.
     *
     * @return object[] Arreglo de objetos stdClass.
     */
    public function getWhatsappMetaBodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->whatsappMetaTmpl['body'] ?? []);
    }

    /** @param list<object|array<string, mixed>> $bodies Vienen del CollectionField de EasyAdmin. */
    public function setWhatsappMetaBodies(array $bodies): self
    {
        $this->whatsappMetaTmpl['body'] = array_map(fn($item) => (array) $item, $bodies);
        return $this;
    }

    /**
     * Obtiene la estructura de botones de Meta como objetos para manipulación en formulario.
     * ¿Por qué existe? Similar a los bodies, EasyAdmin (CollectionField) requiere objetos en la raíz
     * del array para que el PropertyAccessor funcione correctamente.
     *
     * @return object[] Arreglo de objetos stdClass representando el map de botones.
     */
    public function getWhatsappMetaButtonsMap(): array
    {
        return array_map(fn($item) => (object) $item, $this->whatsappMetaTmpl['buttons_map'] ?? []);
    }

    /**
     * Recibe los botones desde el formulario y los convierte de vuelta a array.
     *
     * @param array $buttons
     * @return self
     */
    /** @param list<object|array<string, mixed>> $buttons Vienen del CollectionField de EasyAdmin. */
    public function setWhatsappMetaButtonsMap(array $buttons): self
    {
        $this->whatsappMetaTmpl['buttons_map'] = array_map(fn($item) => (array) $item, $buttons);
        return $this;
    }

    /** @return list<object> */
    public function getWhatsappMetaHeaders(): array
    {
        return array_map(fn($item) => (object) $item, $this->whatsappMetaTmpl['header'] ?? []);
    }

    /** @param list<object|array<string, mixed>> $headers Vienen del CollectionField de EasyAdmin. */
    public function setWhatsappMetaHeaders(array $headers): self
    {
        $this->whatsappMetaTmpl['header'] = array_map(fn($item) => (array) $item, $headers);
        return $this;
    }

    /** @return list<object> */
    public function getWhatsappMetaFooters(): array
    {
        return array_map(fn($item) => (object) $item, $this->whatsappMetaTmpl['footer'] ?? []);
    }

    /** @param list<object|array<string, mixed>> $footers Vienen del CollectionField de EasyAdmin. */
    public function setWhatsappMetaFooters(array $footers): self
    {
        $this->whatsappMetaTmpl['footer'] = array_map(fn($item) => (array) $item, $footers);
        return $this;
    }

    /** @return list<object> */
    public function getBeds24Bodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->beds24Tmpl['body'] ?? []);
    }

    /** @param list<object|array<string, mixed>> $bodies Vienen del CollectionField de EasyAdmin. */
    public function setBeds24Bodies(array $bodies): self
    {
        $this->beds24Tmpl['body'] = array_map(fn($item) => (array) $item, $bodies);
        return $this;
    }

    /** @return list<object> */
    public function getEmailSubjects(): array
    {
        return array_map(fn($item) => (object) $item, $this->emailTmpl['subject'] ?? []);
    }

    /** @param list<object|array<string, mixed>> $subjects Vienen del CollectionField de EasyAdmin. */
    public function setEmailSubjects(array $subjects): self
    {
        $this->emailTmpl['subject'] = array_map(fn($item) => (array) $item, $subjects);
        return $this;
    }

    /** @return list<object> */
    public function getEmailBodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->emailTmpl['body'] ?? []);
    }

    /** @param list<object|array<string, mixed>> $bodies Vienen del CollectionField de EasyAdmin. */
    public function setEmailBodies(array $bodies): self
    {
        $this->emailTmpl['body'] = array_map(fn($item) => (array) $item, $bodies);
        return $this;
    }

    /** @return list<object> */
    public function getWhatsappLinkBodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->whatsappLinkTmpl['body'] ?? []);
    }

    /** @param list<object|array<string, mixed>> $bodies Vienen del CollectionField de EasyAdmin. */
    public function setWhatsappLinkBodies(array $bodies): self
    {
        $this->whatsappLinkTmpl['body'] = array_map(fn($item) => (array) $item, $bodies);
        return $this;
    }

    // =========================================================================
    // LÓGICA INTERNA PRIVADA
    // =========================================================================

    /**
     * Función base para extraer contenido multi-idioma de una estructura de arreglo dada.
     * Soporta fallback automático a inglés ('en') o al primer elemento disponible.
     *
     * @param array|null $list Arreglo que contiene nodos con claves 'language'.
     * @param string $lang El idioma objetivo a buscar.
     * @param string $key La clave del valor que se desea retornar (por defecto 'content').
     * @return string|null
     */
    /** @param list<TextoTraducido>|null $list */
    private function extract(?array $list, string $lang, string $key = 'content'): ?string
    {
        if (empty($list) || !is_array($list)) {
            return null;
        }

        $foundItem = null;
        $englishItem = null;

        foreach ($list as $item) {
            $itemLang = $item['language'] ?? '';

            if ($itemLang === $lang) {
                $foundItem = $item;
                break;
            }
            if ($itemLang === 'en') {
                $englishItem = $item;
            }
        }

        // El `[0]` existe: la guarda de arriba ya descartó la lista vacía.
        $finalItem = $foundItem ?? $englishItem ?? $list[0];

        return $finalItem[$key] ?? null;
    }
}