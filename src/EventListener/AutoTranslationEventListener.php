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
            if ($attr === null) continue;

            $propertyName = $property->getName();
            $getter = 'get' . ucfirst($propertyName);
            $setter = 'set' . ucfirst($propertyName);

            if (!method_exists($entity, $getter) || !method_exists($entity, $setter)) continue;

            $originalValue = $entity->$getter();

            if (empty($originalValue)) {
                continue;
            }

            $sourceLang = strtolower($attr->sourceLanguage);
            $nestedFields = $attr->nestedFields;
            $mimeType = $attr->getFormat();

            // CASO 1: CON NESTED FIELDS
            if (!empty($nestedFields)) {
                if (!is_array($originalValue)) {
                    throw new RuntimeException(sprintf('El campo "%s" tiene nestedFields, por lo que debe ser un array (lista o mapa).', $propertyName));
                }

                $newValue = $this->processNestedStructure($originalValue, $nestedFields, $sourceLang, $mimeType, $overwrite, $propertyName);

                if ($newValue !== $originalValue) {
                    $entity->$setter($newValue);
                    $hasEntityChanges = true;
                }
                continue;
            }

            // CASO 2: SIN NESTED FIELDS
            if (!is_array($originalValue) || !array_is_list($originalValue)) {
                throw new RuntimeException(sprintf(
                    'El campo "%s" sin nestedFields debe ser una lista plana de traducciones [{language, content}]. Tipo encontrado: %s',
                    $propertyName, gettype($originalValue)
                ));
            }

            $valuesMap = $this->listToMapRows($originalValue, $propertyName);
            $translatedMap = $this->translateAndCloneRows($valuesMap, $sourceLang, $mimeType, $overwrite);
            $finalValue = $this->mapRowsToList($translatedMap);

            if ($finalValue !== $originalValue) {
                $entity->$setter($finalValue);
                $hasEntityChanges = true;
            }
        }

        if ($hasEntityChanges && $updateArgs !== null) {
            $em = $updateArgs->getObjectManager();
            $meta = $em->getClassMetadata($entity::class);
            $em->getUnitOfWork()->recomputeSingleEntityChangeSet($meta, $entity);
        }
    }

    /**
     * Inicia la travesía de los nested fields leyendo la notación de flecha (->)
     */
    private function processNestedStructure(array $data, array $targetKeys, string $sourceLang, string $mimeType, bool $overwrite, string $propName): array
    {
        foreach ($targetKeys as $keyPath) {
            // Convertimos "buttons_map->button_text" en ['buttons_map', 'button_text']
            // Si es simple "subject", queda como ['subject'] manteniendo retrocompatibilidad.
            $pathParts = explode('->', $keyPath);
            $data = $this->traverseAndTranslate($data, $pathParts, $sourceLang, $mimeType, $overwrite, $propName);
        }

        return $data;
    }

    /**
     * Función recursiva mágica: bucea en el array hasta encontrar la llave final a traducir.
     */
    private function traverseAndTranslate(array $data, array $pathParts, string $sourceLang, string $mimeType, bool $overwrite, string $fullPath): array
    {
        if (empty($pathParts)) {
            return $data;
        }

        // Sacamos el nivel actual que estamos buscando (Ej: 'buttons_map')
        $currentKey = array_shift($pathParts);

        // ==========================================================
        // CASO A: El nivel actual es una Lista de Objetos (Array Numérico)
        // Ejemplo: Si $data son los botones, iteramos cada uno.
        // ==========================================================
        if (array_is_list($data) && !empty($data)) {
            foreach ($data as $index => $item) {
                // Verificamos si el ítem de la lista tiene la llave que buscamos (ej: 'button_text')
                if (is_array($item) && !empty($item[$currentKey])) {
                    if (empty($pathParts)) {
                        // ¡Llegamos al final del camino! Traducimos esta propiedad.
                        $fieldMap = $this->normalizeNestedFieldToRowMap($item[$currentKey], $sourceLang, $fullPath . '.' . $currentKey);
                        $translatedMap = $this->translateAndCloneRows($fieldMap, $sourceLang, $mimeType, $overwrite);
                        $data[$index][$currentKey] = $this->mapRowsToList($translatedMap);
                    } else {
                        // Todavía hay más niveles, aplicamos recursividad hacia abajo.
                        $data[$index][$currentKey] = $this->traverseAndTranslate($item[$currentKey], $pathParts, $sourceLang, $mimeType, $overwrite, $fullPath . '.' . $currentKey);
                    }
                }
            }
            return $data;
        }

        // ==========================================================
        // CASO B: El nivel actual es un Objeto (Array Asociativo)
        // ==========================================================
        if (isset($data[$currentKey]) && !empty($data[$currentKey])) {
            if (empty($pathParts)) {
                // ¡Llegamos al final del camino! Traducimos esta propiedad.
                $fieldMap = $this->normalizeNestedFieldToRowMap($data[$currentKey], $sourceLang, $fullPath . '.' . $currentKey);
                $translatedMap = $this->translateAndCloneRows($fieldMap, $sourceLang, $mimeType, $overwrite);
                $data[$currentKey] = $this->mapRowsToList($translatedMap);
            } else {
                // Todavía hay más niveles, aplicamos recursividad.
                $data[$currentKey] = $this->traverseAndTranslate($data[$currentKey], $pathParts, $sourceLang, $mimeType, $overwrite, $fullPath . '.' . $currentKey);
            }
        }

        return $data;
    }

    // =========================================================================
    // Funciones Helper Privadas (Sin cambios, pura retrocompatibilidad)
    // =========================================================================

    private function translateAndCloneRows(array $valuesMap, string $sourceLang, string $mimeType, bool $overwrite): array
    {
        $sourceLangNorm = strtolower($sourceLang);
        $cleanMap = [];

        foreach ($valuesMap as $lang => $row) {
            $langNorm = strtolower((string) $lang);
            if ($langNorm === $sourceLangNorm || in_array($langNorm, $this->validLanguageCodes, true)) {
                $cleanMap[$langNorm] = $row;
            }
        }
        $valuesMap = $cleanMap;

        $sourceRow = $valuesMap[$sourceLangNorm] ?? null;
        if (!is_array($sourceRow) || empty($sourceRow['content']) || !is_string($sourceRow['content'])) {
            return $valuesMap;
        }

        $sourceText = $sourceRow['content'];
        $hasChanged = false;

        foreach ($this->validLanguageCodes as $targetCode) {
            if ($targetCode === $sourceLangNorm) continue;
            if (!$overwrite && isset($valuesMap[$targetCode])) continue;

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
                continue;
            }
        }

        return $hasChanged ? $valuesMap : $valuesMap;
    }

    private function listToMapRows(mixed $values, string $propName): array
    {
        if (!is_array($values)) {
            throw new RuntimeException(sprintf('El valor de "%s" debe ser un array de traducciones.', $propName));
        }

        $out = [];
        foreach ($values as $index => $row) {
            if (!is_array($row) || !isset($row['language'], $row['content'])) {
                throw new RuntimeException(sprintf(
                    'Estructura inválida en "%s" (índice %s). Se requiere un objeto con las claves "language" y "content".',
                    $propName, $index
                ));
            }
            $out[strtolower((string) $row['language'])] = $row;
        }
        return $out;
    }

    private function mapRowsToList(array $map): array
    {
        return array_values($map);
    }

    private function normalizeNestedFieldToRowMap(mixed $value, string $sourceLang, string $propName): array
    {
        $sourceLangNorm = strtolower($sourceLang);

        if (is_string($value)) {
            return [$sourceLangNorm => ['language' => $sourceLangNorm, 'content'  => $value]];
        }

        if (is_array($value) && array_is_list($value)) {
            return $this->listToMapRows($value, $propName);
        }

        throw new RuntimeException(sprintf(
            'El valor en "%s" tiene un formato no válido. Debe ser texto plano o una lista de traducciones [{language, content}].',
            $propName
        ));
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

        $this->validLanguageCodes = array_values(array_unique($this->validLanguageCodes));
        if (!in_array('es', $this->validLanguageCodes, true)) {
            $this->validLanguageCodes[] = 'es';
        }
    }

    private function getAutoTranslateAttribute(ReflectionProperty $property): ?AutoTranslate
    {
        $attributes = $property->getAttributes(AutoTranslate::class);

        if (!isset($attributes[0])) {
            return null;
        }

        /** @var AutoTranslate $instance */
        $instance = $attributes[0]->newInstance();

        return $instance;
    }
}