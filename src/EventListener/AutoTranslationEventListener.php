<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Service\GoogleTranslateService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class AutoTranslationEventListener
{
    /** @var string[] lowercased */
    private array $validLanguageCodes = [];

    public function __construct(
        private readonly GoogleTranslateService $translator,
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->process($args->getObject(), null);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->process($args->getObject(), $args);
    }

    private function process(object $entity, ?PreUpdateEventArgs $updateArgs): void
    {
        if (method_exists($entity, 'getEjecutarTraduccion') && !$entity->getEjecutarTraduccion()) {
            return;
        }

        if (empty($this->validLanguageCodes)) {
            $this->loadValidLanguages();
        }

        $overwrite = method_exists($entity, 'getSobreescribirTraduccion')
            ? (bool) $entity->getSobreescribirTraduccion()
            : false;

        $reflection = new ReflectionClass($entity);
        $hasEntityChanges = false;

        foreach ($reflection->getProperties() as $property) {
            $attr = $this->getAutoTranslateAttribute($property);
            if ($attr === null) {
                continue;
            }

            $propertyName = $property->getName();
            $getter = 'get' . ucfirst($propertyName);
            $setter = 'set' . ucfirst($propertyName);

            if (!method_exists($entity, $getter) || !method_exists($entity, $setter)) {
                throw new RuntimeException(sprintf(
                    'La propiedad "%s" tiene AutoTranslate pero faltan getter/setter.',
                    $propertyName
                ));
            }

            $originalValue = $entity->$getter();
            if (empty($originalValue)) {
                continue;
            }

            $sourceLang = strtolower($attr->sourceLanguage);
            $nestedFields = $attr->nestedFields; // ej: ['ubicacion', 'titulo']
            $mimeType = $attr->getFormat();

            // =========================
            // CASO A: ESTRUCTURA COMPLEJA (nestedFields)
            // =========================
            if (!empty($nestedFields)) {
                if (!is_array($originalValue) || !array_is_list($originalValue)) {
                    throw new RuntimeException(sprintf(
                        'El campo "%s" tiene nestedFields, por lo que debe ser una LISTA de objetos (array_is_list=true). Tipo encontrado: %s',
                        $propertyName,
                        gettype($originalValue)
                    ));
                }

                $newValue = $this->processNestedList(
                    $originalValue,
                    $nestedFields,
                    $sourceLang,
                    $mimeType,
                    $overwrite,
                    $propertyName
                );

                if ($newValue !== $originalValue) {
                    $entity->$setter($newValue);
                    $hasEntityChanges = true;
                }

                continue;
            }

            // =========================
            // CASO B: ESTRUCTURA PLANA (lista de traducciones)
            // =========================
            if (!is_array($originalValue) || !array_is_list($originalValue)) {
                throw new RuntimeException(sprintf(
                    'El campo "%s" sin nestedFields debe ser una LISTA de objetos [{language, content, ...}]. Tipo encontrado: %s',
                    $propertyName,
                    gettype($originalValue)
                ));
            }

            $valuesMap = $this->listToMapRows($originalValue, $propertyName); // lang => rowCompleta
            $translatedMap = $this->translateAndCloneRows($valuesMap, $sourceLang, $mimeType, $overwrite);

            $finalValue = $this->mapRowsToList($translatedMap);

            if ($finalValue !== $originalValue) {
                $entity->$setter($finalValue);
                $hasEntityChanges = true;
            }
        }

        // Doctrine: en preUpdate hay que recomputar changeset si cambiamos el entity
        if ($hasEntityChanges && $updateArgs !== null) {
            $em = $updateArgs->getObjectManager();
            $meta = $em->getClassMetadata($entity::class);
            $em->getUnitOfWork()->recomputeSingleEntityChangeSet($meta, $entity);
        }
    }

    /**
     * Procesa una lista de objetos (ej: wifiNetworks) y traduce las claves definidas en nestedFields.
     * Ej: nestedFields=['ubicacion'] => $item['ubicacion'] puede ser string o lista [{language,content}]
     */
    private function processNestedList(
        array $list,
        array $targetKeys,
        string $sourceLang,
        string $mimeType,
        bool $overwrite,
        string $propName
    ): array {
        $hasChanges = false;

        foreach ($list as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException(sprintf(
                    'Error en "%s": Se esperaba un objeto (array) en índice %d, se encontró %s.',
                    $propName,
                    $index,
                    gettype($item)
                ));
            }

            foreach ($targetKeys as $key) {
                if (!array_key_exists($key, $item)) {
                    throw new RuntimeException(sprintf(
                        'Error crítico en "%s": Se configuró nestedFields para la clave "%s", pero el objeto en índice %d no la tiene. Claves encontradas: %s',
                        $propName,
                        $key,
                        $index,
                        implode(', ', array_keys($item))
                    ));
                }

                $fieldValue = $item[$key];

                // Normaliza a mapa de rows (lang => rowCompleta)
                $fieldMap = $this->normalizeNestedFieldToRowMap($fieldValue, $sourceLang, $propName . '.' . $key);
                $translatedMap = $this->translateAndCloneRows($fieldMap, $sourceLang, $mimeType, $overwrite);
                $finalFieldList = $this->mapRowsToList($translatedMap);

                if ($finalFieldList !== $fieldValue) {
                    $list[$index][$key] = $finalFieldList;
                    $hasChanges = true;
                }
            }
        }

        return $hasChanges ? $list : $list;
    }

    /**
     * Traduce faltantes y clona desde el objeto origen (preservando llaves extra).
     * Entrada/salida: lang(lower) => rowCompleta ['language'=>..,'content'=>.., ...]
     *
     * - Poda idiomas no prioritarios
     * - Protege el idioma origen
     * - Traduce a todos los prioritarios
     */
    private function translateAndCloneRows(array $valuesMap, string $sourceLang, string $mimeType, bool $overwrite): array
    {
        $sourceLangNorm = strtolower($sourceLang);

        // 1) PODA (pero protegemos el origen)
        $cleanMap = [];
        foreach ($valuesMap as $lang => $row) {
            $langNorm = strtolower((string) $lang);

            if ($langNorm === $sourceLangNorm) {
                $cleanMap[$langNorm] = $row;
                continue;
            }

            if (in_array($langNorm, $this->validLanguageCodes, true)) {
                $cleanMap[$langNorm] = $row;
            }
        }
        $valuesMap = $cleanMap;

        // 2) Origen
        $sourceRow = $valuesMap[$sourceLangNorm] ?? null;
        if (!is_array($sourceRow) || empty($sourceRow['content']) || !is_string($sourceRow['content'])) {
            return $valuesMap;
        }

        $sourceText = $sourceRow['content'];
        $hasChanged = false;

        // 3) Traducción + clonación
        foreach ($this->validLanguageCodes as $targetCode) {
            if ($targetCode === $sourceLangNorm) {
                continue;
            }

            if (!$overwrite && isset($valuesMap[$targetCode])) {
                continue;
            }

            try {
                $res = $this->translator->translate($sourceText, $targetCode, $sourceLangNorm, $mimeType);

                if (!empty($res[0]) && is_string($res[0])) {
                    $valuesMap[$targetCode] = array_merge($sourceRow, [
                        'language' => $targetCode,
                        'content'  => $res[0],
                    ]);
                    $hasChanged = true;
                }
            } catch (\Throwable) {
                // silencioso: no rompemos persist/update por un fallo puntual del traductor
                continue;
            }
        }

        return $hasChanged ? $valuesMap : $valuesMap;
    }

    /**
     * Convierte lista [{language, content, ...}] a mapa lang(lower) => rowCompleta
     */
    private function listToMapRows(mixed $values, string $propName): array
    {
        if (!is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $index => $row) {
            if (!is_array($row) || !isset($row['language'], $row['content'])) {
                throw new RuntimeException(sprintf(
                    'Estructura inválida en "%s" índice %d. Se requiere array con "language" y "content".',
                    $propName,
                    $index
                ));
            }

            $lang = strtolower((string) $row['language']);
            $out[$lang] = $row;
        }

        return $out;
    }

    /**
     * Convierte mapa lang => rowCompleta a lista (para JSON)
     */
    private function mapRowsToList(array $map): array
    {
        return array_values($map);
    }

    /**
     * Normaliza un campo nested:
     * - si viene string => lo convierte a mapa con el idioma origen
     * - si viene lista [{language,content,...}] => la convierte a mapa
     */
    private function normalizeNestedFieldToRowMap(mixed $value, string $sourceLang, string $propName): array
    {
        $sourceLangNorm = strtolower($sourceLang);

        if (is_string($value)) {
            return [
                $sourceLangNorm => [
                    'language' => $sourceLangNorm,
                    'content'  => $value,
                ],
            ];
        }

        if (is_array($value) && array_is_list($value)) {
            return $this->listToMapRows($value, $propName);
        }

        // Si es null u otro tipo, lo tratamos como “no traducible”
        return [];
    }

    private function loadValidLanguages(): void
    {
        $idiomas = $this->entityManager->getRepository(MaestroIdioma::class)
            ->createQueryBuilder('i')
            ->where('i.prioridad > 0')
            ->orderBy('i.prioridad', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($idiomas as $idioma) {
            $this->validLanguageCodes[] = strtolower((string) $idioma->getId());
        }

        // Si por alguna razón DB viene vacía, al menos protegemos 'es'
        $this->validLanguageCodes = array_values(array_unique($this->validLanguageCodes));
        if (!in_array('es', $this->validLanguageCodes, true)) {
            $this->validLanguageCodes[] = 'es';
        }
    }

    private function getAutoTranslateAttribute(ReflectionProperty $property): ?AutoTranslate
    {
        $attributes = $property->getAttributes(AutoTranslate::class);
        return isset($attributes[0]) ? $attributes[0]->newInstance() : null;
    }
}