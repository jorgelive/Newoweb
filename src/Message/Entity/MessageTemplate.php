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
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'msg_template')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/user/util/msg/templates')
    ],
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
    #[Assert\NotBlank]
    #[Groups(['template:read'])]
    private ?string $code = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Groups(['template:read'])]
    private ?string $name = null;

    #[ORM\Column(type: 'json')]
    private array $parameters = [];

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Groups(['template:read'])]
    private ?string $contextType = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['template:read'])]
    private ?array $allowedSources = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['template:read'])]
    private ?array $allowedAgencies = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['subject', 'body'])]
    private ?array $emailTmpl = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['body'])]
    private ?array $beds24Tmpl = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['body'])]
    private ?array $whatsappMetaTmpl = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['body'])]
    private ?array $whatsappLinkTmpl = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->emailTmpl = [];
        $this->beds24Tmpl = [];
        $this->whatsappMetaTmpl = [];
        $this->whatsappLinkTmpl = [];
        $this->allowedSources = [];
        $this->allowedAgencies = [];
    }

    /**
     * Obtiene los nombres de los campos que almacenan configuraciones de plantillas.
     * ¿Por qué existe? Útil para iterar dinámicamente sobre los canales disponibles
     * en factorías o validadores sin quemar strings mágicos en la lógica.
     *
     * @return array<int, string>
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
    public function getId(): UuidV7
    {
        return $this->id;
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

    public function getParameters(): array
    {
        return $this->parameters;
    }

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

    public function getAllowedSources(): array
    {
        return $this->allowedSources ?? [];
    }

    public function setAllowedSources(?array $allowedSources): self
    {
        $this->allowedSources = $allowedSources;
        return $this;
    }

    public function getAllowedAgencies(): array
    {
        return $this->allowedAgencies ?? [];
    }

    public function setAllowedAgencies(?array $allowedAgencies): self
    {
        $this->allowedAgencies = $allowedAgencies;
        return $this;
    }

    public function getEmailTmpl(): ?array
    {
        return $this->emailTmpl;
    }

    public function setEmailTmpl(?array $val): self
    {
        $this->emailTmpl = $val;
        return $this;
    }

    public function getBeds24Tmpl(): ?array
    {
        return $this->beds24Tmpl;
    }

    public function setBeds24Tmpl(?array $val): self
    {
        $this->beds24Tmpl = $val;
        return $this;
    }

    public function getWhatsappMetaTmpl(): ?array
    {
        return $this->whatsappMetaTmpl;
    }

    public function setWhatsappMetaTmpl(?array $val): self
    {
        $this->whatsappMetaTmpl = $val;
        return $this;
    }

    public function getWhatsappLinkTmpl(): ?array
    {
        return $this->whatsappLinkTmpl;
    }

    public function setWhatsappLinkTmpl(?array $val): self
    {
        $this->whatsappLinkTmpl = $val;
        return $this;
    }

    public function isEmailActive(): bool
    {
        return ($this->emailTmpl['is_active'] ?? false) === true;
    }

    public function isBeds24Active(): bool
    {
        return ($this->beds24Tmpl['is_active'] ?? false) === true;
    }

    public function isWhatsappMetaActive(): bool
    {
        return ($this->whatsappMetaTmpl['is_active'] ?? false) === true;
    }

    /**
     * Extrae los canales de comunicación activos configurados en la plantilla.
     * ¿Por qué existe? Este método se expone en la API mediante el grupo de serialización para que el
     * frontend (PWA o Panel) pueda determinar dinámicamente qué canales ofrecer en el selector
     * omnicanal al momento de enviar un mensaje al huésped.
     * * @return array<string> Arreglo de identificadores de canal (ej. ['beds24', 'whatsapp_meta'])
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
    // METODOS DE EXTRACCION DE DATOS POR IDIOMA (Lógica de Negocio Original)
    // =========================================================================

    /**
     * Extrae el asunto del correo en el idioma solicitado.
     *
     * @param string $lang Código de idioma (ej. 'es', 'en').
     * @return string|null Retorna el asunto, el fallback en inglés, o null si no existe.
     */
    public function getEmailSubject(string $lang): ?string
    {
        return $this->extract($this->emailTmpl['subject'] ?? [], $lang, 'content');
    }

    /**
     * Extrae el cuerpo HTML/Texto del correo en el idioma solicitado.
     *
     * @param string $lang Código de idioma (ej. 'es', 'en').
     * @return string|null
     */
    public function getEmailBody(string $lang): ?string
    {
        return $this->extract($this->emailTmpl['body'] ?? [], $lang, 'content');
    }

    /**
     * Extrae el contenido del mensaje para Beds24 según el idioma.
     *
     * @param string $lang Código de idioma.
     * @return string|null
     */
    public function getBeds24Body(string $lang): ?string
    {
        return $this->extract($this->beds24Tmpl['body'] ?? [], $lang, 'content');
    }

    /**
     * Obtiene el nombre interno de la plantilla en Meta (ej. 'despedida_booking_v2').
     * ¿Por qué existe? La API de WhatsApp requiere el nombre exacto de la plantilla
     * aprobada para poder disparar el mensaje de inicio de conversación.
     *
     * @return string|null
     */
    public function getWhatsappMetaName(): ?string
    {
        return $this->whatsappMetaTmpl['meta_template_name'] ?? $this->whatsappMetaTmpl['whatsapp_meta_template_name'] ?? null;
    }

    /**
     * Obtiene la categoría de la plantilla de Meta.
     *
     * @return string|null (ej. 'UTILITY', 'MARKETING')
     */
    public function getWhatsappMetaCategory(): ?string
    {
        return $this->whatsappMetaTmpl['category'] ?? null;
    }

    /**
     * Obtiene el mapeo de parámetros para inyectar variables en la plantilla de Meta.
     * ¿Por qué existe? Utilizado por los servicios de envío (Builders) para extraer
     * la configuración cruda y reemplazar {{1}}, {{2}} por los valores de la entidad.
     *
     * @return array
     */
    public function getWhatsappMetaParamsMap(): array
    {
        return $this->whatsappMetaTmpl['params_map'] ?? [];
    }

    /**
     * Obtiene el ID numérico de la plantilla en Meta correspondiente al idioma.
     * ¿Por qué existe? Al igual que Beds24 tiene cuerpos por idioma, Meta asume
     * que cada traducción es un "template_id" distinto. Este ID es vital para el webhook.
     *
     * @param string $lang Código del idioma.
     * @return string|null
     */
    public function getWhatsappMetaTemplateId(string $lang): ?string
    {
        return $this->extract($this->whatsappMetaTmpl['language_mapping'] ?? [], $lang, 'whatsapp_meta_template_id');
    }

    /**
     * Verifica si existe configuración válida de Meta para el idioma dado.
     *
     * @param string $lang Código del idioma.
     * @return bool
     */
    public function hasWhatsappMetaOfficialData(string $lang): bool
    {
        return $this->getWhatsappMetaTemplateId($lang) !== null;
    }

    /**
     * Obtiene el cuerpo decodificado de WhatsApp Meta para mostrar en la interfaz.
     * ¿Por qué existe? Aunque Meta se envía por template_name y parámetros, en el frontend
     * necesitamos mostrarle al usuario (ventana) cómo se verá exactamente el mensaje
     * final decodificado en el idioma del huésped.
     *
     * @param string $lang Código del idioma.
     * @return string|null
     */
    public function getWhatsappMetaBody(string $lang): ?string
    {
        return $this->extract($this->whatsappMetaTmpl['body'] ?? [], $lang, 'content');
    }

    /**
     * Obtiene el cuerpo del mensaje para WhatsApp Link (apertura manual de chat).
     *
     * @param string $lang Código del idioma.
     * @return string|null
     */
    public function getWhatsappLinkBody(string $lang): ?string
    {
        return $this->extract($this->whatsappLinkTmpl['body'] ?? [], $lang, 'content');
    }

    /**
     * Asigna o actualiza el ID de la plantilla de Meta WhatsApp para un idioma específico.
     *
     * ¿Por qué existe? Facilita el mapeo programático desde controladores o comandos
     * sin tener que manipular manualmente los arrays profundos del JSON.
     *
     * @param string $lang Código del idioma (ej. 'es', 'en').
     * @param string $templateId El ID devuelto por la API de Meta.
     * @return self
     */
    public function setWhatsappMetaLanguageMapping(string $lang, string $templateId): self
    {
        $mapping = $this->whatsappMetaTmpl['language_mapping'] ?? [];
        $found = false;

        foreach ($mapping as &$item) {
            if (($item['language'] ?? '') === $lang) {
                $item['whatsapp_meta_template_id'] = $templateId;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $mapping[] = ['language' => $lang, 'whatsapp_meta_template_id' => $templateId];
        }

        $this->whatsappMetaTmpl['language_mapping'] = $mapping;
        return $this;
    }

    /**
     * Asigna o actualiza el texto decodificado (body) de la plantilla de WhatsApp para un idioma.
     *
     * ¿Por qué existe? Para mantener sincronizado el texto que se muestra "en ventana"
     * con los idiomas configurados de manera programática.
     *
     * @param string $lang Código del idioma (ej. 'es').
     * @param string $content El texto con las variables listas para previsualizar.
     * @return self
     */
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
            $body[] = ['language' => $lang, 'content' => $content];
        }

        $this->whatsappMetaTmpl['body'] = $body;
        return $this;
    }

    // =========================================================================
    // GETTERS Y SETTERS ESPECIFICOS PARA FORMULARIOS (Casting a Objetos \stdClass)
    // =========================================================================

    /**
     * Obtiene el mapeo de variables de Meta (índice posicional a propiedad de entidad) como objetos.
     * ¿Por qué existe? Meta exige el uso de variables como {{1}}, {{2}}. Este método permite a
     * EasyAdmin renderizar una colección donde el usuario pueda asociar '1' -> 'guest_name'.
     *
     * @return object[]
     */
    public function getWhatsappMetaParamsMappings(): array
    {
        return array_map(fn($item) => (object) $item, $this->whatsappMetaTmpl['params_map'] ?? []);
    }

    /**
     * Recibe el mapeo de variables desde el formulario y lo convierte a arreglo puro.
     *
     * @param array $mappings
     * @return self
     */
    public function setWhatsappMetaParamsMappings(array $mappings): self
    {
        $this->whatsappMetaTmpl['params_map'] = array_map(fn($item) => (array) $item, $mappings);
        return $this;
    }

    /**
     * Obtiene el mapeo de lenguajes como un arreglo de objetos.
     * ¿Por qué existe? El componente Form de Symfony (específicamente CollectionField en EasyAdmin)
     * necesita trabajar con un arreglo de objetos para que PropertyAccessor pueda resolver
     * las propiedades (ej. $item->language en lugar de $item['language']).
     *
     * @return object[]
     */
    public function getWhatsappMetaLanguageMappings(): array
    {
        return array_map(fn($item) => (object) $item, $this->whatsappMetaTmpl['language_mapping'] ?? []);
    }

    /**
     * Recibe los mapeos desde el formulario y los convierte de vuelta a arreglo asociativo.
     * ¿Por qué existe? Evita guardar estructuras no deseadas en el JSON de Doctrine.
     *
     * @param array $mappings
     * @return self
     */
    public function setWhatsappMetaLanguageMappings(array $mappings): self
    {
        $this->whatsappMetaTmpl['language_mapping'] = array_map(fn($item) => (array) $item, $mappings);
        return $this;
    }

    /**
     * Obtiene los cuerpos de WhatsApp Meta como objetos para el formulario.
     *
     * @return object[]
     */
    public function getWhatsappMetaBodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->whatsappMetaTmpl['body'] ?? []);
    }

    /**
     * Recibe los cuerpos desde el formulario y los convierte a arreglo.
     *
     * @param array $bodies
     * @return self
     */
    public function setWhatsappMetaBodies(array $bodies): self
    {
        $this->whatsappMetaTmpl['body'] = array_map(fn($item) => (array) $item, $bodies);
        return $this;
    }

    /**
     * Obtiene los cuerpos de Beds24 como objetos para el formulario.
     *
     * @return object[]
     */
    public function getBeds24Bodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->beds24Tmpl['body'] ?? []);
    }

    /**
     * Recibe los cuerpos de Beds24 desde el formulario y los convierte a arreglo.
     *
     * @param array $bodies
     * @return self
     */
    public function setBeds24Bodies(array $bodies): self
    {
        $this->beds24Tmpl['body'] = array_map(fn($item) => (array) $item, $bodies);
        return $this;
    }

    /**
     * Obtiene los asuntos de Email como objetos para el formulario.
     *
     * @return object[]
     */
    public function getEmailSubjects(): array
    {
        return array_map(fn($item) => (object) $item, $this->emailTmpl['subject'] ?? []);
    }

    /**
     * Recibe los asuntos de Email desde el formulario y los convierte a arreglo.
     *
     * @param array $subjects
     * @return self
     */
    public function setEmailSubjects(array $subjects): self
    {
        $this->emailTmpl['subject'] = array_map(fn($item) => (array) $item, $subjects);
        return $this;
    }

    /**
     * Obtiene los cuerpos de Email como objetos para el formulario.
     *
     * @return object[]
     */
    public function getEmailBodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->emailTmpl['body'] ?? []);
    }

    /**
     * Recibe los cuerpos de Email desde el formulario y los convierte a arreglo.
     *
     * @param array $bodies
     * @return self
     */
    public function setEmailBodies(array $bodies): self
    {
        $this->emailTmpl['body'] = array_map(fn($item) => (array) $item, $bodies);
        return $this;
    }

    /**
     * Obtiene los cuerpos de Whatsapp Link como objetos para el formulario.
     *
     * @return object[]
     */
    public function getWhatsappLinkBodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->whatsappLinkTmpl['body'] ?? []);
    }

    /**
     * Recibe los cuerpos de Whatsapp Link desde el formulario y los convierte a arreglo.
     *
     * @param array $bodies
     * @return self
     */
    public function setWhatsappLinkBodies(array $bodies): self
    {
        $this->whatsappLinkTmpl['body'] = array_map(fn($item) => (array) $item, $bodies);
        return $this;
    }

    // =========================================================================
    // LOGICA INTERNA PRIVADA
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

        $finalItem = $foundItem ?? $englishItem ?? ($list[0] ?? null);

        return $finalItem[$key] ?? null;
    }
}