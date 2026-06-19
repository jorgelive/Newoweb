<?php

declare(strict_types=1);

namespace App\Entity\Maestro;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty; // <--- Importado para solucionar el mapeo en Swagger
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
    shortName: 'Idioma',     // 🔥 Define el recurso base para generar la ruta '/idiomas'
    operations: [
        // 🔥 Declaración explícita del URI para evitar el colapso del HydraCollectionBaseSchema
        new Get(),
        new GetCollection()
    ], // 🔥 Agrupa las rutas bajo el módulo de catálogos maestros
    routePrefix: '/maestro',
    normalizationContext: ['groups' => ['pax:read']],

    // Orden por defecto de la colección:
    // Muestra primero los idiomas con mayor nivel de prioridad (ej. Español/Inglés)
    // y en caso de empate los ordena alfabéticamente por nombre.
    order: ['prioridad' => 'DESC', 'nombre' => 'ASC']
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
    #[ApiProperty(identifier: true)] // 🔥 Soluciona el error "key not found in object" en Swagger UI
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

    /**
     * Constructor de la clase MaestroIdioma.
     *
     * Inicializa un nuevo idioma con su código y nombre base. Garantiza por diseño
     * que el identificador del idioma se almacene siempre en minúsculas (ej. 'ES' -> 'es')
     * para mantener la uniformidad en las relaciones de Doctrine y las URLs de la API.
     *
     * @param string $id El código ISO de 2 letras del idioma (ej. 'es').
     * @param string $nombre El nombre descriptivo del idioma (ej. 'Español').
     */
    public function __construct(string $id, string $nombre)
    {
        $this->id = strtolower($id);
        $this->nombre = $nombre;
        $this->prioridad = 0;
    }

    // --- GETTERS & SETTERS ---

    /**
     * Obtiene el identificador único del idioma.
     *
     * Este método existe para proporcionar el código ISO de 2 letras que identifica unívocamente
     * al recurso. Es crítico porque API Platform lo requiere para resolver la URL individual
     * (ej. /maestro/idiomas/es) y para que Doctrine establezca las relaciones de llaves foráneas.
     *
     * @return string|null El código ISO del idioma o nulo si no ha sido instanciado correctamente.
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Obtiene el nombre del idioma.
     *
     * Permite a los clientes de la API y a las interfaces de usuario recuperar
     * el nombre legible del idioma para mostrarlo en selectores, tablas o reportes.
     *
     * @return string|null El nombre del idioma.
     */
    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    /**
     * Establece el nombre del idioma.
     *
     * Este método existe para permitir la hidratación de datos (cuando Doctrine lee de la DB)
     * y para permitir mutaciones (operaciones PUT/PATCH) en caso de que se requiera
     * corregir la ortografía de un idioma en el maestro.
     *
     * @param string $nombre El nombre legible a asignar.
     * @return self Devuelve la instancia actual para permitir interfaz fluida (chaining).
     */
    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    /**
     * Obtiene la bandera o emoji representativo del idioma.
     *
     * Útil para interfaces visuales (FrontEnd/App) que requieren renderizar de forma
     * rápida un apoyo visual asociado al idioma (ej. 🇪🇸).
     *
     * @return string|null La representación visual de la bandera o nulo si no está configurada.
     */
    public function getBandera(): ?string
    {
        return $this->bandera;
    }

    /**
     * Establece la bandera o emoji del idioma.
     *
     * Permite administrar y actualizar el identificador visual del idioma
     * desde un panel de administración o mediante la API.
     *
     * @param string|null $bandera El emoji o identificador visual.
     * @return self Devuelve la instancia actual para permitir interfaz fluida (chaining).
     */
    public function setBandera(?string $bandera): self
    {
        $this->bandera = $bandera;
        return $this;
    }

    /**
     * Obtiene el nivel de prioridad del idioma.
     *
     * Este método determina el peso del idioma para operaciones de ordenamiento.
     * Es consumido internamente por el `OrderFilter` de API Platform para garantizar
     * que idiomas clave (como español o inglés) aparezcan primero en las colecciones.
     *
     * @return int El valor numérico de la prioridad (valores mayores implican más prioridad).
     */
    public function getPrioridad(): int
    {
        return $this->prioridad;
    }

    /**
     * Establece el nivel de prioridad del idioma.
     *
     * Modifica el peso del idioma en el sistema. Un cambio en este valor alterará
     * directamente el orden en el que se devuelve la colección `GetCollection`
     * hacia el FrontEnd.
     *
     * @param int $prioridad Valor numérico de importancia.
     * @return self Devuelve la instancia actual para permitir interfaz fluida (chaining).
     */
    public function setPrioridad(int $prioridad): self
    {
        $this->prioridad = $prioridad;
        return $this;
    }

    /**
     * Representación en formato texto de la entidad.
     *
     * Este método mágico existe para proporcionar un "fallback" amigable cuando
     * Symfony, EasyAdmin o Twig intentan imprimir el objeto directamente como un string
     * (por ejemplo, en un menú desplegable `EntityType`).
     *
     * @return string La combinación legible de bandera y nombre.
     */
    public function __toString(): string
    {
        return ($this->bandera ? $this->bandera . ' ' : '') . $this->nombre;
    }

    // --- MÉTODOS ESTÁTICOS DE AYUDA ---

    /**
     * Ordena un arreglo de datos de idiomas basándose en la jerarquía del sistema.
     *
     * Este método existe para pre-procesar listas desordenadas de idiomas provenientes
     * de formularios o payloads JSON. Interviene antes de la persistencia para asegurar
     * que el arreglo cumpla visual y lógicamente con el estándar de negocio definido
     * en la constante estática `JERARQUIA`.
     *
     * @param array $data Arreglo donde cada ítem debe ser un array asociativo con la llave 'language'.
     * @throws \RuntimeException Si la estructura de los datos inyectados no posee el nodo 'language'.
     * @return array El arreglo reorganizado jerárquica y alfabéticamente.
     */
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

    /**
     * Valida la integridad estructural de un array de traducciones.
     *
     * Este método actúa como una barrera de seguridad estricta para los datos que
     * ingresan al sistema. Su propósito es prevenir excepciones a nivel de base de datos
     * y asegurar que la data persistida en campos JSON cumpla exactamente con el contrato
     * esperado ('language' y 'content').
     *
     * @param array $data El conjunto crudo de traducciones recibido en el payload.
     * @throws \InvalidArgumentException Si algún elemento carece de las propiedades obligatorias o está mal formateado.
     * @return array Un arreglo seguro, sanitizado y con sus llaves reindexadas secuencialmente.
     */
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