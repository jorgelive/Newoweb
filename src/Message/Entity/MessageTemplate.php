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

#[ORM\Entity]
#[ORM\Table(name: 'msg_template')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'], message: 'Este código de plantilla ya existe. El código debe ser único.')]
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

    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array')]
    #[Groups(['template:read'])]
    private ?array $allowedSources = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array')]
    #[Groups(['template:read'])]
    private ?array $allowedAgencies = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array', message: 'La configuración de Email debe ser un arreglo o estructura JSON válida.')]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['subject', 'body'])]
    private ?array $emailTmpl = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array', message: 'La configuración de Beds24 debe ser un arreglo o estructura JSON válida.')]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['body'])]
    private ?array $beds24Tmpl = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\Type(type: 'array', message: 'La configuración de WhatsApp Meta debe ser un arreglo o estructura JSON válida.')]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['body', 'buttons_map->button_text'], preventOverwriteIf: 'isWhatsappMetaOfficial')]
    private ?array $whatsappMetaTmpl = [];

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
            'is_official_meta' => true // Por defecto asumimos que es oficial, el usuario en EasyAdmin puede cambiarlo a false.
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
     * Determina si esta plantilla es oficial de Meta (aprobada por ellos) o es un "Quick Reply" interno del PMS.
     * ¿Por qué existe? Meta es estricto: no podemos enviar plantillas "no oficiales" fuera de la ventana de 24h.
     * El Mapper usa esto como barrera de seguridad para bloquear intentos inválidos.
     *
     * @return bool True si es oficial y sincronizada, False si es interna/quick reply.
     */
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
     * Asigna o actualiza el texto decodificado (body) de la plantilla de WhatsApp para un idioma.
     * ¿Por qué existe? Permite actualizar programáticamente el contenido de la base
     * sin tener que reconstruir manualmente el array, conservando el estado.
     *
     * @param string $lang Código del idioma (ej. 'es').
     * @param string $content El texto con las variables.
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
    public function setWhatsappMetaButtonsMap(array $buttons): self
    {
        $this->whatsappMetaTmpl['buttons_map'] = array_map(fn($item) => (array) $item, $buttons);
        return $this;
    }

    public function getBeds24Bodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->beds24Tmpl['body'] ?? []);
    }

    public function setBeds24Bodies(array $bodies): self
    {
        $this->beds24Tmpl['body'] = array_map(fn($item) => (array) $item, $bodies);
        return $this;
    }

    public function getEmailSubjects(): array
    {
        return array_map(fn($item) => (object) $item, $this->emailTmpl['subject'] ?? []);
    }

    public function setEmailSubjects(array $subjects): self
    {
        $this->emailTmpl['subject'] = array_map(fn($item) => (array) $item, $subjects);
        return $this;
    }

    public function getEmailBodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->emailTmpl['body'] ?? []);
    }

    public function setEmailBodies(array $bodies): self
    {
        $this->emailTmpl['body'] = array_map(fn($item) => (array) $item, $bodies);
        return $this;
    }

    public function getWhatsappLinkBodies(): array
    {
        return array_map(fn($item) => (object) $item, $this->whatsappLinkTmpl['body'] ?? []);
    }

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