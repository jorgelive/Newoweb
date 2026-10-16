<?php

declare(strict_types=1);

namespace App\Front\Comun\Dto;

/** Una foto o un vídeo de un álbum, ya en el idioma de la página. Ver `ExperienciaWebLector`. */
final readonly class MedioWeb
{
    public function __construct(
        /** `foto` o `video`. */
        public string $tipo,
        /** Ruta de la foto para Liip (`/carga/front/experiencias/…`); null en un vídeo. */
        public ?string $ruta,
        public ?string $pie,
        public ?VideoExterno $video,
    ) {
    }
}
