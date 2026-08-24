<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Qué es esta persona dentro del grupo, y de ahí, qué ve y quién la ve.
 *
 * ⚠️ **El vocabulario no es escolar.** El modo grupo sirve igual para la promoción de un colegio
 * que para un incentivo de empresa, así que los nombres describen el PAPEL y no el contexto:
 * `PARTICIPANTE` y no «alumno», `ACOMPANANTE` y no «padre de familia». En un viaje de empresa nadie
 * es alumno ni padre de nadie, y en uno de colegio los dos nombres siguen leyéndose bien.
 *
 * ## Dos ejes, y confundirlos es la fuga
 *
 * - **{@see self::alcance()}** — qué expediente ve.
 * - **{@see self::esExpuesto()}** — si aparece ante los demás.
 *
 * Un invitado no es «el que menos ve»: es **el que no se ve**. Eso no es un límite de alcance,
 * es una propiedad de la persona, y por eso son dos preguntas y no una.
 *
 * ## Por qué los invitados desaparecen
 *
 * Son **gratuidades de la agencia**, acordadas aparte del contrato con el colegio. En el padrón de
 * Punta Cana son 7 de 133, con 1 a 5 servicios cada uno. Si el colegio los ve, pregunta por ellos —
 * y la respuesta no es del colegio.
 *
 * ⚠️ Desaparecen **para todos**, no sólo para el supervisor. Filtrar por rol dejaría huecos
 * —habitaciones con una cama de menos, cuentas que no cuadran— y los huecos se preguntan igual.
 *
 * ## Y no hay «jefes»
 *
 * La primera versión puso un `esJefe` en la pertenencia. El padrón lo desmintió: hay **9
 * coordinadores para 9 grupos, uno cada uno**, y el rol ya dice quién lidera. Una bandera que no
 * cambia nada **y que además podía abrir una puerta** es peor que no tenerla, así que se quitó.
 * El rol es el único mecanismo, y además es el que decide el colegio y viene en el padrón.
 */
enum PasajeroTipoEnum: string
{
    case PARTICIPANTE = 'participante';
    case ACOMPANANTE = 'acompanante';
    case COORDINADOR = 'coordinador';
    case SUPERVISOR = 'supervisor';
    case INVITADO = 'invitado';
    case NO_PARTICIPA = 'no_participa';

    public function label(): string
    {
        return match ($this) {
            self::PARTICIPANTE => 'Participante',
            self::ACOMPANANTE => 'Acompañante',
            self::COORDINADOR => 'Coordinador',
            self::SUPERVISOR => 'Supervisor',
            self::INVITADO => 'Invitado',
            self::NO_PARTICIPA => 'No participa',
        };
    }

    /**
     * Hasta dónde llega lo que ve.
     *
     * ⚠️ `EXPEDIENTE` **nunca incluye a los invitados**: eso lo decide `esExpuesto()`, que es el
     * otro eje. Un supervisor ve todo el viaje del colegio, y las gratuidades de la agencia no son
     * parte de eso.
     */
    public function alcance(): AlcanceDeVistaEnum
    {
        return match ($this) {
            self::SUPERVISOR => AlcanceDeVistaEnum::EXPEDIENTE,
            self::COORDINADOR => AlcanceDeVistaEnum::SUS_GRUPOS,
            self::PARTICIPANTE, self::ACOMPANANTE, self::INVITADO, self::NO_PARTICIPA => AlcanceDeVistaEnum::SOLO_YO,
        };
    }

    /**
     * ¿Aparece en las listas que ven los demás?
     *
     * Sólo el invitado dice que no, y lo dice **explícitamente y no por descarte**: si se resolviera
     * con un `default`, el día que alguien deje el tipo en blanco un invitado heredaría el
     * comportamiento visible. Un `case` propio no se olvida.
     */
    public function esExpuesto(): bool
    {
        return match ($this) {
            self::INVITADO => false,
            self::PARTICIPANTE, self::ACOMPANANTE, self::COORDINADOR, self::SUPERVISOR, self::NO_PARTICIPA => true,
        };
    }

    /**
     * El rol tal como viene en la hoja. **Estricto: sólo lo que la plantilla enumera.**
     *
     * ⚠️ Antes adivinaba —«alumno», «acompañante», «ppff»— y era un error de criterio: la plantilla
     * trae una hoja de instrucciones con los valores válidos y un desplegable en la propia columna,
     * así que adivinar sólo consigue que un «Alumbo» mal escrito entre como algo y nadie se entere.
     *
     * Un valor que no esté en la lista devuelve `null`, y el importador **lo dice con nombre y
     * apellido** en vez de tragárselo.
     */
    public static function desdeTexto(?string $texto): ?self
    {
        $limpio = mb_strtolower(trim((string) $texto));

        if ($limpio === '') {
            return null;
        }

        foreach (self::cases() as $caso) {
            if ($limpio === $caso->value || $limpio === mb_strtolower($caso->label())) {
                return $caso;
            }
        }

        return null;
    }

    /** @return list<string> Lo que se acepta escribir, para la hoja de instrucciones. */
    public static function valoresValidos(): array
    {
        return array_map(static fn (self $c): string => $c->label(), self::cases());
    }
}
