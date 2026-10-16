<?php

declare(strict_types=1);

namespace App\Front\Comun\Enum;

/**
 * Qué es un medio de un álbum de experiencias: una foto subida o un vídeo por ENLACE.
 *
 * Los vídeos no se suben (decisión del 07/10/2026, docs/PlanWebPublica.md §Fase 3): YouTube o
 * Vimeo, aunque sean ocultos. No gastan ancho de banda del servidor ni disco, y la web los carga
 * sólo al pulsar. Ver `App\Front\Comun\Dto\VideoExterno`.
 */
enum ExperienciaMedioTipoEnum: string
{
    case FOTO = 'foto';
    case VIDEO = 'video';
}
