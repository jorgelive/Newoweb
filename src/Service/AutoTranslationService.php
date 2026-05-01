<?php

declare(strict_types=1);

namespace App\Service;

use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Servicio encargado de procesar la autotraducción de entidades.
 * Centraliza la lógica para poder ser invocado tanto por Listeners de Doctrine
 * como por Comandos de consola de forma directa.
 */
class AutoTranslationService
{
    /** @var string[] Códigos de idioma en minúsculas (ej: 'en', 'pt', 'es') */
    private array $validLanguageCodes = [];

    public function __construct(
        private readonly GoogleTranslateService $translator,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * Procesa una entidad buscando atributos #[AutoTranslate] y traduce sus contenidos.
     *
     * @param object $entity La entidad a procesar.
     * @param bool $forceExecution Si es true, ignora el flag del Trait y ejecuta la traducción obligatoriamente.
     * @param bool|null $overrideOverwrite Si se define, sobreescribe el comportamiento de sobrescritura de la entidad.
     * @param ObjectManager|null $emToRecompute Si se envía (desde un preUpdate), recalcula el ChangeSet de Doctrine.
     *
     * @return void
     */
    public function processEntity(object $entity, bool $forceExecution = false, ?bool $overrideOverwrite = null, ?ObjectManager $emToRecompute = null): void
    {
        // 1. Decidir si ejecutamos o no el proceso
        $execute = $forceExecution;
        if (!$execute && method_exists($entity, 'getEjecutarTraduccion')) {
            $execute = (bool) $entity->getEjecutarTraduccion();
        }

        if (!$execute) {
            return;
        }

        if (empty($this->validLanguageCodes)) {
            $this->loadValidLanguages();
        }

        // 2. Determinar si vamos a sobrescribir (leyendo parámetro o el flag físico de la entidad)
        $globalOverwrite = $overrideOverwrite ?? (method_exists($entity, 'getSobreescribirTraduccion') ? (bool) $entity->getSobreescribirTraduccion() : false);

        $reflection = new ReflectionClass($entity);
        $hasEntityChanges = false;

        // =========================================================================
        // ✨ AUTO-APAGADO DEL FLAG DE SOBRESCRITURA
        // =========================================================================
        // Si el usuario activó la sobrescritura desde EasyAdmin (true), la regresamos a false
        // inmediatamente en la memoria del objeto para que MySQL lo guarde apagado.
        // Esto previene que el flag se quede pegado y obligue a traducir en futuras ediciones.
        if ($globalOverwrite && method_exists($entity, 'setSobreescribirTraduccion')) {
            $entity->setSobreescribirTraduccion(false);
            $hasEntityChanges = true; // Forzamos a Doctrine a registrar el 'false' en la BD
        }

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
            $mimeType = method_exists($attr, 'getFormat') ? $attr->getFormat() : 'text/html';

            // =========================================================================
            // 🛡️ BARRERA DE SEGURIDAD DINÁMICA (El Veto Declarativo)
            // =========================================================================
            $currentOverwrite = $globalOverwrite;

            // Si el atributo dice que un método puede vetar la sobrescritura, lo evaluamos
            if ($currentOverwrite && property_exists($attr, 'preventOverwriteIf') && $attr->preventOverwriteIf !== null) {
                $vetoMethod = $attr->preventOverwriteIf;
                if (method_exists($entity, $vetoMethod) && $entity->$vetoMethod() === true) {
                    $currentOverwrite = false; // Se apaga la sobrescritura solo para esta propiedad
                }
            }

            // CASO 1: CON NESTED FIELDS (Estructuras complejas anidadas)
            if (!empty($nestedFields)) {
                if (!is_array($originalValue)) {
                    throw new RuntimeException(sprintf('El campo "%s" tiene nestedFields, por lo que debe ser un array (lista o mapa).', $propertyName));
                }

                $newValue = $this->processNestedStructure($originalValue, $nestedFields, $sourceLang, $mimeType, $currentOverwrite, $propertyName);

                if ($newValue !== $originalValue) {
                    $entity->$setter($newValue);
                    $hasEntityChanges = true;
                }
                continue;
            }

            // CASO 2: SIN NESTED FIELDS (Lista plana de traducciones)
            if (!is_array($originalValue) || !array_is_list($originalValue)) {
                throw new RuntimeException(sprintf(
                    'El campo "%s" sin nestedFields debe ser una lista plana de traducciones [{language, content}]. Tipo encontrado: %s',
                    $propertyName, gettype($originalValue)
                ));
            }

            $valuesMap = $this->listToMapRows($originalValue, $propertyName);
            $translatedMap = $this->translateAndCloneRows($valuesMap, $sourceLang, $mimeType, $currentOverwrite);
            $finalValue = $this->mapRowsToList($translatedMap);

            // Solo seteamos la propiedad si el mapa traducido es distinto al original
            if ($finalValue !== $originalValue) {
                $entity->$setter($finalValue);
                $hasEntityChanges = true;
            }
        }

        // 3. Notificar a Doctrine si hubo cambios estructurales en un evento de Update
        if ($hasEntityChanges && $emToRecompute !== null) {
            $meta = $emToRecompute->getClassMetadata($entity::class);
            $emToRecompute->getUnitOfWork()->recomputeSingleEntityChangeSet($meta, $entity);
        }
    }

