<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\AutoTranslate;
use App\Entity\MaestroIdioma;
use App\Service\GoogleTranslateService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;

/**
 * Núcleo Global de Traducción Automática.
 * Detecta atributos AutoTranslate en cualquier entidad y procesa traducciones
 * basándose en la configuración de idiomas prioritarios de la base de datos.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class AutoTranslationEventListener
{
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

    /**
     * Lógica central de procesamiento por reflexión.
     */
    private function process(object $entity, ?PreUpdateEventArgs $args): void
    {
        // Verificamos si la entidad implementa el control de latencia vía Trait
        if (method_exists($entity, 'getEjecutarTraduccion') && !$entity->getEjecutarTraduccion()) {
            return;
        }

        $reflection = new ReflectionClass($entity);

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(AutoTranslate::class);
            if (empty($attributes)) {
                continue;
            }

            /** @var AutoTranslate $autoTranslateAttr */
            $autoTranslateAttr = $attributes[0]->newInstance();
            $sourceLang = $autoTranslateAttr->sourceLanguage;
            $propertyName = $property->getName();

            // Respetamos getters y setters explícitos
            $getter = 'get' . ucfirst($propertyName);
            $setter = 'set' . ucfirst($propertyName);

            if (!method_exists($entity, $getter) || !method_exists($entity, $setter)) {
                continue;
            }

            $values = $entity->$getter();

            // Validamos que exista contenido en el idioma origen
            if (!is_array($values) || empty($values[$sourceLang])) {
                continue;
            }

            // Dirty Checking: Solo llamar a la API si el texto origen cambió
            if ($args !== null) {
                $changeSet = $args->getEntityChangeSet();
                if (!isset($changeSet[$propertyName])) {
                    continue;
                }
                $oldValue = $changeSet[$propertyName][0];
                if (isset($oldValue[$sourceLang]) && $oldValue[$sourceLang] === $values[$sourceLang]) {
                    continue;
                }
            }

            $this->executeBatchTranslations($entity, $values, $sourceLang, $setter);
        }
    }

    /**
     * Ejecuta las llamadas a Google Translate basándose en MaestroIdioma.
     */
    private function executeBatchTranslations(object $entity, array $values, string $sourceLang, string $setter): void
    {
        $textToTranslate = $values[$sourceLang];

        // Obtenemos idiomas marcados como prioritarios para limitar latencia y costos
        $idiomasPrioritarios = $this->entityManager->getRepository(MaestroIdioma::class)
            ->findBy(['prioritario' => true]);

        $hasChanged = false;

        foreach ($idiomasPrioritarios as $idioma) {
            $targetCode = $idioma->getCodigo();

            // Traducimos solo si el campo destino está vacío (evita pisar cambios manuales)
            if ($targetCode !== $sourceLang && empty($values[$targetCode])) {
                try {
                    $translationResult = $this->translator->translate($textToTranslate, $targetCode, $sourceLang);
                    if (!empty($translationResult)) {
                        $values[$targetCode] = $translationResult[0];
                        $hasChanged = true;
                    }
                } catch (\Exception $e) {
                    // Fallo silencioso en producción para no bloquear el guardado de la entidad
                    continue;
                }
            }
        }

        if ($hasChanged) {
            $entity->$setter($values);
        }
    }
}