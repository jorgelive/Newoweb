<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\AnnotationToAttributeRector;
use Rector\Php80\ValueObject\AnnotationToAttribute;

return static function (RectorConfig $rectorConfig): void {
    // Deshabilitamos el paralelismo para evitar errores de pool
    $rectorConfig->disableParallel();

    // Carpetas donde aplicar Rector
    $rectorConfig->paths([
        __DIR__ . '/src/Entity',
    ]);

    // Configuración para transformar anotaciones en atributos
    $rectorConfig->ruleWithConfiguration(AnnotationToAttributeRector::class, [
        // ===== Doctrine ORM =====
        new AnnotationToAttribute('Doctrine\ORM\Mapping\Entity'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\MappedSuperclass'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\Embeddable'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\Table'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\Index'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\UniqueConstraint'),

        new AnnotationToAttribute('Doctrine\ORM\Mapping\Id'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\Column'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\GeneratedValue'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\SequenceGenerator'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\JoinColumn'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\JoinColumns'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\OrderBy'),

        new AnnotationToAttribute('Doctrine\ORM\Mapping\OneToOne'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\OneToMany'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\ManyToOne'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\ManyToMany'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\InverseJoinColumn'),
        new AnnotationToAttribute('Doctrine\ORM\Mapping\JoinTable'),

        // ===== Symfony Validator =====
        new AnnotationToAttribute('Symfony\Component\Validator\Constraints\NotBlank'),
        new AnnotationToAttribute('Symfony\Component\Validator\Constraints\NotNull'),
        new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Length'),
        new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Email'),
        new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Positive'),
        new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Range'),
        new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Regex'),
        // (añade aquí cualquier otra Constraint que uses)

        // ===== Gedmo =====
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\Translatable'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\TranslationEntity'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\Locale'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\Timestampable'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\Sluggable'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\Slug'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\Blameable'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\Tree'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\TreeLeft'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\TreeLevel'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\TreeRight'),
        new AnnotationToAttribute('Gedmo\Mapping\Annotation\TreeParent'),
    ]);
};