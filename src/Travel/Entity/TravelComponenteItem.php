<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Travel\Enum\ItemModoEnum;
use Doctrine\ORM\Mapping as ORM;

/**
 * Define un ítem descriptivo o un sub-componente dentro de un Componente Logístico mayor.
 * Permite separar la narrativa del costo, permitiendo añadir servicios adicionales (Upsells).
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_componente_item')]
class TravelComponenteItem
{
    use IdTrait;
    use TimestampTrait;

    /**
     * El componente logístico padre al que pertenece este ítem (Ej: Paquete Machupicchu).
     */
    #[ORM\ManyToOne(targetEntity: TravelComponente::class, inversedBy: 'componenteItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelComponente $componente = null;

    /**
     * El término bilingüe del diccionario que se imprimirá en el PDF (Ej: "Boleto de Tren").
     */
    #[ORM\ManyToOne(targetEntity: TravelItemDiccionario::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?TravelItemDiccionario $diccionario = null;

    /**
     * Define si este ítem nace incluido por defecto, es opcional (Upsell) o no está incluido.
     */
    #[ORM\Column(type: 'string', length: 30, enumType: ItemModoEnum::class)]
    private ItemModoEnum $modo = ItemModoEnum::INCLUIDO;

    /**
     * Si el ítem es OPCIONAL o UPSELL, esta relación apunta al Componente Maestro
     * que contiene las tarifas reales (Adulto, Niño, etc.) para cobrar este extra.
     * Si es nulo, el ítem se considera puramente informativo (Inerte financieramente).
     */
    #[ORM\ManyToOne(targetEntity: TravelComponente::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TravelComponente $componenteAdicionalVinculado = null;

    /**
     * Posición visual dentro de la lista de ítems del componente.
     */
    #[ORM\Column(type: 'integer')]
    private int $orden = 1;

    public function __construct()
    {
        $this->initializeId();
    }

    /**
     * Representación en texto para EasyAdmin y depuración.
     * Genera una etiqueta visual atractiva que indica el estado del ítem.
     * Ejemplos: "✅ Guiado [INCLUIDO]" o "➕ Seguro Aventura [OPCIONAL] 💰"
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
            'OPCIONAL', 'UPSELL' => '➕',
            default => '▪️'
        };

        $etiqueta = sprintf('%s %s [%s]', $icono, $nombreItem, $modoNombre);

        // Si tiene un costo extra vinculado, le damos una pista visual al usuario
        if ($this->componenteAdicionalVinculado) {
            $etiqueta .= ' 💰 (Vinculado)';
        }

        return $etiqueta;
    }

    /**
     * Obtiene el componente logístico padre.
     */
    public function getComponente(): ?TravelComponente
    {
        return $this->componente;
    }

    /**
     * Establece el componente logístico padre.
     */
    public function setComponente(?TravelComponente $componente): self
    {
        $this->componente = $componente;
        return $this;
    }

    /**
     * Obtiene el objeto del diccionario para traducciones.
     */
    public function getDiccionario(): ?TravelItemDiccionario
    {
        return $this->diccionario;
    }

    /**
     * Establece el objeto del diccionario.
     */
    public function setDiccionario(?TravelItemDiccionario $diccionario): self
    {
        $this->diccionario = $diccionario;
        return $this;
    }

    /**
     * Obtiene el modo de inclusión (Enum).
     */
    public function getModo(): ItemModoEnum
    {
        return $this->modo;
    }

    /**
     * Establece el modo de inclusión.
     */
    public function setModo(ItemModoEnum $modo): self
    {
        $this->modo = $modo;
        return $this;
    }

    /**
     * Obtiene el componente logístico vinculado para cargos extra (Upsells).
     * Retorna NULL si el ítem no genera un costo independiente.
     */
    public function getComponenteAdicionalVinculado(): ?TravelComponente
    {
        return $this->componenteAdicionalVinculado;
    }

    /**
     * Vincula un componente logístico para gestionar el costeo de este ítem si es opcional.
     */
    public function setComponenteAdicionalVinculado(?TravelComponente $componenteAdicionalVinculado): self
    {
        $this->componenteAdicionalVinculado = $componenteAdicionalVinculado;
        return $this;
    }

    /**
     * Obtiene el orden de aparición.
     */
    public function getOrden(): int
    {
        return $this->orden;
    }

    /**
     * Establece el orden de aparición.
     */
    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }
}