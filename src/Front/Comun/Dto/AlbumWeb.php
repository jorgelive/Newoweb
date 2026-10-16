<?php

declare(strict_types=1);

namespace App\Front\Comun\Dto;

use DateTimeImmutable;

/** Un álbum de experiencias tal como lo pinta la web, ya en el idioma de la página. */
final readonly class AlbumWeb
{
    /** @param list<MedioWeb> $medios en su orden */
    public function __construct(
        public string $slug,
        public string $titulo,
        public ?string $texto,
        public ?string $destino,
        public ?DateTimeImmutable $fecha,
        /** Ruta de la foto de portada para Liip; null si el álbum no tiene fotos. */
        public ?string $portada,
        public array $medios,
    ) {
    }

    /** La imagen de la tarjeta: la portada o, sin fotos, la miniatura del primer vídeo de YouTube. */
    public function miniaturaDeVideo(): ?string
    {
        foreach ($this->medios as $medio) {
            if ($medio->video?->miniatura() !== null) {
                return $medio->video->miniatura();
            }
        }

        return null;
    }

    /** @return list<MedioWeb> */
    public function fotos(): array
    {
        return array_values(array_filter($this->medios, static fn (MedioWeb $m): bool => $m->tipo === 'foto'));
    }

    /** @return list<MedioWeb> */
    public function videos(): array
    {
        return array_values(array_filter($this->medios, static fn (MedioWeb $m): bool => $m->video !== null));
    }
}
