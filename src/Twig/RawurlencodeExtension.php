<?php

namespace App\Twig;
use \Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class RawurlencodeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('raw_url_encode', [$this, 'rawUrlEncode']),
        ];
    }


    public function rawUrlEncode(string $value): string
    {
        // Lógica de tu filtro
        return rawurlencode($value);
    }

}