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
 * con la que lee treinta correctos. Hacen falta **dos fuentes que coincidan**, y las dos clases de
 * documento las consiguen por caminos distintos:
 *
 * | | Segunda fuente | Si no la hay |
 * |---|---|---|
 * | **Pasaporte** | los dígitos de control de la MRZ — aritmética, no depende de nadie | OBSERVADO: es un escaneo del que no se puede responder |
 * | **DNI y demás** | el número que **ya estaba** en el manifiesto | OBSERVADO: no hay con qué cotejar |
 *
 * 🔥 **Y de ahí sale la regla que más fácil sería saltarse: un DNI que CREA su propia ficha no
 * puede quedar VALIDADO.** Se compararía consigo mismo y saldría bien siempre — un sello verde
 * puesto por el propio dato que había que comprobar. El pasaporte sí puede, porque su respaldo es
 * la aritmética de la MRZ y no el manifiesto: es independiente de lo que se acabe de escribir.
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
        $observaciones = $leido->avisos;

        // Sin número no hay documento que valga: no se puede cotejar ni guardar, y decir
        // «observado» sugeriría que hay algo que revisar cuando lo que hay es una foto ilegible.
        if (!$leido->esUtilizable()) {
            return new self(ValidacionDocumentoEnum::NO_VALIDADO, [...$observaciones, 'no se pudo leer el número del documento']);
        }

        $esPasaporte = $leido->tipo === DocumentoTipoEnum::PASAPORTE;

        if ($esPasaporte && !$leido->verificadoPorMrz()) {
            // ⚠️ Un pasaporte sin MRZ legible es el caso PELIGROSO, no el neutro: la lectura sale
            // igual de completa y creíble que una comprobada, y sin este aviso las dos se ven
            // iguales en pantalla. Casi siempre es resolución: la banda necesita el escaneo bueno.
            $observaciones[] = 'no se pudo leer la banda MRZ: la lectura no está comprobada (revisa la calidad del escaneo)';
        }

        if ($guardado === null || !$guardado->tieneNumero()) {
            $observaciones[] = $guardado === null
                ? 'el archivo no está asignado a ninguna persona del manifiesto'
                : 'la persona no tenía documento guardado: no hay contra qué cotejar';

            // El pasaporte con MRZ coherente se sostiene solo — su respaldo no es el manifiesto.
            return new self(
                $esPasaporte && $leido->verificadoPorMrz() && $leido->avisos === []
                    ? ValidacionDocumentoEnum::VALIDADO
                    : ValidacionDocumentoEnum::OBSERVADO,
                $observaciones,
            );
        }

        foreach (self::diferencias($leido, $guardado) as $diferencia) {
            $observaciones[] = $diferencia;
        }

        $respaldado = $esPasaporte
            ? $leido->verificadoPorMrz()
            // Para un DNI, coincidir con lo guardado ES el respaldo. Si hubiera diferencias, la
            // lista de observaciones no estaría vacía y no se llega a VALIDADO de todos modos.
            : true;

        return new self(
            $respaldado && $observaciones === [] ? ValidacionDocumentoEnum::VALIDADO : ValidacionDocumentoEnum::OBSERVADO,
            $observaciones,
        );
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
