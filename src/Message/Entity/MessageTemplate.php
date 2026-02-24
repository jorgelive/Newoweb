<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'msg_template')]
#[ORM\HasLifecycleCallbacks]
class MessageTemplate
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    // 👈 Control de traducción automática y manual

    // Constantes para mapeo en MessageChannel
    public const string FIELD_EMAIL = 'emailTmpl';
    public const string FIELD_BEDS24 = 'beds24Tmpl';
    public const string FIELD_GUPSHUP = 'whatsappGupshupTmpl';
    public const string FIELD_WHATSAPP_LINK = 'whatsappLinkTmpl';
    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    private ?string $code = null;
    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private ?string $name = null;
    #[ORM\Column(type: 'json')]
    private array $parameters = [];
    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['subject', 'body'])] // 👈 Traduce asunto y cuerpo HTML
    private ?array $emailTmpl = [];

    // =========================================================================
    // CONFIGURACIONES POR CANAL (Campos JSON con Auto-Traducción)
    // =========================================================================
    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['body'])] // 👈 Traduce el cuerpo de la OTA
    private ?array $beds24Tmpl = [];
    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['text_reference'])] // 👈 Traduce solo la referencia visual
    private ?array $whatsappGupshupTmpl = [];
    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['body'])] // 👈 Traduce el texto del link manual
    private ?array $whatsappLinkTmpl = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->emailTmpl = [];
        $this->beds24Tmpl = [];
        $this->whatsappGupshupTmpl = [];
        $this->whatsappLinkTmpl = [];
    }

    public static function getTemplateFields(): array
    {
        return [self::FIELD_EMAIL, self::FIELD_BEDS24, self::FIELD_GUPSHUP, self::FIELD_WHATSAPP_LINK];
    }

    public function __toString(): string
    {
        return $this->name ?? $this->code ?? 'New Template';
    }

    // =========================================================================
    // GETTERS Y SETTERS EXPLÍCITOS
    // =========================================================================

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

    public function getWhatsappGupshupTmpl(): ?array
    {
        return $this->whatsappGupshupTmpl;
    }

    public function setWhatsappGupshupTmpl(?array $val): self
    {
        $this->whatsappGupshupTmpl = $val;
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

    // =========================================================================
    // HELPERS DE LÓGICA DE NEGOCIO (Extracción tipo MongoDB)
    // =========================================================================

    // --- INTERRUPTORES (Switches de Encendido/Apagado) ---
    public function isEmailActive(): bool
    {
        return ($this->emailTmpl['is_active'] ?? false) === true;
    }

    public function isBeds24Active(): bool
    {
        return ($this->beds24Tmpl['is_active'] ?? false) === true;
    }

    public function isWhatsappGupshupActive(): bool
    {
        return ($this->whatsappGupshupTmpl['is_active'] ?? false) === true;
    }

    // --- GETTERS EMAIL ---
    public function getEmailSubject(string $lang): ?string
    {
        return $this->extract($this->emailTmpl['subject'] ?? [], $lang, 'content');
    }

    /**
     * Motor de extracción segura para arrays de traducciones.
     * Prioridad: 1. Idioma Exacto -> 2. Inglés ('en') -> 3. El primero de la lista.
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

    // --- GETTERS BEDS24 ---

    public function getEmailBody(string $lang): ?string
    {
        return $this->extract($this->emailTmpl['body'] ?? [], $lang, 'content');
    }

    // --- GETTERS WHATSAPP GUPSHUP (META) ---

    public function getBeds24Body(string $lang): ?string
    {
        return $this->extract($this->beds24Tmpl['body'] ?? [], $lang, 'content');
    }

    public function getWhatsappGupshupMetaName(): ?string
    {
        return $this->whatsappGupshupTmpl['meta_template_name'] ?? null;
    }

    public function getWhatsappGupshupCategory(): ?string
    {
        return $this->whatsappGupshupTmpl['category'] ?? null;
    }

    public function getWhatsappGupshupParamsMap(): array
    {
        return $this->whatsappGupshupTmpl['params_map'] ?? [];
    }

    public function getWhatsappGupshupTemplateId(string $lang): ?string
    {
        return $this->extract($this->whatsappGupshupTmpl['language_mapping'] ?? [], $lang, 'meta_template_id');
    }

    // --- GETTERS WHATSAPP LINK MANUAL ---

    public function getWhatsappGupshupTextReference(string $lang): ?string
    {
        return $this->extract($this->whatsappGupshupTmpl['text_reference'] ?? [], $lang, 'content');
    }

    public function getWhatsappLinkBody(string $lang): ?string
    {
        return $this->extract($this->whatsappLinkTmpl['body'] ?? [], $lang, 'content');
    }
}