<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'message_template')]
#[ORM\HasLifecycleCallbacks]
class MessageTemplate
{
    use IdTrait;
    use TimestampTrait;

    // Constantes para mapeo en MessageChannel
    public const string FIELD_EMAIL = 'emailTmpl';
    public const string FIELD_BEDS24 = 'beds24Tmpl';
    public const string FIELD_GUPSHUP = 'gupshupTmpl';
    public const string FIELD_WHATSAPP_LINK = 'whatsappLinkTmpl';

    public static function getTemplateFields(): array
    {
        return [self::FIELD_EMAIL, self::FIELD_BEDS24, self::FIELD_GUPSHUP, self::FIELD_WHATSAPP_LINK];
    }

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    private ?string $code = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: 'json')]
    private array $parameters = [];

    // =========================================================================
    // CONFIGURACIONES POR CANAL (JSON Fields)
    // =========================================================================

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $emailTmpl = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $beds24Tmpl = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $gupshupTmpl = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $whatsappLinkTmpl = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
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
        // Lógica de normalización (antes en el Hook)
        $this->code = strtoupper(trim($code));
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

    // --- Getters/Setters para los campos JSON ---

    public function getEmailTmpl(): ?array
    {
        return $this->emailTmpl;
    }

    public function setEmailTmpl(?array $emailTmpl): self
    {
        $this->emailTmpl = $emailTmpl;
        return $this;
    }

    public function getBeds24Tmpl(): ?array
    {
        return $this->beds24Tmpl;
    }

    public function setBeds24Tmpl(?array $beds24Tmpl): self
    {
        $this->beds24Tmpl = $beds24Tmpl;
        return $this;
    }

    public function getGupshupTmpl(): ?array
    {
        return $this->gupshupTmpl;
    }

    public function setGupshupTmpl(?array $gupshupTmpl): self
    {
        $this->gupshupTmpl = $gupshupTmpl;
        return $this;
    }

    public function getWhatsappLinkTmpl(): ?array
    {
        return $this->whatsappLinkTmpl;
    }

    public function setWhatsappLinkTmpl(?array $whatsappLinkTmpl): self
    {
        $this->whatsappLinkTmpl = $whatsappLinkTmpl;
        return $this;
    }

    // =========================================================================
    // HELPERS DE LÓGICA DE NEGOCIO (Refactorizados sin array_find)
    // =========================================================================

    public function getEmailSubject(string $lang): ?string
    {
        return $this->extract($this->emailTmpl['subject'] ?? [], $lang);
    }

    public function getEmailBody(string $lang): ?string
    {
        return $this->extract($this->emailTmpl['body'] ?? [], $lang);
    }

    public function getBeds24Text(string $lang): ?string
    {
        return $this->extract($this->beds24Tmpl['text'] ?? [], $lang);
    }

    public function getGupshupTemplateId(string $lang): ?string
    {
        return $this->extract($this->gupshupTmpl['template_ids'] ?? [], $lang, 'id');
    }

    public function isGupshupTemplate(): bool
    {
        return ($this->gupshupTmpl['is_template'] ?? false) === true;
    }

    public function getGupshupParamsMap(): array
    {
        return $this->gupshupTmpl['params_map'] ?? [];
    }

    /**
     * Lógica de extracción segura (Reemplaza array_find para compatibilidad)
     * Prioridad: 1. Idioma Exacto -> 2. Inglés ('en') -> 3. El primero de la lista
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

            // 1. Coincidencia exacta
            if ($itemLang === $lang) {
                $foundItem = $item;
                break;
            }

            // 2. Guardamos inglés por si acaso
            if ($itemLang === 'en') {
                $englishItem = $item;
            }
        }

        // Selección final
        $finalItem = $foundItem ?? $englishItem ?? ($list[0] ?? null);

        return $finalItem[$key] ?? null;
    }
}