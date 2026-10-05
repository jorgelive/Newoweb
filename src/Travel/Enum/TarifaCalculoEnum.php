<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * **Cómo se cuenta el dinero de una tarifa**: por persona, por grupo, o repartido y oculto.
 *
 * Sustituye al booleano `costoPorGrupo` / `esGrupal`, que sólo sabía decir dos de los tres casos.
 *
 * ⚠️ **No confundir con {@see TarifaModalidadEnum}**, que es `privado`/`compartido` —el nivel de
 * exclusividad del servicio— y no tiene nada que ver con la aritmética. El panel llama a esto
 * «Modalidad de Cálculo» y a aquello «Modalidad», que es lo que invita a mezclarlos; aquí se
 * llaman distinto a propósito.
 *
 * ## ⚠️ Por qué un booleano no bastaba
 *
 * Porque gobernaba **dos comportamientos a la vez**:
 *
 *     × 1 en vez de × cantidad        al calcular el total
 *     / numPax                        al sacar la cifra por persona
 *
 * En `INDIVIDUAL` y `GRUPAL` los dos van juntos, y por eso un `bool` parecía suficiente.
 * **`OPERATIVA` los rompe: multiplica por cantidad Y se prorratea.** Es el caso de los liberados
 * de un grupo —cinco vuelos que el grupo paga entre todos— que no cabía en ninguno de los dos.
 *
 * 🔑 **De ahí que esto exponga TRES predicados y no un `match` suelto en cada sitio.** Los diez
 * puntos del clasificador que hoy preguntan `esGrupal` no estaban haciendo la misma pregunta: unos
 * preguntaban cómo multiplicar y otros si repartir. Un reemplazo mecánico dejaría la operativa sin
 * prorratear, y eso sale como una cifra por persona baja — plausible, y revisada por nadie.
 *
 * ```
 *                   multiplicaPorCantidad()   seProrratea()   visibleParaCliente()
 * INDIVIDUAL                 sí                    no                 sí
 * GRUPAL                     no (× 1)              sí                 sí
 * OPERATIVA                  sí                    sí                 NO
 * ```
 *
 * ⚠️ **Espejo de `dominio/cotizacion/modalidadTarifa.ts`.** Si cambia una regla, se tocan LOS DOS
 * — las dos apps leen de allí y el backend de aquí. Ver `docs/PlanModalidadDeTarifa.md`.
 */
enum TarifaCalculoEnum: string
{
    /** El monto ya es por persona: se multiplica por la cantidad y no se reparte. */
    case INDIVIDUAL = 'individual';

    /** El monto es el total del grupo: no se multiplica, y se reparte para enseñarlo por pax. */
    case GRUPAL = 'grupal';

    /**
     * Un costo que el grupo paga entre todos y **el cliente no ve como línea**.
     *
     * Multiplica por cantidad —cinco vuelos liberados son `cantidad = 5`— y se reparte. Es la
     * combinación que el booleano no podía expresar.
     */
    case OPERATIVA = 'operativa';

    /** ¿El monto se multiplica por la cantidad, o es un precio cerrado? */
    public function multiplicaPorCantidad(): bool
    {
        return $this !== self::GRUPAL;
    }

    /** ¿La cifra por persona sale de dividir el total entre los pax? */
    public function seProrratea(): bool
    {
        return $this !== self::INDIVIDUAL;
    }

    /**
     * ¿Sale como línea en lo que lee el cliente?
     *
     * ⚠️ Lo contrario NO significa que no cueste: una operativa **suma al costo igual que las
     * demás**. Lo único que no hace es aparecer.
     */
    public function visibleParaCliente(): bool
    {
        return $this !== self::OPERATIVA;
    }

    /** Cómo se nombra en pantalla. Espejo de `ETIQUETAS_CALCULO` en el archivo de `dominio/`. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::INDIVIDUAL => 'Individual',
            self::GRUPAL => 'Grupal',
            self::OPERATIVA => 'Operativa',
        };
    }
}
