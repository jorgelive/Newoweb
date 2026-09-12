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

    /**
     * Desde qué nivel de acceso se puede ver, con el MISMO vocabulario que el resto de la guía
     * ({@see \App\Pms\Enum\PmsGuiaVisibilidad}) y resuelto por el mismo juez
     * ({@see \App\Pms\Guia\PmsGuiaAcceso::permite()}).
     *
     * ⚠️ **Era un booleano y se quedaba corto.** La guía clasifica por cuatro niveles —público,
     * con localizador, pagado, y con la ventana de 30 h abierta— y un `esSensible()` los colapsa
     * en dos: obliga a elegir entre enseñar de más o de menos, y encima crea un segundo
     * vocabulario para lo mismo.
     *
     * | tipo | nivel | por qué |
     * |---|---|---|
     * | `CROQUIS`, `FOTO_PUERTA` | `Cliente` | quien tiene su localizador puede ver dónde va a dormir; una puerta verde no abre nada |
     * | `VIDEO_INGRESO` | `SoloVentana` | enseña el recorrido hasta dentro |
     *
     * **Lo decide el tipo, no quien lo consume.** Si la respuesta viviera en el contexto de la
     * guía habría que repetirla en el agente, en el catálogo y en cada sitio nuevo, y bastaría
     * olvidarla una vez para publicar algo que no tocaba.
     */
    public function visibilidad(): PmsGuiaVisibilidad
    {
        return match ($this) {
            self::CROQUIS, self::FOTO_PUERTA => PmsGuiaVisibilidad::Cliente,
            self::VIDEO_INGRESO => PmsGuiaVisibilidad::SoloVentana,
        };
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
