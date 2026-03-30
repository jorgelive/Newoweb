<?php

declare(strict_types=1);

namespace App\Agent\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Entidad que representa una regla del motor determinista de Auto-Respuesta.
 * Mapea un disparador (trigger) exacto proveniente de un canal (ej. Meta)
 * hacia una acción específica (ej. enviar plantilla, bloquear canal).
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_autoresponder_rule')]
class AutoResponderRule
{
    use IdTrait;

    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 50)]
    private string $triggerValue;

    #[ORM\Column(type: 'string', length: 50)]
    private string $actionType;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $actionParameters = [];

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->actionParameters = [];
    }

    /**
     * Obtiene el ID único de la regla.
     */
    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    /**
     * Obtiene el valor exacto que dispara esta regla.
     */
    public function getTriggerValue(): string
    {
        return $this->triggerValue;
    }

    /**
     * Establece el valor exacto que disparará esta regla (ej. BTN_WIFI, ERR_131049).
     */
    public function setTriggerValue(string $triggerValue): self
    {
        $this->triggerValue = $triggerValue;
        return $this;
    }

    /**
     * Obtiene el identificador interno de la acción a ejecutar.
     */
    public function getActionType(): string
    {
        return $this->actionType;
    }

    /**
     * Establece el identificador de la acción (debe coincidir con getActionKey() de un Handler).
     */
    public function setActionType(string $actionType): self
    {
        $this->actionType = $actionType;
        return $this;
    }

    /**
     * Obtiene los parámetros dinámicos de la acción en formato Array nativo de PHP.
     * Usado internamente por el Router y los Handlers para ejecutar la lógica.
     */
    public function getActionParameters(): ?array
    {
        return $this->actionParameters;
    }

    /**
     * Establece los parámetros dinámicos de la acción desde un Array nativo de PHP.
     */
    public function setActionParameters(?array $actionParameters): self
    {
        $this->actionParameters = $actionParameters;
        return $this;
    }

    /**
     * Indica si la regla está activa y debe ser evaluada por el IntentRouter.
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Activa o desactiva la regla.
     */
    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    // =========================================================================
    // PROPIEDADES VIRTUALES PARA EASYADMIN (TRANSFORMACIÓN DE DATOS)
    // =========================================================================

    /**
     * Propiedad virtual utilizada exclusivamente por EasyAdmin para mostrar el JSON
     * como un string formateado dentro del CodeEditorField en las vistas de edición.
     * * @return string El JSON formateado con sangría, o una plantilla vacía por defecto.
     */
    public function getActionParametersJson(): string
    {
        if (empty($this->actionParameters)) {
            return "{\n    \n}";
        }

        return json_encode($this->actionParameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: "{\n    \n}";
    }

    /**
     * Propiedad virtual utilizada exclusivamente por EasyAdmin para recibir el string
     * proveniente del CodeEditorField al enviar el formulario (Submit) y convertirlo
     * de vuelta a un Array nativo antes de que Doctrine lo persista en la base de datos.
     * * @param string|null $json El string capturado desde el formulario.
     */
    public function setActionParametersJson(?string $json): self
    {
        if (!$json || trim($json) === '') {
            $this->actionParameters = [];
            return $this;
        }

        $decoded = json_decode($json, true);

        // Si el JSON es válido, lo asignamos. Si hay un error de sintaxis,
        // fallback a array vacío para evitar que Doctrine lance una excepción fatal.
        $this->actionParameters = is_array($decoded) ? $decoded : [];

        return $this;
    }
}