<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Enum\ValidacionIdentificacionEnum;
use App\Enum\DocumentoTipoEnum;
use DateTimeImmutable;

/**
 * Decide en qué estado queda un documento: la única pieza del proceso que juzga algo.
 *
 * Va aparte del servicio y **sin una sola dependencia** para que quepa en la suite de tests, que
 * aquí es unitaria pura. Un proceso de control cuyas reglas no se pueden probar es un proceso que
 * cambia de criterio sin que nadie se entere.
 *
 * ### De dónde sale el respaldo, que es lo que separa VALIDADO de OBSERVADO
 *
 * «Lo leyó un modelo» no es respaldo: un modelo lee un número equivocado con la misma seguridad
 * con la que lee treinta correctos. Hacen falta **dos fuentes que coincidan**, y hay dos maneras
 * de conseguir la segunda:
 *
 * | Respaldo | Cómo | Necesita el manifiesto |
 * |---|---|---|
 * | **MRZ** | los dígitos de control del pasaporte — aritmética pura | no: se sostiene solo |
 * | **Cotejo** | el número **y** el nombre coinciden con lo que ya estaba guardado | sí |
 *
 * ⚠️ **El pasaporte sin MRZ legible NO se queda sin salida: cae al cotejo**, igual que un DNI. La
 * banda no siempre entra en el escaneo, y negarle la validación a un pasaporte cuyo número y
 * nombre concuerdan con el manifiesto sería tratar «no pude comprobarlo por el camino bueno» como
 * «no se puede comprobar». Lo que cambia es **cómo** quedó validado, no si lo está — y eso se ve:
 * {@see DatosDeDocumento::verificadoPorMrz()}.
 *
 * ⚠️ **El cotejo exige las DOS cosas, número y nombre.** Sólo el número no basta: un número
 * tecleado igual en dos fichas de la misma familia es justo el error que se busca. Y sólo el
 * nombre tampoco, por razones obvias.
 *
 * 🔥 **De ahí la regla que más fácil sería saltarse: sin nada guardado contra lo que cotejar, un
 * documento sin MRZ no se valida solo.** Un DNI que acaba de CREAR su propia ficha se compararía
 * consigo mismo y saldría bien siempre — un sello verde puesto por el dato que había que
 * comprobar. El pasaporte con MRZ sí puede, porque su respaldo es independiente de lo que se acabe
 * de escribir.
 */
