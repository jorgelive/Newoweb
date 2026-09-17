<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Enum\ValidacionIdentificacionEnum;
use DateTimeImmutable;

/**
 * Decide en qué estado queda un trámite migratorio: la única pieza que juzga algo.
 *
 * Hermano de {@see Cotejo}, con la misma forma y por el mismo motivo: **sin una sola dependencia**,
 * para que quepa en la suite unitaria. Un control cuyas reglas no se pueden probar es un control
 * que cambia de criterio sin que nadie se entere.
 *
 * ── Contra qué se coteja ────────────────────────────────────────────────────
 * Contra {@see CruceDeFrontera} —el vuelo con el que ESA persona entra al país y aquél con el que
 * sale, sacados de su propio subgrupo aéreo— y contra {@see ReferenciaDeIdentidad}.
 *
 * | campo | qué dice el documento | contra qué |
 * |---|---|---|
 * | `pasaporte` | el número que declaró al rellenarlo | el **escaneo** de su pasaporte |
 * | `nombre` | el que tecleó | el **escaneo** de su pasaporte |
 * | `vuelo de entrada` | el que escribió | `CruceDeFrontera::$entrada` |
 * | `fecha de entrada` | la que escribió | el día que **aterriza** ese vuelo |
 * | `vuelo de salida` | el que escribió | `CruceDeFrontera::$salida` |
 * | `fecha de salida` | la que escribió | el día que **despega** ese vuelo |
 *
 * 🔥 **El escaneo y no el manifiesto, y esto fue una corrección de fondo.** El manifiesto está
 * tecleado a mano y el E-Ticket también: cotejar uno contra otro dice que discrepan, no quién tiene
 * razón. Medido sobre los ocho primeros documentos reales, cotejar contra el manifiesto habría
 * acusado a **dos que estaban bien**. El porqué y la jerarquía, en {@see ReferenciaDeIdentidad}.
 *
 * 🔑 **Se entra el día que se ATERRIZA y se sale el día que se DESPEGA**, y la asimetría es el
 * detalle que más fácil se pasa. El `DM6771` despega de Lima el 18 a las 00:30 y aterriza en Punta
 * Cana a las 06:49 del 18 — coinciden por suerte. El `DM6770` despega de Punta Cana el 22 a las
 * 20:22 y aterriza en Lima el **23** a las 00:30: comparar contra la llegada haría que un trámite
 * correcto saliera con la fecha equivocada, y en un vuelo de vuelta nocturno eso es todo el grupo.
 *
 * ── Lo que NO hace ──────────────────────────────────────────────────────────
 * ⚠️ **No hay «validado» automático.** Lo mejor que da es {@see ValidacionIdentificacionEnum::VALIDADO_OCR},
 * que aquí significa «nada que objetar en lo que se pudo leer». A diferencia del pasaporte, un
 * E-Ticket no tiene MRZ ni dígitos de control: no existe una segunda fuente aritmética que lo
 * respalde, así que el sello fuerte sigue siendo humano.
 */
