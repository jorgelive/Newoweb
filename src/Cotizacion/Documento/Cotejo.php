<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Enum\ValidacionDocumentoEnum;
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
    /** @param list<string> $observaciones */
    private function __construct(
        public ValidacionDocumentoEnum $estado,
        public array $observaciones,
    ) {}

    /**
     * @param DatosDeDocumento $leido Lo que se sacó de la imagen.
     * @param FichaGuardada|null $guardado Lo que ya dice el manifiesto. `null` = el archivo no
     *        está asignado a nadie, o la persona se acaba de crear a partir de este documento.
     */
    public static function de(DatosDeDocumento $leido, ?FichaGuardada $guardado): self
    {
        // Sin número no hay documento que valga: no se puede cotejar ni guardar, y decir
        // «observado» sugeriría que hay algo que revisar cuando lo que hay es una foto ilegible.
        if (!$leido->esUtilizable()) {
            return new self(ValidacionDocumentoEnum::NO_VALIDADO, [...$leido->avisos, 'no se pudo leer el número del documento']);
        }

        // ⚠️ **Las diferencias se calculan SIEMPRE que haya ficha, aunque esté a medias.** Una
        // versión anterior cortaba antes al faltar el nombre guardado y se callaba que el número
        // no coincidía — que es lo más importante que hay que decir. Primero se reúne todo lo que
        // está mal, y sólo después se juzga.
        $defectos = [
            ...$leido->avisos,
            ...($guardado !== null ? self::diferencias($leido, $guardado) : []),
        ];

        // Camino 1: la aritmética de la MRZ. Es la única que se sostiene sin manifiesto.
        // Camino 2: el cotejo, que exige número Y nombre guardados — sólo el número no basta, un
        // número tecleado igual en dos fichas de la misma familia es justo el error que se busca.
        $respaldado = $leido->verificadoPorMrz()
            || ($guardado !== null && $guardado->tieneNumero() && $guardado->tieneNombre());

        if ($respaldado && $defectos === []) {
            return new self(ValidacionDocumentoEnum::VALIDADO, []);
        }

        if (!$respaldado) {
            $defectos[] = match (true) {
                $guardado === null => 'el archivo no está asignado a ninguna persona del manifiesto',
                !$guardado->tieneNumero() => 'la persona no tenía documento guardado: no hay contra qué cotejar',
                default => 'la persona no tiene nombre guardado: no hay contra qué cotejar',
            };
        }

        // ⚠️ Este aviso va SÓLO cuando ya no se valida, y como explicación de por qué hizo falta
        // el manifiesto. Añadirlo siempre lo convertía en un defecto y **bloqueaba** la validación
        // de todo pasaporte sin banda, que es justo lo contrario de lo que se quiere. Que se
        // validara con MRZ o cotejando se sabe por `DatosDeDocumento::verificadoPorMrz()`, no por
        // una frase en la lista de lo que está mal.
        if (!$leido->verificadoPorMrz() && $leido->tipo === DocumentoTipoEnum::PASAPORTE) {
            $defectos[] = 'sin banda MRZ legible: hubo que cotejar con el manifiesto (revisa la calidad del escaneo)';
        }

        return new self(ValidacionDocumentoEnum::OBSERVADO, $defectos);
    }

    /** @return list<string> */
    private static function diferencias(DatosDeDocumento $leido, FichaGuardada $guardado): array
    {
        $diferencias = [];

        if (self::distinto((string) $leido->numero, (string) $guardado->numero)) {
            $diferencias[] = sprintf('el número leído (%s) no es el guardado (%s)', $leido->numero, $guardado->numero);
        }

        if ($leido->tipo !== null && $guardado->tipo !== null && strtoupper($guardado->tipo) !== $leido->tipo->value) {
            $diferencias[] = sprintf('es un %s y está guardado como %s', $leido->tipo->value, strtoupper($guardado->tipo));
        }

        // ⚠️ Sólo se señala si las DOS existen. Un vencimiento guardado en blanco no es un
        // desacuerdo: es un hueco, y confundirlos llenaría la cola de trabajo de ruido.
        if ($leido->vencimiento !== null && $guardado->vencimiento !== null
            && $leido->vencimiento->format('Y-m-d') !== $guardado->vencimiento->format('Y-m-d')) {
            $diferencias[] = sprintf(
                'vence el %s y está guardado %s',
                $leido->vencimiento->format('d/m/Y'),
                $guardado->vencimiento->format('d/m/Y'),
            );
        }

        // El nombre se compara flojo —sin tildes ni orden— porque en un padrón se escribe de
        // quince maneras y un aviso por cada una haría que nadie mirase la lista.
        $nombreLeido = trim(($leido->nombres ?? '') . ' ' . ($leido->apellidos ?? ''));
        if ($nombreLeido !== '' && $guardado->nombreCompleto !== null && trim($guardado->nombreCompleto) !== ''
            && !self::mismasPalabras($nombreLeido, $guardado->nombreCompleto)) {
            $diferencias[] = sprintf('el nombre leído (%s) no se parece al guardado (%s)', $nombreLeido, trim($guardado->nombreCompleto));
        }

        return $diferencias;
    }

    /** Compara ignorando lo que sólo es forma de escribir: espacios, guiones y caja. */
    private static function distinto(string $a, string $b): bool
    {
        $limpiar = static fn (string $v): string => strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $v));

        return $limpiar($a) !== $limpiar($b);
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
