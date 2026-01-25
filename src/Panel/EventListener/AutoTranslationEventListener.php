<?php

namespace App\Panel\EventListener;

use App\Attribute\AutoTranslate;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use ReflectionClass;

//use App\Service\GoogleTranslationService;

// <-- Nuevo atributo

// Marcamos la clase como un Listener de Doctrine para eventos específicos
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class AutoTranslationEventListener
{
    public function __construct(
        //private GoogleTranslationService $translator
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->process($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->process($args->getObject());
    }

    private function process(object $entity): void
    {
        $reflection = new ReflectionClass($entity);

        foreach ($reflection->getProperties() as $property) {
            // Buscamos nuestro atributo personalizado AutoTranslate
            $attributes = $property->getAttributes(AutoTranslate::class);

            if (empty($attributes)) {
                continue;
            }

            $autoTranslateAttr = $attributes[0]->newInstance();
            $sourceLang = $autoTranslateAttr->sourceLanguage;

            /*$property->setAccessible(true);
            $values = $property->getValue($entity);

            if (is_array($values) && isset($values[$sourceLang])) {
                $textToTranslate = $values[$sourceLang];

                // Lógica de traducción
                if (empty($values['en'])) {
                    $values['en'] = $this->translator->translate($textToTranslate, 'en');
                }
                if (empty($values['pt'])) {
                    $values['pt'] = $this->translator->translate($textToTranslate, 'pt');
                }

                $property->setValue($entity, $values);
            }*/
        }
    }
}