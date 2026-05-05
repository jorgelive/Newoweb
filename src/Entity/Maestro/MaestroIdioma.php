<?php

declare(strict_types=1);

namespace App\Entity\Maestro;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter; // <--- Necesario para el filtro > 0
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Egulias\EmailValidator\Parser\IDLeftPart;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'maestro_idioma')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/public/maestro_idioma/{id}'
        ),
        new GetCollection(
            uriTemplate: '/public/maestro_idioma'
        )
    ],
    normalizationContext: ['groups' => ['pax:read']],
    order: ['prioridad' => 'DESC', 'nombre' => 'ASC'] // Orden por defecto
)]
#[ApiFilter(RangeFilter::class, properties: ['prioridad'])] // Permite ?prioridad[gt]=0
#[ApiFilter(OrderFilter::class, properties: ['prioridad', 'nombre'])] // Permite reordenar desde la URL
class MaestroIdioma
{

    public const ID_ESPANOL = 'es';
    public const ID_INGLES = 'en';
    public const ID_PORTUGUES = 'pt';
    public const ID_FRANCES = 'fr';
    public const ID_ALEMAN = 'de';
    public const ID_ITALIANO = 'it';

    public const DEFAULT_IDIOMA = self::ID_INGLES;

    public const JERARQUIA  = [
        self::ID_ESPANOL => 100,
        self::ID_INGLES => 90,
        self::ID_PORTUGUES => 80,
        self::ID_FRANCES => 70,
        self::ID_ALEMAN => 60,
        self::ID_ITALIANO => 50
    ];

    use TimestampTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 2)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[Groups(['pax:read', 'file:read', 'file:item:read'])]
    private ?string $id = null;

    #[ORM\Column(type: 'string', length: 50)]
    #[Groups(['pax:read', 'file:read', 'file:item:read'])]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    #[Groups(['pax:read', 'file:read', 'file:item:read'])]
    private ?string $bandera = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['pax:read', 'file:read', 'file:item:read'])]
    private int $prioridad = 0;

    public function __construct(string $id, string $nombre)
    {
        $this->id = strtolower($id);
        $this->nombre = $nombre;
        $this->prioridad = 0;
    }

    // --- GETTERS & SETTERS ---

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getBandera(): ?string
    {
        return $this->bandera;
    }

    public function setBandera(?string $bandera): self
    {
        $this->bandera = $bandera;
        return $this;
    }

    public function getPrioridad(): int
    {
        return $this->prioridad;
    }

    public function setPrioridad(int $prioridad): self
    {
        $this->prioridad = $prioridad;
        return $this;
    }

    public function __toString(): string
    {
        return ($this->bandera ? $this->bandera . ' ' : '') . $this->nombre;
    }

    // --- MÉTODOS ESTÁTICOS DE AYUDA ---

    public static function ordenarParaFormulario(array $data): array
    {
        if (empty($data)) {
            return $data;
        }

        // Ordenamos el array de objetos usando la jerarquía
        usort($data, function ($a, $b) {

            if (!is_array($a) || !isset($a['language'])) {
                throw new \RuntimeException(sprintf(
                    "Error de formato en base de datos: Se esperaba un array con la llave 'language', pero se recibió: %s",
                    json_encode($a)
                ));
            }

            // Validamos $b
            if (!is_array($b) || !isset($b['language'])) {
                throw new \RuntimeException(sprintf(
                    "Error de formato en base de datos: Se esperaba un array con la llave 'language', pero se recibió: %s",
                    json_encode($b)
                ));
            }
            $pA = self::JERARQUIA[$a['language']] ?? 0;
            $pB = self::JERARQUIA[$b['language']] ?? 0;

            // Si tienen misma prioridad, orden alfabético por código de idioma
            return ($pA === $pB)
                ? strcmp($a['language'], $b['language'])
                : ($pB <=> $pA);
        });

        return $data;
    }
    public static function normalizarParaDB(array $data): array
    {
        foreach ($data as $index => $item) {
            // Si no es array, o faltan las llaves exactas, o sobran llaves extrañas... ¡BOOM!
            if (
                !is_array($item) ||
                !array_key_exists('language', $item) ||
                !array_key_exists('content', $item)
            ) {
                throw new \InvalidArgumentException(
                    "Estructura de traducción inválida en el índice $index. Se requiere exactamente 'language' y 'content'."
                );
            }
        }

        return array_values($data);
    }
}