    /**
     * Inicia la travesía de los campos anidados leyendo la notación de flecha (->).
     *
     * @param array $data Los datos estructurados a procesar.
     * @param array $targetKeys Claves objetivo en notación de flechas.
     * @param string $sourceLang Idioma origen (ej. 'es').
     * @param string $mimeType Tipo mime para el traductor (ej. 'text/html').
     * @param bool $overwrite Si debe sobrescribir o no.
     * @param string $propName Nombre de la propiedad original para mensajes de error.
     *
     * @return array Los datos procesados.
     */
    private function processNestedStructure(array $data, array $targetKeys, string $sourceLang, string $mimeType, bool $overwrite, string $propName): array
    {
        foreach ($targetKeys as $keyPath) {
            $pathParts = explode('->', $keyPath);
            $data = $this->traverseAndTranslate($data, $pathParts, $sourceLang, $mimeType, $overwrite, $propName);
        }
        return $data;
    }

    /**
     * Función recursiva que navega en el array hasta encontrar la llave final a traducir.
     *
     * @param array $data Los datos del nivel actual.
     * @param array $pathParts Las claves restantes por navegar.
     * @param string $sourceLang Idioma de origen.
     * @param string $mimeType Tipo Mime.
     * @param bool $overwrite Bandera de sobrescritura.
     * @param string $fullPath Path completo para contexto de depuración.
     *
     * @return array
     */
    private function traverseAndTranslate(array $data, array $pathParts, string $sourceLang, string $mimeType, bool $overwrite, string $fullPath): array
    {
        if (empty($pathParts)) return $data;

        $currentKey = array_shift($pathParts);

        // CASO A: Lista de Objetos (Iterar)
        if (array_is_list($data) && !empty($data)) {
            foreach ($data as $index => $item) {
                if (is_array($item) && !empty($item[$currentKey])) {
                    if (empty($pathParts)) {
                        $fieldMap = $this->normalizeNestedFieldToRowMap($item[$currentKey], $sourceLang, $fullPath . '.' . $currentKey);
                        $translatedMap = $this->translateAndCloneRows($fieldMap, $sourceLang, $mimeType, $overwrite);
                        $data[$index][$currentKey] = $this->mapRowsToList($translatedMap);
                    } else {
                        $data[$index][$currentKey] = $this->traverseAndTranslate($item[$currentKey], $pathParts, $sourceLang, $mimeType, $overwrite, $fullPath . '.' . $currentKey);
                    }
                }
            }
            return $data;
        }

        // CASO B: Objeto Simple
        if (isset($data[$currentKey]) && !empty($data[$currentKey])) {
            if (empty($pathParts)) {
                $fieldMap = $this->normalizeNestedFieldToRowMap($data[$currentKey], $sourceLang, $fullPath . '.' . $currentKey);
                $translatedMap = $this->translateAndCloneRows($fieldMap, $sourceLang, $mimeType, $overwrite);
                $data[$currentKey] = $this->mapRowsToList($translatedMap);
            } else {
                $data[$currentKey] = $this->traverseAndTranslate($data[$currentKey], $pathParts, $sourceLang, $mimeType, $overwrite, $fullPath . '.' . $currentKey);
            }
        }

        return $data;
    }

