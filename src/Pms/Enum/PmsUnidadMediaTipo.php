<?php

declare(strict_types=1);

namespace App\Pms\Enum;

/**
 * Qué es cada medio de una casita, y por tanto por qué clave se pide en la guía.
 *
 * ── Por qué un tipo y no un campo por cada cosa ─────────────────────────────
 * Un campo por medio (`fotoPuerta`, `croquis`, `videoIngreso`…) obliga a una migración y a tocar
 * el panel cada vez que aparece uno nuevo — y van a aparecer: el estacionamiento, la terraza, el
 * cuarto de la lavandería. Con el tipo, añadir uno es una línea aquí.
 *
 * ⚠️ **Cada tipo dice también DÓNDE vive su contenido**, y no es decorativo: un croquis es un
 * archivo que subimos y un vídeo es una URL de YouTube que ya se usa en la guía. Por eso
 * {@see self::esArchivo()} existe: el CRUD y el interpolador preguntan al tipo en vez de adivinar
 * por cuál de las dos columnas viene relleno.
 */
enum PmsUnidadMediaTipo: string
{
    /**
     * El plano del sitio numerando **sólo la puerta de esta casita**.
     *
     * ⚠️ **Uno por casita, no uno general.** El croquis viejo numeraba las siete puertas y estaba
     * copiado dentro de cada ítem de guía. Mandárselo entero a cada huésped deshace la razón por
     * la que las puertas no se numeran físicamente: que nadie sepa cuál es cuál. Ver
     * `docs/PmsGuiaHuesped.md` §3.c.
     */
    case CROQUIS = 'croquis';

    /** La puerta real, como se ve. Opcional: el croquis ya la ubica y la identifica. */
    case FOTO_PUERTA = 'foto_puerta';

    /** El recorrido hasta la puerta, en vídeo. URL de YouTube. */
    case VIDEO_INGRESO = 'video_ingreso';

    /** Lo que el editor escribe en la guía para pedirlo: `{{ croquis }}`. */
    public function clave(): string
    {
        return $this->value;
    }

    /** ¿Se sube un archivo, o se pega una URL? */
    public function esArchivo(): bool
    {
        return match ($this) {
            self::CROQUIS, self::FOTO_PUERTA => true,
            self::VIDEO_INGRESO => false,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::CROQUIS => 'Croquis (sólo con el número de esta puerta)',
            self::FOTO_PUERTA => 'Foto de la puerta',
            self::VIDEO_INGRESO => 'Vídeo del ingreso (YouTube)',
        };
    }

    /** @return array<string, string> Etiqueta → valor, para el desplegable del panel. */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $caso) {
            $opciones[$caso->etiqueta()] = $caso->value;
        }

        return $opciones;
    }
}
