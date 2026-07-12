<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Travel\Enum\ItemModoEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Define un ítem descriptivo o un sub-componente dentro de un Componente Logístico mayor.
 * Permite separar la narrativa del costo, permitiendo añadir servicios adicionales (Upsells).
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_componente_item')]
#[ORM\HasLifecycleCallbacks]
class TravelComponenteItem
{
    use IdTrait;
    use TimestampTrait;

    // 🚫 CORTE CIRCULAR
    #[ORM\ManyToOne(targetEntity: TravelComponente::class, inversedBy: 'componenteItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelComponente $componente = null;

    #[Assert\NotNull(message: 'Debes seleccionar un ítem del diccionario.')]
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\ManyToOne(targetEntity: TravelItemDiccionario::class, inversedBy: 'componenteItems')]
    #[ORM\JoinColumn(nullable: false)]
    private ?TravelItemDiccionario $diccionario = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: ItemModoEnum::class)]
    private ItemModoEnum $modo = ItemModoEnum::INCLUIDO;

    /**
     * API Platform Truco: readableLink false para que devuelva IRI y corte recursividad en VUE.
     */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TravelComponente::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TravelComponente $componenteAdicionalVinculado = null;

    #[Assert\PositiveOrZero]
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'integer')]
    private int $orden = 1;

    /**
     * Controla si el título de la tarifa asociada a este ítem es visible públicamente.
     */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $tituloTarifaVisible = false;

    /**
     * Controla si la categoría de la tarifa asociada a este ítem es visible públicamente.
     */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $categoriaTarifaVisible = false;

    /**
     * Controla si la modalidad de la tarifa asociada a este ítem es visible públicamente.
     */
    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $modalidadTarifaVisible = false;

    public function __construct()
    {
        $this->initializeId();
    }

    /**
     * Representación en texto para EasyAdmin y depuración.
     * Genera una etiqueta visual atractiva que indica el estado del ítem.
     * Ejemplos: "✅ Guiado [INCLUIDO]" o "➕ Seguro Aventura [OPCIONAL] 💰"
     *
     * @return string
     */
    public function __toString(): string
    {
        if (!$this->diccionario) {
            return '✨ Nuevo Ítem';
        }

        $nombreItem = (string) $this->diccionario;
        $modoNombre = $this->modo->name;

        // Asignamos un ícono visual dependiendo del modo para facilitar la lectura rápida
        $icono = match ($modoNombre) {
            'INCLUIDO' => '✅',
            'NO_INCLUIDO' => '❌',
            'OPCIONAL' => '➕',
            default => '▪️'
        };

        $etiqueta = sprintf('%s %s [%s]', $icono, $nombreItem, $modoNombre);

        // Si tiene un costo extra vinculado, le damos una pista visual al usuario
        if ($this->componenteAdicionalVinculado) {
            $etiqueta .= ' 💰 (Vinculado)';
        }

        return $etiqueta;
    }

    public function __clone()
    {
        $this->resetId();
        $this->resetTimestamps();
    }

    /**
     * Valida la coherencia entre el modo del ítem y el componente adicional vinculado.
     * - OPCIONAL requieren un componente vinculado para poder costearse.
     * - Cualquier otro modo NO debe tener un componente vinculado (evita tarifas fantasma).
     */
    #[Assert\Callback]
    public function validateVinculacionCosto(ExecutionContextInterface $context): void
    {
        $esOpcionalOUpsell = in_array($this->modo, [
            ItemModoEnum::OPCIONAL
        ], true);

        if ($esOpcionalOUpsell && !$this->componenteAdicionalVinculado) {
            $context->buildViolation('Los ítems opcionales o upsell deben vincular un componente adicional para el costeo.')
                ->atPath('componenteAdicionalVinculado')
                ->addViolation();
        }

        if (!$esOpcionalOUpsell && $this->componenteAdicionalVinculado) {
            $context->buildViolation('Solo los ítems en modo OPCIONAL pueden tener un componente adicional vinculado.')
                ->atPath('componenteAdicionalVinculado')
                ->addViolation();
        }
    }

    /**
     * Obtiene el componente logístico padre.
     *
     * @return TravelComponente|null
     */
    public function getComponente(): ?TravelComponente
    {
        return $this->componente;
    }

    /**
     * Establece el componente logístico padre.
     *
     * @param TravelComponente|null $componente
     * @return self
     */
    public function setComponente(?TravelComponente $componente): self
    {
        $this->componente = $componente;
        return $this;
    }

    /**
     * Obtiene el objeto del diccionario para traducciones.
     *
     * @return TravelItemDiccionario|null
     */
    public function getDiccionario(): ?TravelItemDiccionario
    {
        return $this->diccionario;
    }

    /**
     * Establece el objeto del diccionario.
     *
     * @param TravelItemDiccionario|null $diccionario
     * @return self
     */
    public function setDiccionario(?TravelItemDiccionario $diccionario): self
    {
        $this->diccionario = $diccionario;
        return $this;
    }

    /**
     * Obtiene la modalidad comercial del ítem descriptivo.
     *
     * @return ItemModoEnum
     */
    public function getModo(): ItemModoEnum
    {
        return $this->modo;
    }

    /**
     * Establece la modalidad comercial del ítem descriptivo.
     *
     * @param ItemModoEnum $modo
     * @return self
     */
    public function setModo(ItemModoEnum $modo): self
    {
        $this->modo = $modo;
        return $this;
    }

    /**
     * Obtiene el componente logístico vinculado para cargos extra (Upsells).
     * Retorna NULL si el ítem no genera un costo independiente.
     *
     * @return TravelComponente|null
     */
    public function getComponenteAdicionalVinculado(): ?TravelComponente
    {
        return $this->componenteAdicionalVinculado;
    }

    /**
     * Vincula un componente logístico para gestionar el costeo de este ítem si es opcional.
     *
     * @param TravelComponente|null $componenteAdicionalVinculado
     * @return self
     */
    public function setComponenteAdicionalVinculado(?TravelComponente $componenteAdicionalVinculado): self
    {
        $this->componenteAdicionalVinculado = $componenteAdicionalVinculado;
        return $this;
    }

    /**
     * Obtiene el orden de aparición.
     *
     * @return int
     */
    public function getOrden(): int
    {
        return $this->orden;
    }

    /**
     * Establece el orden de aparición.
     *
     * @param int $orden
     * @return self
     */
    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }

    /**
     * Determina si el título de la tarifa vinculada se debe mostrar al cliente final.
     *
     * @return bool
     */
    public function isTituloTarifaVisible(): bool
    {
        return $this->tituloTarifaVisible;
    }

    /**
     * Establece si el título de la tarifa vinculada se debe mostrar al cliente final.
     *
     * @param bool $tituloTarifaVisible
     * @return self
     */
    public function setTituloTarifaVisible(bool $tituloTarifaVisible): self
    {
        $this->tituloTarifaVisible = $tituloTarifaVisible;
        return $this;
    }

    /**
     * Determina si la categoría de la tarifa vinculada se debe mostrar al cliente final.
     *
     * @return bool
     */
    public function isCategoriaTarifaVisible(): bool
    {
        return $this->categoriaTarifaVisible;
    }

    /**
     * Establece si la categoría de la tarifa vinculada se debe mostrar al cliente final.
     *
     * @param bool $categoriaTarifaVisible
     * @return self
     */
    public function setCategoriaTarifaVisible(bool $categoriaTarifaVisible): self
    {
        $this->categoriaTarifaVisible = $categoriaTarifaVisible;
        return $this;
    }

    /**
     * Determina si la modalidad de la tarifa vinculada se debe mostrar al cliente final.
     *
     * @return bool
     */
    public function isModalidadTarifaVisible(): bool
    {
        return $this->modalidadTarifaVisible;
    }

    /**
     * Establece si la modalidad de la tarifa vinculada se debe mostrar al cliente final.
     *
     * @param bool $modalidadTarifaVisible
     * @return self
     */
    public function setModalidadTarifaVisible(bool $modalidadTarifaVisible): self
    {
        $this->modalidadTarifaVisible = $modalidadTarifaVisible;
        return $this;
    }
}