    /**
     * Traduce y clona las filas iterando sobre los idiomas soportados.
     * Genera las filas vacantes inyectando nuevas llaves cuando es necesario.
     *
     * @param array $valuesMap Mapa asociativo indexado por idioma (ej. ['es' => [...]]).
     * @param string $sourceLang Idioma de origen.
     * @param string $mimeType Tipo de contenido.
     * @param bool $overwrite Si es verdadero, fuerza la traducción en elementos no vacíos.
     *
     * @return array
     */
    private function translateAndCloneRows(array $valuesMap, string $sourceLang, string $mimeType, bool $overwrite): array
    {
        $sourceLangNorm = strtolower($sourceLang);
        $cleanMap = [];

        // Filtra claves que no pertenecen al diccionario principal
        foreach ($valuesMap as $lang => $row) {
            $langNorm = strtolower((string) $lang);
            if ($langNorm === $sourceLangNorm || in_array($langNorm, $this->validLanguageCodes, true)) {
                $cleanMap[$langNorm] = $row;
            }
        }
        $valuesMap = $cleanMap;

        // Obtenemos la fila base original
        $sourceRow = $valuesMap[$sourceLangNorm] ?? null;
        if (!is_array($sourceRow) || empty($sourceRow['content']) || !is_string($sourceRow['content'])) {
            return $valuesMap;
        }

        $sourceText = $sourceRow['content'];
        $hasChanged = false;

        foreach ($this->validLanguageCodes as $targetCode) {
            if ($targetCode === $sourceLangNorm) continue;

            $existingRow = $valuesMap[$targetCode] ?? null;
            $isContentEmpty = $existingRow === null || trim((string) $existingRow['content']) === '';

            // Si no debemos sobrescribir y ya hay contenido, lo ignoramos y dejamos lo que puso el usuario.
            if (!$overwrite && !$isContentEmpty) {
                continue;
            }

            try {
                $res = $this->translator->translate($sourceText, $targetCode, $sourceLangNorm, $mimeType);

                if (!empty($res[0]) && is_string($res[0])) {
                    // Si ya existía, preservamos sus llaves (ej: metadatos, status). Si no, clonamos de la fuente.
                    $baseRow = $existingRow !== null ? $existingRow : $sourceRow;
                    $valuesMap[$targetCode] = array_merge($baseRow, [
                        'language' => $targetCode,
                        'content'  => $res[0],
                    ]);
                    $hasChanged = true;
                }
            } catch (\Throwable) {
                // Silenciamos excepciones puntuales de traducción para no detener todo el proceso.
                continue;
            }
        }

        return $hasChanged ? $valuesMap : $valuesMap;
    }

    /**
     * Convierte una lista JSON en un mapa asociativo por idioma.
     *
     * @param mixed $values Lista de arrays.
     * @param string $propName Nombre de la propiedad original.
     *
     * @throws RuntimeException Si el formato es inválido.
     * @return array
     */
    private function listToMapRows(mixed $values, string $propName): array
    {
        if (!is_array($values)) {
            throw new RuntimeException(sprintf('El valor de "%s" debe ser un array de traducciones.', $propName));
        }

        $out = [];
        foreach ($values as $index => $row) {
            // Se usa array_key_exists en lugar de isset para permitir contenidos "null" de EasyAdmin
            if (!is_array($row) || !isset($row['language']) || !array_key_exists('content', $row)) {
                throw new RuntimeException(sprintf(
                    'Estructura inválida en "%s" (índice %s). Se requiere un objeto con las claves "language" y "content"',
                    $propName, $index
                ));
            }

            $row['content'] = $row['content'] ?? '';
            $out[strtolower((string) $row['language'])] = $row;
        }
        return $out;
    }

    /**
     * Convierte el mapa asociativo nuevamente en una lista para guardarse en BD.
     *
     * @param array $map
     * @return array
     */
    private function mapRowsToList(array $map): array
    {
        return array_values($map);
    }

    /**
     * Normaliza un campo anidado preparándolo para ser traducido.
     * Si viene como texto plano, lo encapsula. Si viene como lista, lo indexa.
     *
     * @param mixed $value
     * @param string $sourceLang
     * @param string $propName
     *
     * @throws RuntimeException
     * @return array
     */
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

    /**
     * Carga de la BD los idiomas soportados con prioridad mayor a 0.
     */
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

        // Medida de seguridad: Garantizar que el español siempre sea base obligatoria
        if (!in_array('es', $this->validLanguageCodes, true)) {
            $this->validLanguageCodes[] = 'es';
        }
    }

    /**
     * Obtiene y procesa el atributo AutoTranslate de una propiedad vía Reflexión.
     *
     * @param ReflectionProperty $property
     * @return AutoTranslate|null
     */
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