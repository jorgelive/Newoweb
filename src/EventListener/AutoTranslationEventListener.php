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

            // REGLA 1: Si está completamente vacío, lo ignoramos de forma segura.
            if (empty($originalValue)) {
                continue;
            }

            $sourceLang = strtolower($attr->sourceLanguage);
            $nestedFields = $attr->nestedFields;
            $mimeType = $attr->getFormat();

            // ==========================================================
            // CASO 1: CON NESTED FIELDS
            // ==========================================================
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

            // ==========================================================
            // CASO 2: SIN NESTED FIELDS (La base DEBE ser lista válida)
            // ==========================================================
            if (!is_array($originalValue) || !array_is_list($originalValue)) {
                throw new RuntimeException(sprintf(
                    'El campo "%s" sin nestedFields debe ser una lista plana de traducciones [{language, content}]. Tipo encontrado: %s',
                    $propertyName, gettype($originalValue)
                ));
            }

            // Validamos y procesamos
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

    private function processNestedStructure(array $data, array $targetKeys, string $sourceLang, string $mimeType, bool $overwrite, string $propName): array
    {
        // Si es una lista de elementos (ej: wifiNetworks -> [{ssid: "A"}, {ssid: "B"}])
        if (array_is_list($data)) {
            foreach ($data as $index => $item) {
                if (!is_array($item)) {
                    throw new RuntimeException(sprintf('El elemento en el índice %d de "%s" debe ser un objeto (array).', $index, $propName));
                }

                foreach ($targetKeys as $key) {
                    if (!empty($item[$key])) {
                        $fieldMap = $this->normalizeNestedFieldToRowMap($item[$key], $sourceLang, $propName . '.' . $key);
                        $translatedMap = $this->translateAndCloneRows($fieldMap, $sourceLang, $mimeType, $overwrite);
                        $data[$index][$key] = $this->mapRowsToList($translatedMap);
                    }
                }
            }
        }
        // Si es un objeto de configuración único (ej: emailTmpl -> {is_active: true, subject: [...]})
        else {
            foreach ($targetKeys as $key) {
                // Si la llave está vacía o no existe, la ignoramos. ¡Pero si hay algo, lo validamos!
                if (!empty($data[$key])) {
                    $fieldMap = $this->normalizeNestedFieldToRowMap($data[$key], $sourceLang, $propName . '.' . $key);
                    $translatedMap = $this->translateAndCloneRows($fieldMap, $sourceLang, $mimeType, $overwrite);
                    $data[$key] = $this->mapRowsToList($translatedMap);
                }
            }
        }

        return $data;
    }

    private function translateAndCloneRows(array $valuesMap, string $sourceLang, string $mimeType, bool $overwrite): array
    {
        // (Lógica de Google Translate intacta...)
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

    /**
     * REGLA ESTRICTA: Transforma la lista asegurándose de que tenga language y content.
     */
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

    /**
     * REGLA ESTRICTA: Verifica que el contenido anidado tenga sentido.
     */
    private function normalizeNestedFieldToRowMap(mixed $value, string $sourceLang, string $propName): array
    {
        $sourceLangNorm = strtolower($sourceLang);

        // Si mandaron texto plano (ej. por código), lo autoconvertimos
        if (is_string($value)) {
            return [$sourceLangNorm => ['language' => $sourceLangNorm, 'content'  => $value]];
        }

        // Si mandaron lista, delegamos la validación estricta a listToMapRows
        if (is_array($value) && array_is_list($value)) {
            return $this->listToMapRows($value, $propName);
        }

        // Si llegó hasta aquí, hay datos pero no es ni texto ni una lista válida. EXCEPCIÓN.
        throw new RuntimeException(sprintf(
            'El valor en "%s" tiene un formato no válido. Debe ser texto plano o una lista de traducciones [{language, content}].',
            $propName
        ));
    }

    private function loadValidLanguages(): void
    {
        // (Carga de idiomas intacta...)
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