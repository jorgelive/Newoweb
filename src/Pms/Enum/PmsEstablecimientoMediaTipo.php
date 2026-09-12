<?php

declare(strict_types=1);

namespace App\Pms\Enum;

/**
 * Los medios que son del EDIFICIO, no de una casita: las dos cajas fuertes del pasaje.
 *
 * ── Por qué no están en `PmsUnidadMedia` ────────────────────────────────────
 * Porque las cajas son **una para las siete casitas**. Colgarlas de la unidad obligaría a subir la
 * misma foto siete veces, y siete copias de una foto es la misma trampa que ya costó dos arreglos
 * esta semana: el día que se cambie la cerradura hay que acordarse de las siete, y la que se
 * olvide seguirá enseñando la caja vieja sin que nada avise.
 *
 * ── Dos cajas, y no hacen lo mismo ──────────────────────────────────────────
 * | caja | para qué | quién la ve |
 * |---|---|---|
 * | **llaves** | el huésped saca su llave al llegar | él mismo, en su guía |
 * | **dinero** | el huésped deja allí un pago en efectivo | **nadie por su cuenta**: la entrega un operador |
 *
 * ⚠️ **«Caja fuerte» a secas ya no distingue cuál**, y por eso el vídeo que se llamaba
 * `video_caja_fuerte` pasa a `video_caja_llaves`. Un nombre ambiguo entre dos cosas que abren
 * sitios distintos es exactamente el patrón del `{{ door_code }}` que acabó anunciando «el código
 * de la puerta es #5».
 */
enum PmsEstablecimientoMediaTipo: string
{
    /** Cómo se abre la caja donde están las llaves. URL de YouTube. */
    case VIDEO_CAJA_LLAVES = 'video_caja_llaves';

    /** Dónde está esa caja, en foto: el pasaje tiene dos y se confunden. */
    case FOTO_CAJA_LLAVES = 'foto_caja_llaves';

    /** Cómo se abre la caja del dinero. URL de YouTube. **No sale en la guía.** */
    case VIDEO_CAJA_DINERO = 'video_caja_dinero';

    /** Dónde está la caja del dinero. **No sale en la guía.** */
    case FOTO_CAJA_DINERO = 'foto_caja_dinero';

    /** Lo que el editor escribe para pedirlo: `{{ video_caja_llaves }}`. */
    public function clave(): string
    {
        return $this->value;
    }

    /**
     * Desde qué nivel de la guía se puede ver — o `null` si **no entra en la guía, nunca**.
     *
     * ── Por qué `null` y no un quinto nivel ─────────────────────────────────
     * {@see PmsGuiaVisibilidad} es la escalera de confianza del HUÉSPED: sus cuatro peldaños
     * significan «lo verá cuando pase X» —cuando tenga localizador, cuando pague, cuando falten
     * 30 h—. Lo de la caja del dinero no lo verá nunca por ahí: se lo entrega un operador cuando
     * hace falta, y el huésped no puede pedirlo. Eso no es un peldaño más bajo, **es no estar en
     * la escalera**. Meterlo dentro obligaría a revisar `permite()` y a todos sus consumidores
     * para enseñarles a no enseñar.
     *
     * ⚠️ **El `null` se respeta en un solo sitio y por eso es seguro**:
     * {@see \App\Pms\Guia\PmsGuiaContexto::construir()} es la única puerta por la que un medio
     * entra en la guía, y allí los `null` no se cargan. Así, escribir `{{ foto_caja_dinero }}` en
     * un ítem no filtra nada — no hay valor que resolver, y el marcador se quita.
     *
     * Por dónde SÍ sale: `enviar_plantilla`, que exige `ROLE_MENSAJES_WRITE` y está cerrada por
     * {@see \App\Agent\Access\GuardiaDeSkills}, no por una frase en el prompt.
     */
    public function visibilidad(): ?PmsGuiaVisibilidad
    {
        return match ($this) {
            // Abren la puerta: mismo nivel que el resto de lo que abre puertas.
            self::VIDEO_CAJA_LLAVES, self::FOTO_CAJA_LLAVES => PmsGuiaVisibilidad::SoloVentana,
            self::VIDEO_CAJA_DINERO, self::FOTO_CAJA_DINERO => null,
        };
    }

    /** ¿Se sube un archivo, o se pega una URL? */
    public function esArchivo(): bool
    {
        return match ($this) {
            self::FOTO_CAJA_LLAVES, self::FOTO_CAJA_DINERO => true,
            self::VIDEO_CAJA_LLAVES, self::VIDEO_CAJA_DINERO => false,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::VIDEO_CAJA_LLAVES => 'Vídeo: cómo abrir la caja de las LLAVES (YouTube)',
            self::FOTO_CAJA_LLAVES => 'Foto: dónde está la caja de las LLAVES',
            self::VIDEO_CAJA_DINERO => 'Vídeo: cómo abrir la caja del DINERO (YouTube) — no sale en la guía',
            self::FOTO_CAJA_DINERO => 'Foto: dónde está la caja del DINERO — no sale en la guía',
        };
    }

    /** @return array<string, self> Etiqueta → caso, para el desplegable del panel. */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $caso) {
            // ⚠️ El CASO, no su `->value`: la columna va con `enumType`, así que la propiedad es
            // este objeto. Con cadenas, abrir la edición revienta. Pasó el 12/09/2026 con
            // `PmsUnidadMediaTipo`.
            $opciones[$caso->etiqueta()] = $caso;
        }

        return $opciones;
    }
}
