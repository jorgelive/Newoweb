<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Qué CLASE de negocio es este expediente, y de ahí, cómo se comporta todo.
 *
 * ## Por qué un modo y no cinco banderas
 *
 * Iba a haber una bandera por consecuencia —ocultar totales, exigir identificación, habilitar
 * padrón— y eran todas la misma decisión escrita tres veces. Con banderas sueltas existen
 * combinaciones que no significan nada («padrón sí, identificación no») y alguien tendría que
 * decidir qué hacen.
 *
 * Es la misma idea que `modoCatalogo`, que hoy **no es un flag**: sale de que la cotización cuelgue
 * de un `CotizacionCatalogo` en vez de un `CotizacionFile`. Aquí no hace falta una entidad aparte
 * para conseguir lo mismo — basta decir qué es el expediente.
 *
 * ## ⚠️ Va en el EXPEDIENTE, no en la cotización
 *
 * Un expediente tiene N versiones, y el modo es propiedad **del negocio**, no de la propuesta: la
 * v2 de un viaje de colegio sigue siendo un viaje de colegio. Ponerlo por versión abriría la puerta
 * a que la v1 exija documento y la v2 no, sin que eso quiera decir nada.
 */
enum FileModoEnum: string
{
    /** Venta normal: un grupo pequeño, precio de grupo, enlace público sin identificarse. */
    case ESTANDAR = 'estandar';

    /**
     * Grupo grande o de incentivo: colegio, empresa, promoción.
     *
     * El calculador financiero deja de ser lo que ve el cliente y pasa a ser **coste interno**:
     * aquí no se vende «coste + margen» sino un precio de paquete acordado, con gratuidades y
     * excepciones que ninguna fórmula describe.
     */
    case GRUPO = 'grupo';

    public function label(): string
    {
        return match ($this) {
            self::ESTANDAR => 'Estándar',
            self::GRUPO => 'Grupo / incentivo',
        };
    }

    /**
     * ¿La vista del cliente pide documento y fecha de nacimiento?
     *
     * Sólo en grupo: en un expediente de dos personas, pedirle el documento a quien ya tiene el
     * enlace es fricción sin nada detrás — no hay nada de otro que esconderle.
     */
    public function exigeIdentificacion(): bool
    {
        return $this === self::GRUPO;
    }

    /**
     * ¿Se enseña precio por persona en vez del total del viaje?
     *
     * Con 133 personas y 13 combinaciones de servicios, un «precio total del viaje» no describe a
     * nadie. Lo único cierto que se le puede enseñar a cada familia es lo suyo.
     */
    public function ocultaTotalDeGrupo(): bool
    {
        return $this === self::GRUPO;
    }

    /**
     * ¿Hay padrón: subgrupos, importación, panel de inclusiones por participante?
     *
     * Para dos pasajeros, montar salones y reservas de grupo es maquinaria que estorba.
     */
    public function usaPadron(): bool
    {
        return $this === self::GRUPO;
    }
}