final readonly class Cotejo
{
    /**
     * @param list<Discrepancia> $discrepancias Campos en los que el manifiesto y el documento no
     *        dicen lo mismo. Es lo que la pantalla pinta al lado de cada campo.
     * @param list<string> $notas Lo que no es de ningún campo: vencido, banda ilegible, sin nada
     *        contra qué cotejar. Va en prosa porque es para leerlo, no para ramificar.
     */
    private function __construct(
        public ValidacionIdentificacionEnum $estado,
        public array $discrepancias,
        public array $notas,
    ) {}

    /** Todo lo que hay que decir, ya compuesto, para un log o una tabla de consola. */
    public function resumen(): string
    {
        return implode(' · ', [
            ...array_map(static fn (Discrepancia $d): string => $d->titulo(), $this->discrepancias),
            ...$this->notas,
        ]);
    }

    /**
     * @param DatosDeDocumento $leido Lo que se sacó de la imagen.
     * @param FichaGuardada|null $guardado Lo que ya dice el manifiesto. `null` = el archivo no
     *        está asignado a nadie, o la persona se acaba de crear a partir de este documento.
     */
    /** No se pudo leer el escaneo: no hay veredicto que dar, y se dice por qué. */
    public static function ilegible(string $porque): self
    {
        return new self(ValidacionIdentificacionEnum::NO_VALIDADO, [], [$porque]);
    }

    public static function de(DatosDeDocumento $leido, ?FichaGuardada $guardado): self
    {
        // Sin número no hay documento que valga: no se puede cotejar ni guardar, y decir
        // «observado» sugeriría que hay algo que revisar cuando lo que hay es una foto ilegible.
        if (!$leido->esUtilizable()) {
            return new self(
                ValidacionIdentificacionEnum::NO_VALIDADO,
                [],
                [...$leido->avisos, 'no se pudo leer el número del documento'],
            );
        }

        // ⚠️ **Las diferencias se calculan SIEMPRE que haya ficha, aunque esté a medias.** Una
        // versión anterior cortaba al faltar el nombre guardado y se callaba que el número no
        // coincidía — lo más importante que hay que decir. Primero se reúne todo lo que está mal,
        // y sólo después se juzga.
        $discrepancias = $guardado !== null ? self::diferencias($leido, $guardado) : [];
        $notas = $leido->avisos;

        // Dos caminos al sello verde, y **cuál fue importa**: la MRZ son dígitos de control, el
        // cotejo son dos lecturas que coinciden. La segunda puede equivocarse en las dos a la vez
        // si el error venía del padrón original.
        $cotejable = $guardado !== null && $guardado->tieneNumero() && $guardado->tieneNombre();

        // 🔥 **Aquí HUBO un aviso de giro, y estaba en el sitio equivocado.**
        //
        // El giro es una propiedad del ARCHIVO, no del veredicto de un número. Ponerlo aquí traía
        // dos fallos: enderezar obligaba a recalcular el veredicto —una llamada a la IA y una
        // recarga del expediente por cada clic, 20 s— y sólo cubría **el escaneo que respalda ese
        // número**, así que girar el anverso hacía desaparecer el aviso con el reverso todavía
        // torcido. Ahora lo lee la pantalla de `datos_leidos`, escaneo por escaneo.
        $informativas = [];

        if ($discrepancias === [] && $notas === []) {
            if ($leido->verificadoPorMrz()) {
                return new self(ValidacionIdentificacionEnum::VALIDADO_MRZ, [], $informativas);
            }

            if ($cotejable) {
                return new self(ValidacionIdentificacionEnum::VALIDADO_OCR, [], $informativas);
            }
        }

        if (!$leido->verificadoPorMrz() && !$cotejable) {
            $notas[] = match (true) {
                $guardado === null => 'el archivo no está asignado a ninguna persona del manifiesto',
                !$guardado->tieneNumero() => 'la persona no tenía documento guardado: no hay contra qué cotejar',
                default => 'la persona no tiene nombre guardado: no hay contra qué cotejar',
            };
        }

        // ⚠️ Este aviso va SÓLO cuando ya no se valida, y como explicación de por qué hizo falta
        // el manifiesto. Añadirlo siempre lo convertía en un defecto y **bloqueaba** la validación
        // de todo pasaporte sin banda, que es lo contrario de lo que se quiere.
        // ⚠️ El DNI entra en esta lista desde que se descubrió que el nuevo peruano lleva TD1 en
        // el anverso. Antes sólo el pasaporte podía «echar de menos» su banda.
        if (!$leido->verificadoPorMrz()
            && in_array($leido->tipo, [DocumentoTipoEnum::PASAPORTE, DocumentoTipoEnum::DNI], true)) {
            $notas[] = 'sin banda MRZ legible: hubo que cotejar con el manifiesto (revisa la calidad del escaneo)';
        }

        return new self(ValidacionIdentificacionEnum::OBSERVADO, $discrepancias, [...$notas, ...$informativas]);
    }

    /** @return list<Discrepancia> */
    private static function diferencias(DatosDeDocumento $leido, FichaGuardada $guardado): array
    {
        $diferencias = [];

        if (!self::mismoNumero((string) $leido->numero, (string) $guardado->numero)) {
            $diferencias[] = new Discrepancia('número', (string) $leido->numero, (string) $guardado->numero);
        }

        if ($leido->tipo !== null && $guardado->tipo !== null && strtoupper($guardado->tipo) !== $leido->tipo->value) {
            $diferencias[] = new Discrepancia('tipo', $leido->tipo->value, strtoupper($guardado->tipo));
        }

        // ⚠️ Sólo se señala si las DOS existen. Un dato guardado en blanco no es un desacuerdo:
        // es un hueco que hay que COMPLETAR, y mezclarlos llenaría la cola de trabajo de ruido.
        foreach ([
            'vencimiento' => [$leido->vencimiento, $guardado->vencimiento],
            'nacimiento' => [$leido->nacimiento, $guardado->nacimiento],
        ] as $campo => [$delDocumento, $delManifiesto]) {
            if ($delDocumento !== null && $delManifiesto !== null
                && $delDocumento->format('Y-m-d') !== $delManifiesto->format('Y-m-d')) {
                $diferencias[] = new Discrepancia($campo, $delDocumento->format('Y-m-d'), $delManifiesto->format('Y-m-d'));
            }
        }

        // El país del documento viene en ISO-3 (`PER`) y el manifiesto guarda ISO-2 (`PE`), que es
        // la CLAVE de `MaestroPais`. Quien llama ya trae el puente resuelto — aquí sólo se compara.
        if ($leido->nacionalidadIso2 !== null && $guardado->nacionalidad !== null
            && strtoupper($guardado->nacionalidad) !== $leido->nacionalidadIso2) {
            $diferencias[] = new Discrepancia('nacionalidad', $leido->nacionalidadIso2, strtoupper($guardado->nacionalidad));
        }

        // El nombre se compara flojo —sin tildes ni orden— porque en un padrón se escribe de
        // quince maneras y un aviso por cada una haría que nadie mirase la lista.
        $nombreLeido = trim(($leido->nombres ?? '') . ' ' . ($leido->apellidos ?? ''));
        if ($nombreLeido !== '' && $guardado->nombreCompleto !== null && trim($guardado->nombreCompleto) !== ''
            && !self::mismasPalabras($nombreLeido, $guardado->nombreCompleto)) {
            $diferencias[] = new Discrepancia('nombre', $nombreLeido, trim($guardado->nombreCompleto));
        }

        return $diferencias;
    }

    /**
     * ¿Es el mismo documento? Ignora la forma de escribirlo **y el dígito verificador**.
     *
     * 🔥 **8 de las 10 discrepancias de número eran falsas por esto**, medido sobre el expediente
     * real. El DNI peruano son 8 dígitos **más uno de control**, y cada lado guarda una convención
     * distinta sin que nadie lo haya acordado:
     *
     * | Lo que dice el documento | Lo que hay guardado | Qué pasa |
     * |---|---|---|
     * | `73716768-8` | `73716768` | el escaneo trae el dígito, el padrón no — 6 casos |
     * | `122298834` | `1222988343` | al revés: el padrón lo trae de más — 2 casos |
     *
     * ⚠️ **Por eso la regla es simétrica**: cualquiera de los dos lados puede ser el largo. Escrita
     * en una sola dirección habría limpiado seis avisos y dejado dos, que es peor que no hacer
     * nada — daría la impresión de estar resuelto.
     *
     * Y **exactamente un carácter**, no «hasta dos»: con dos, `12229883` y `1222988343` pasarían
     * por el mismo documento, y eso ya no es una convención, es un número mal tecleado. Las 2
     * diferencias de verdad del expediente —`125853071` contra `61859757`— no se parecen en nada,
     * así que ninguna regla de prefijo las toca.
     */
    private static function mismoNumero(string $a, string $b): bool
    {
        $limpiar = static fn (string $v): string => strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $v));
        [$uno, $otro] = [$limpiar($a), $limpiar($b)];

        if ($uno === $otro) {
            return true;
        }

        if ($uno === '' || $otro === '') {
            return false;
        }

        [$corto, $largo] = strlen($uno) < strlen($otro) ? [$uno, $otro] : [$otro, $uno];

        return strlen($largo) - strlen($corto) === 1 && str_starts_with($largo, $corto);
    }

    /**
     * ¿Comparten al menos dos palabras? «MARIA DEL CARMEN VELASQUEZ» y «VELASQUEZ ZEGARRA, MARIA»
     * son la misma persona escrita de dos maneras, y ninguna comparación de cadenas lo diría.
     */
    private static function mismasPalabras(string $a, string $b): bool
    {
        $normalizar = static function (string $texto): array {
            $sinTildes = (string) preg_replace('/[^A-Z ]/', ' ', strtr(strtoupper($texto), 'ÁÉÍÓÚÜÑÀÈÌÒÙÂÊÎÔÛÃÕÇ', 'AEIOUUNAEIOUAEIOUAOC'));

            // Las partículas no distinguen a nadie: «DE», «DEL» y «LA» aparecen en media lista.
            return array_values(array_diff(
                array_filter(explode(' ', $sinTildes), static fn (string $p): bool => strlen($p) > 2),
                ['DEL', 'LOS', 'LAS', 'VAN', 'VON'],
            ));
        };

        $comunes = array_intersect($normalizar($a), $normalizar($b));

        return count($comunes) >= 2;
    }
}