final readonly class CotejoDeEticket
{
    /**
     * @param list<Discrepancia> $discrepancias
     * @param list<string>       $notas
     */
    private function __construct(
        public ValidacionIdentificacionEnum $estado,
        public array $discrepancias,
        public array $notas,
    ) {}

    /**
     * No se pudo leer el documento: es un veredicto, no un silencio.
     *
     * ⚠️ Antes esto devolvía `null` y el archivo se quedaba en `no_validado` con las notas vacías —
     * el motivo sólo en `lecturaError`, que la hoja no lee—, así que la persona salía en verde con
     * su PDF ilegible. Espejo de {@see Cotejo::ilegible()}.
     */
    public static function ilegible(string $porque): self
    {
        return new self(ValidacionIdentificacionEnum::NO_VALIDADO, [], [$porque]);
    }

    /**
     * @param DatosDeEticket        $leido      lo que se sacó del documento
     * @param CruceDeFrontera       $cruce      los vuelos de ESA persona
     * @param ReferenciaDeIdentidad $identidad  contra qué identidad, y de dónde salió
     */
    public static function de(DatosDeEticket $leido, CruceDeFrontera $cruce, ReferenciaDeIdentidad $identidad): self
    {
        // ⚠️ **«Es otro documento» es OBSERVADO, no NO_VALIDADO.** Es el error más común —medio
        // grupo sube su billete de avión creyendo que es el trámite— y es accionable: hay que
        // escribirle a esa persona. En `no_validado` se confunde con «nunca se ha mirado», que es
        // lo contrario, y se queda fuera del filtro y del contador de la cola de trabajo.
        if ($leido->esOtroDocumento()) {
            return new self(ValidacionIdentificacionEnum::OBSERVADO, [], $leido->avisos);
        }

        if (!$leido->esUtilizable()) {
            return new self(
                ValidacionIdentificacionEnum::NO_VALIDADO,
                [],
                [...$leido->avisos, 'no se pudo leer ningún dato de vuelo ni de fecha'],
            );
        }

        // ⚠️ Sin saber con qué vuelos va, cualquier veredicto sería sobre el vuelo equivocado.
        // Eso es «no se puede comprobar», y se dice por qué: casi siempre es que a esa persona no
        // se le asignó su subgrupo aéreo, que es algo que alguien puede arreglar en un minuto.
        $porQueNo = $cruce->porQueNoSePuede();

        if ($porQueNo !== '') {
            return new self(
                ValidacionIdentificacionEnum::NO_VALIDADO,
                [],
                [...$leido->avisos, 'no se puede cotejar: '.$porQueNo],
            );
        }

        $diferencias = [];

        // 🔥 **Primero, CUÁL de las filas es la suya.** El trámite puede ser de varias personas y
        // hasta el 16/09/2026 se cotejaba siempre contra la primera. Ver `PasajeroDelTramite`.
        $quien = self::aQuienCorresponde($leido, $identidad);

        if ($quien === null && $leido->pasajeros !== []) {
            // Figura gente, y ninguna es ésta. Es lo contrario de un dedazo —el trámite es de otro—
            // y por eso se enseña la lista entera: quien lo mire tiene que ver a nombre de quién
            // está, no un «no coincide» a secas.
            $diferencias[] = new Discrepancia(
                'a nombre de',
                implode(' · ', array_map(
                    static fn (PasajeroDelTramite $p): string => $p->nombre ?? $p->pasaporte ?? '¿?',
                    $leido->pasajeros,
                )),
                (string) ($identidad->nombre ?? $identidad->pasaporte),
            );
        }

        $quien ??= new PasajeroDelTramite();

        // El pasaporte: es lo que une el trámite con la persona. Un E-Ticket correcto a nombre de
        // otro pasaporte no sirve en el mostrador.
        if ($identidad->pasaporte !== null && $quien->pasaporte !== null
            && !self::mismoTexto($quien->pasaporte, $identidad->pasaporte)) {
            $diferencias[] = new Discrepancia('pasaporte', $quien->pasaporte, $identidad->pasaporte);
        }

        // 🔥 **El nombre, que es lo que de verdad se teclea mal.** Hubo que avisar a mano de un
        // `Ascarsa` por `Ascarza` y un `juaquin` por `joaquin`: en el mostrador de Migración, un
        // nombre que no es el del pasaporte es un problema, y no lo caza ningún otro control.
        $nombre = CotejoDeNombre::de($quien->nombre ?? '', $identidad->nombre);

        if ($nombre->hayDedazo()) {
            $diferencias[] = new Discrepancia('nombre', $nombre->comoSeLee(), (string) $identidad->nombre);
        }

        $entrada = $cruce->entrada;
        $salida = $cruce->salida;

        if ($leido->vueloEntrada !== null && !self::mismoVuelo($leido->vueloEntrada, $entrada?->getNumero())) {
            $diferencias[] = new Discrepancia('vuelo de entrada', $leido->vueloEntrada, (string) $entrada?->getNumero());
        }

        if ($leido->vueloSalida !== null && !self::mismoVuelo($leido->vueloSalida, $salida?->getNumero())) {
            $diferencias[] = new Discrepancia('vuelo de salida', $leido->vueloSalida, (string) $salida?->getNumero());
        }

        // Se ATERRIZA para entrar y se DESPEGA para salir. Ver la cabecera.
        $diferencias = [
            ...$diferencias,
            ...self::fecha('fecha de entrada', $leido->fechaEntrada, $entrada?->getLlegada()),
            ...self::fecha('fecha de salida', $leido->fechaSalida, $salida?->getSalida()),
        ];

        $notas = $leido->avisos;

        // ⚠️ Se dice SIEMPRE que el formulario es de varios, aunque todo cuadre. Quien revisa ve un
        // trámite con dos nombres y tiene que saber que eso es normal y que se miró el suyo; sin la
        // nota, el acierto se lee como que el control no se enteró.
        if (count($leido->pasajeros) > 1) {
            $notas[] = sprintf(
                'el trámite es compartido: figuran %d personas%s',
                count($leido->pasajeros),
                $quien->nombre !== null ? sprintf(', y se comprobó la de «%s»', $quien->nombre) : '',
            );
        }

        if ($identidad->pasaporte === null && $identidad->nombre === null) {
            $notas[] = 'no hay pasaporte ni nombre con qué comprobar a nombre de quién está el trámite';
        } elseif (!$identidad->esFiable && $diferencias !== []) {
            // ⚠️ Sin escaneo con MRZ, la referencia también está tecleada a mano: se dice de dónde
            // sale para que nadie mande a rehacer un trámite que a lo mejor es el que tiene razón.
            $notas[] = sprintf('ojo: se comparó contra %s', $identidad->fuente);
        }

        // Lo que no se acusa pero se apunta: un nombre de más o de menos tiene mil explicaciones
        // inocentes —el formulario corta, el segundo nombre no se usa— y acusar llenaría la hoja de
        // ruido. Que conste, sin mandar a nadie a corregir nada.
        foreach ($nombre->sobran as $palabra) {
            $notas[] = sprintf('el trámite trae «%s» y %s no', $palabra, $identidad->fuente);
        }

        foreach ($nombre->faltan as $palabra) {
            $notas[] = sprintf('%s dice «%s» y el trámite no lo trae', $identidad->fuente, $palabra);
        }

        // 🔥 Un trámite a medias NO es un trámite con una discrepancia: es un trámite que hay que
        // rehacer. Se separa del resto porque lo que hay que pedirle a esa persona es distinto.
        //
        // ⚠️ Y **tiene que decir por qué**. Con las dos secciones en `false` —que el modelo puede
        // devolver aun habiendo leído vuelos y fechas— esto daba «Observado» con cero discrepancias
        // y ninguna nota: en la hoja, una celda ámbar vacía; en pantalla, un chip sin explicación.
        // Un aviso que no dice qué hacer no se puede atender.
        if (!$leido->traeEntrada || !$leido->traeSalida) {
            if (!$leido->traeEntrada && !$leido->traeSalida) {
                $notas[] = 'no se reconoció ninguna de las dos secciones: comprueba que el archivo '
                    .'sea el E-Ticket y que se vea entero';
            }

            return new self(ValidacionIdentificacionEnum::OBSERVADO, $diferencias, $notas);
        }

        return new self(
            $diferencias === [] ? ValidacionIdentificacionEnum::VALIDADO_OCR : ValidacionIdentificacionEnum::OBSERVADO,
            $diferencias,
            $notas,
        );
    }

    /**
     * Cuál de las personas del trámite es la dueña de este archivo.
     *
     * 🔑 **El pasaporte manda, y por eso se mira primero.** Es el único campo de los dos lados que
     * no admite interpretación: o es el número o no lo es.
     *
     * ⚠️ **Con un solo pasajero se devuelve ése aunque el pasaporte no cuadre**, y es deliberado:
     * un trámite de una sola persona con otro número es exactamente la discrepancia que hay que
     * denunciar. Devolver `null` ahí la convertiría en «el trámite es de otro», que dice algo
     * distinto y manda a otro sitio.
     *
     * Con varios y ninguno por pasaporte se decide por nombre, y **sólo si gana en solitario**: en
     * un formulario familiar los apellidos se repiten, así que un empate no distingue a nadie y
     * elegir al azar sería peor que decir que no se sabe.
     */
    private static function aQuienCorresponde(DatosDeEticket $leido, ReferenciaDeIdentidad $identidad): ?PasajeroDelTramite
    {
        if ($leido->pasajeros === []) {
            return null;
        }

        if ($identidad->pasaporte !== null) {
            foreach ($leido->pasajeros as $candidato) {
                if ($candidato->pasaporte !== null && self::mismoTexto($candidato->pasaporte, $identidad->pasaporte)) {
                    return $candidato;
                }
            }
        }

        if (count($leido->pasajeros) === 1) {
            return $leido->pasajeros[0];
        }

        $suyas = PalabrasDelNombre::de((string) $identidad->nombre);

        if ($suyas === []) {
            return null;
        }

        $mejor = null;
        $mejorPuntuacion = 0;
        $empatado = false;

        foreach ($leido->pasajeros as $candidato) {
            $comunes = count(array_intersect(PalabrasDelNombre::de($candidato->nombre ?? ''), $suyas));

            if ($comunes > $mejorPuntuacion) {
                $mejor = $candidato;
                $mejorPuntuacion = $comunes;
                $empatado = false;
            } elseif ($comunes === $mejorPuntuacion && $comunes > 0) {
                $empatado = true;
            }
        }

        return $empatado ? null : $mejor;
    }

    /**
     * ⚠️ Una fecha que no se leyó NO es una fecha que no coincide. Devolver discrepancia ahí sería
     * acusar a alguien por una foto movida.
     *
     * @return list<Discrepancia>
     */
    private static function fecha(string $campo, ?DateTimeImmutable $documento, ?DateTimeImmutable $esperada): array
    {
        if ($documento === null || $esperada === null) {
            return [];
        }

        if ($documento->format('Y-m-d') === $esperada->format('Y-m-d')) {
            return [];
        }

        return [new Discrepancia($campo, $documento->format('Y-m-d'), $esperada->format('Y-m-d'))];
    }

    /**
     * ⚠️ `H2 5002` y `H25002` son el mismo vuelo. El espacio lo pone la aerolínea cuando el código
     * lleva un dígito, y quien rellena el formulario lo escribe como le sale: exigir la misma
     * puntuación convertiría la mitad de los trámites correctos en discrepancias.
     */
    private static function mismoVuelo(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return true;   // nada que comparar: no se acusa
        }

        return self::mismoTexto(str_replace([' ', '-'], '', $a), str_replace([' ', '-'], '', $b));
    }

    /**
     * ⚠️ **Se quita la puntuación, no sólo los espacios de los extremos.** `P 1234567` y `P1234567`
     * son el mismo pasaporte y quien rellena el formulario lo escribe como le sale. `Cotejo` ya lo
     * hacía para los números de identidad (`mismoNumero()`) y aquí no se había traído: era una
     * discrepancia falsa por cada pasajero que teclease un espacio o un guion.
     */
    private static function mismoTexto(string $a, string $b): bool
    {
        $limpiar = static fn (string $v): string
            => mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $v));

        return $limpiar($a) === $limpiar($b);
    }

    /** Todo lo que hay que decir, ya compuesto, para un log o una tabla de consola. */
    public function resumen(): string
    {
        $partes = array_map(
            static fn (Discrepancia $d): string => sprintf('%s: doc %s ≠ esperado %s', $d->campo, $d->documento, $d->manifiesto),
            $this->discrepancias,
        );

        return implode(' · ', [...$partes, ...$this->notas]) ?: 'nada que objetar';
    }
}
