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

    public static function getTemplateFields(): array
    {
        return [self::FIELD_EMAIL, self::FIELD_BEDS24, self::FIELD_WHATSAPP_META, self::FIELD_WHATSAPP_LINK];
    }

    public function __toString(): string
    {
        return $this->name ?? $this->code ?? 'New Template';
    }

    #[Groups(['template:read'])]
    public function getId(): UuidV7 { return $this->id; }

    public function getCode(): ?string { return $this->code; }
    public function setCode(string $code): self { $this->code = trim($code); return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getParameters(): array { return $this->parameters; }
    public function setParameters(array $parameters): self { $this->parameters = $parameters; return $this; }

    public function getContextType(): ?string { return $this->contextType; }
    public function setContextType(?string $contextType): self { $this->contextType = $contextType; return $this; }

    public function getAllowedSources(): array { return $this->allowedSources ?? []; }
    public function setAllowedSources(?array $allowedSources): self { $this->allowedSources = $allowedSources; return $this; }

    public function getAllowedAgencies(): array { return $this->allowedAgencies ?? []; }
    public function setAllowedAgencies(?array $allowedAgencies): self { $this->allowedAgencies = $allowedAgencies; return $this; }

    public function getEmailTmpl(): ?array { return $this->emailTmpl; }
    public function setEmailTmpl(?array $val): self { $this->emailTmpl = $val; return $this; }

    public function getBeds24Tmpl(): ?array { return $this->beds24Tmpl; }
    public function setBeds24Tmpl(?array $val): self { $this->beds24Tmpl = $val; return $this; }

    public function getWhatsappMetaTmpl(): ?array { return $this->whatsappMetaTmpl; }
    public function setWhatsappMetaTmpl(?array $val): self { $this->whatsappMetaTmpl = $val; return $this; }

    public function getWhatsappLinkTmpl(): ?array { return $this->whatsappLinkTmpl; }
    public function setWhatsappLinkTmpl(?array $val): self { $this->whatsappLinkTmpl = $val; return $this; }

    public function isEmailActive(): bool { return ($this->emailTmpl['is_active'] ?? false) === true; }
    public function isBeds24Active(): bool { return ($this->beds24Tmpl['is_active'] ?? false) === true; }
    public function isWhatsappMetaActive(): bool { return ($this->whatsappMetaTmpl['is_active'] ?? false) === true; }

    /**
     * Extrae los canales de comunicación activos configurados en la plantilla.
     * Este método se expone en la API mediante el grupo de serialización para que el
     * frontend pueda determinar dinámicamente qué canales seleccionar en el selector omnicanal.
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

    public function getWhatsappMetaName(): ?string
    {
        return $this->whatsappMetaTmpl['whatsapp_meta_template_name'] ?? null;
    }

    public function getWhatsappMetaCategory(): ?string
    {
        return $this->whatsappMetaTmpl['category'] ?? null;
    }

    public function getWhatsappMetaParamsMap(): array
    {
        return $this->whatsappMetaTmpl['params_map'] ?? [];
    }

    public function getWhatsappMetaTemplateId(string $lang): ?string
    {
        return $this->extract($this->whatsappMetaTmpl['language_mapping'] ?? [], $lang, 'whatsapp_meta_template_id');
    }

    public function hasWhatsappMetaOfficialData(string $lang): bool
    {
        return $this->getWhatsappMetaTemplateId($lang) !== null;
    }

    public function getWhatsappMetaBody(string $lang): ?string
    {
        return $this->extract($this->whatsappMetaTmpl['body'] ?? [], $lang, 'content');
    }

    public function getWhatsappLinkBody(string $lang): ?string
    {
        return $this->extract($this->whatsappLinkTmpl['body'] ?? [], $lang, 'content');
    }

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