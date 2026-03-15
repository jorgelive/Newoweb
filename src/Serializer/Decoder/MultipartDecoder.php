<?php

declare(strict_types=1);

namespace App\Serializer\Decoder;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Encoder\DecoderInterface;

final class MultipartDecoder implements DecoderInterface
{
    public const FORMAT = 'multipart';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * Decodifica la petición multipart/form-data.
     * Alimenta al Serializador SOLO con los datos de texto.
     */
    public function decode(string $data, string $format, array $context = []): ?array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            return null;
        }

        // 🔥 EXTRAEMOS SOLO EL TEXTO (POST DATA)
        // Ignoramos $request->files->all() por completo aquí.
        // El MessageMultipartProcessor se encargará de los archivos físicos después.
        $postData = $request->request->all();

        return $postData;
    }

    public function supportsDecoding(string $format): bool
    {
        return self::FORMAT === $format;
    }
}