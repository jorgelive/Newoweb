<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizador global de fechas.
 * 🔥 Usamos prioridad 100 para asegurarnos de que se ejecute ANTES
 * que el normalizador por defecto de Symfony o API Platform.
 */
#[AutoconfigureTag('serializer.normalizer', attributes: ['priority' => 100])]
class DateTimeNormalizer implements NormalizerInterface
{
    // Formato estricto: sin 'Z' y sin '+00:00' (Floating Time)
    private const FORMAT = 'Y-m-d\TH:i:s';

    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        /** @var DateTimeInterface $object */
        return $object->format(self::FORMAT);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof DateTimeInterface;
    }

    /**
     * 🔥 LA NUEVA FORMA (Reemplaza a CacheableSupportsMethodInterface)
     * Le dice a Symfony exactamente qué clases maneja este normalizador
     * y permite que guarde la decisión en caché (true) para máximo rendimiento.
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            DateTimeInterface::class => true,
            \DateTime::class => true,
            \DateTimeImmutable::class => true,
        ];
    }
}