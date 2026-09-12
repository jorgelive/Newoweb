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

    /** No se pudo leer el escaneo: no hay veredicto que dar, y se dice por qué. */
    public static function ilegible(string $porque): self
    {
        return new self(ValidacionIdentificacionEnum::NO_VALIDADO, [], [$porque]);
    }

    /**
     * @param DatosDeDocumento $leido Lo que se sacó de la imagen.
     * @param FichaGuardada|null $guardado Lo que ya dice el manifiesto. `null` = el archivo no
     *        está asignado a nadie, o la persona se acaba de crear a partir de este documento.
     * @param Mrz|null $aval La banda de **otra cara** del mismo documento, cuando ésta no la
     *        lleva. Es el caso del DNIe: el número va impreso en el anverso y la MRZ, en el
     *        reverso. Sin esto, un DNI jamás llegaba a «validado por MRZ».
     * @param bool $faltaLaOtraCara Esa cara **no está subida**, que es distinto de estar subida y
     *        no servir. Separa «súbela» de «mírala»: sin distinguirlo, el aviso tenía que decir
     *        las dos cosas a la vez y no servía para ninguna.
     */
    public static function de(
        DatosDeDocumento $leido,
        ?FichaGuardada $guardado,
        ?Mrz $aval = null,
        bool $faltaLaOtraCara = false,
    ): self {
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

        // 🔥 **La banda de la otra cara vale igual que la propia, pero SÓLO si es del mismo
        // documento.** Se compara el número antes de darla por buena: dos caras de DNIs distintos
        // en la misma ficha —un archivo mal asignado— es exactamente el fallo que esto persigue,
        // y aceptar el aval a ciegas lo sellaría en verde.
        $verificado = $leido->verificadoPorMrz();
        if (!$verificado && $aval !== null && $aval->esCoherente()) {
            // Mismos criterios que contra el manifiesto, y por el mismo motivo: el DNI convive con
            // el CUI de 9 dígitos, así que «41501189» y «415011895» son el mismo documento.
            if (self::mismoNumero($aval->numero, (string) $leido->numero, $leido->tipo, $leido->tipo?->value)) {
                $verificado = true;
            } else {
                $notas[] = sprintf(
                    'la banda del reverso dice %s y el anverso %s: son dos documentos distintos',
                    $aval->numero,
                    (string) $leido->numero,
                );
            }
        }

        // Dos caminos al sello verde, y **cuál fue importa**: la MRZ son dígitos de control, el
        // cotejo son dos lecturas que coinciden. La segunda puede equivocarse en las dos a la vez
        // si el error venía del padrón original.
        // 🔥 Una ficha COPIADA del escaneo no es una segunda fuente: es la misma. Cotejarla
        // sería compararla consigo misma y saldría bien siempre.
        $cotejable = $guardado !== null && !$guardado->copiadaDelEscaneo
            && $guardado->tieneNumero() && $guardado->tieneNombre();

        // 🔥 **Aquí HUBO un aviso de giro, y estaba en el sitio equivocado.**
        //
        // El giro es una propiedad del ARCHIVO, no del veredicto de un número. Ponerlo aquí traía
        // dos fallos: enderezar obligaba a recalcular el veredicto —una llamada a la IA y una
        // recarga del expediente por cada clic, 20 s— y sólo cubría **el escaneo que respalda ese
        // número**, así que girar el anverso hacía desaparecer el aviso con el reverso todavía
        // torcido. Ahora lo lee la pantalla de `datos_leidos`, escaneo por escaneo.
        $informativas = [];

        if ($discrepancias === [] && $notas === []) {
            if ($verificado) {
                return new self(ValidacionIdentificacionEnum::VALIDADO_MRZ, [], $informativas);
            }

            if ($cotejable) {
                return new self(ValidacionIdentificacionEnum::VALIDADO_OCR, [], $informativas);
            }
        }

        if (!$verificado && !$cotejable) {
            $notas[] = match (true) {
                $guardado === null => 'el archivo no está asignado a ninguna persona del manifiesto',
                $guardado->copiadaDelEscaneo => 'esta ficha se creó copiando el escaneo: hace falta que alguien la confirme',
                !$guardado->tieneNumero() => 'la persona no tenía documento guardado: no hay contra qué cotejar',
                default => 'la persona no tiene nombre guardado: no hay contra qué cotejar',
            };
        }

        // ⚠️ Este aviso va SÓLO cuando ya no se valida. Añadirlo siempre lo convertía en un
        // defecto y **bloqueaba** la validación de todo pasaporte sin banda, que es lo contrario
        // de lo que se quiere.
        //
        // 🔥 **Y sólo cuando la banda es EL MOTIVO de que no se valide**, que es lo que faltaba:
        // salía también encima de una discrepancia, donde no pinta nada. Si el número del escaneo
        // no coincide con el del manifiesto, el problema es ése y el aviso de la banda es ruido
        // tapando lo único que hay que leer. Con algo contra lo que cotejar, la banda ya no es la
        // única salida, así que tampoco hace falta nombrarla.
        //
        // 🔥 Antes decía «hubo que cotejar con el manifiesto» **también cuando no había con qué
        // cotejar**, justo debajo de la nota que dice eso mismo: dos frases seguidas
        // contradiciéndose en la misma tarjeta. Y a un DNI le pedía «revisa la calidad del
        // escaneo» mandando a rehacer una foto impecable, porque la banda se buscaba en el
        // anverso. El aviso ahora dice **qué falta**, no de quién es la culpa.
        if (!$verificado && !$cotejable && $discrepancias === []
            && in_array($leido->tipo, [DocumentoTipoEnum::PASAPORTE, DocumentoTipoEnum::DNI], true)) {
            $notas[] = match (true) {
                $leido->tipo !== DocumentoTipoEnum::DNI => 'no se pudo leer la banda MRZ del escaneo',
                $faltaLaOtraCara => 'falta el reverso del DNI: ahí va la banda que lo valida solo',
                default => 'no se pudo verificar la banda del reverso',
            };
        }

        return new self(ValidacionIdentificacionEnum::OBSERVADO, $discrepancias, [...$notas, ...$informativas]);
    }

    /** @return list<Discrepancia> */
    private static function diferencias(DatosDeDocumento $leido, FichaGuardada $guardado): array
    {
        $diferencias = [];

        if (!self::mismoNumero((string) $leido->numero, (string) $guardado->numero, $leido->tipo, $guardado->tipo)) {
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
        if ($nombreLeido !== '' && $guardado->tieneNombre()
            && !self::mismaPersona($leido, $guardado)) {
            $diferencias[] = new Discrepancia('nombre', $nombreLeido, $guardado->nombreCompleto());
        }

        return $diferencias;
    }

    /**
     * ¿Es el mismo documento? Ignora la forma de escribirlo **y el dígito verificador del DNI**.
     *
     * 🔥 **La tolerancia es SÓLO del DNI, y no acotarla ya tapó un error real.** El DNI peruano
     * son 8 dígitos más uno de control, y cada lado del sistema guarda una convención distinta:
     * de 10 discrepancias de número, 8 eran esa diferencia y ninguna era un fallo.
     *
     * Pero la primera versión aplicaba la regla a **cualquier** tipo, y un pasaporte **no lleva
     * dígito de control**. Resultado, encontrado en producción:
     *
     *     PASAPORTE   manifiesto 1222988343   documento 122298834   →  VALIDADO_MRZ
     *
     * Un número de pasaporte con un dígito de más, en verde y «respaldado por aritmética». Es
     * justo el problema de aeropuerto que este control existe para cazar, y la regla que limpiaba
     * el ruido lo escondió. Un filtro de ruido que se come una señal es peor que el ruido.
     *
     * ⚠️ Por eso ahora se exige **las tres cosas**: que el documento sea un DNI, que las
     * longitudes sean exactamente 8 y 9, y que el largo empiece por el corto. Un DNI truncado a 7
     * dígitos —`7371676` contra `73716768`— vuelve a ser una diferencia, que es lo que es.
     */
    private static function mismoNumero(string $a, string $b, ?DocumentoTipoEnum $tipoLeido, ?string $tipoGuardado): bool
    {
        $limpiar = static fn (string $v): string => strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $v));
        [$uno, $otro] = [$limpiar($a), $limpiar($b)];

        if ($uno === $otro) {
            return true;
        }

        if ($uno === '' || $otro === '') {
            return false;
        }

        // Cualquiera de los dos lados basta para decir «esto es un DNI»: el manifiesto puede
        // tenerlo mal tipado y el documento traer su tipo bien, o al revés.
        $esDni = $tipoLeido === DocumentoTipoEnum::DNI
            || strtoupper((string) $tipoGuardado) === DocumentoTipoEnum::DNI->value;

        if (!$esDni) {
            return false;
        }

        [$corto, $largo] = strlen($uno) < strlen($otro) ? [$uno, $otro] : [$otro, $uno];

        return strlen($corto) === 8 && strlen($largo) === 9 && str_starts_with($largo, $corto);
    }

    /**
     * ¿Son la misma persona? Apellidos **y** nombre de pila, comparados por separado.
     *
     * 🔥 **Con «dos palabras en común» no distinguía a dos hermanos**, que es justo el caso que
     * este control existe para cazar: `PEDRO QUISPE MAMANI` con el número de `JUAN QUISPE MAMANI`
     * compartía los dos apellidos y se validaba. **En una familia, los apellidos son lo que TIENEN
     * en común**; lo que los separa es el nombre de pila.
     *
     * 🔥 Y el primer intento de arreglarlo —mirar «las dos primeras palabras»— también fallaba,
     * porque en «Pedro Quispe Mamani» el apellido cae ahí dentro. La solución no era adivinar
     * mejor: era **dejar de concatenar**. Las dos fuentes traen nombre y apellidos separados de
     * origen, así que se comparan campo con campo y no hay nada que adivinar.
     *
     * Sigue siendo flojo con el orden, las tildes y las partículas: en un padrón un nombre se
     * escribe de quince maneras, y un aviso por cada una haría que nadie mirase la lista.
     */
    private static function mismaPersona(DatosDeDocumento $leido, FichaGuardada $guardado): bool
    {
        $apellidosCoinciden = self::compartenAlguna($leido->apellidos, $guardado->apellidos);
        $nombresCoinciden = self::compartenAlguna($leido->nombres, $guardado->nombres);

        // Si a un lado le falta el campo entero, no se puede exigir: se acepta con el otro. Un
        // padrón a medias es un hueco, no un desacuerdo.
        $hayApellidos = self::palabras((string) $leido->apellidos) !== [] && self::palabras((string) $guardado->apellidos) !== [];
        $hayNombres = self::palabras((string) $leido->nombres) !== [] && self::palabras((string) $guardado->nombres) !== [];

        return (!$hayApellidos || $apellidosCoinciden) && (!$hayNombres || $nombresCoinciden);
    }

    /** ¿Comparten al menos una palabra que distinga? */
    private static function compartenAlguna(?string $a, ?string $b): bool
    {
        return array_intersect(self::palabras((string) $a), self::palabras((string) $b)) !== [];
    }

    /**
     * Las palabras que distinguen, ya normalizadas.
     *
     * 🔥 **Esto usaba `strtr()` con dos cadenas, que opera BYTE A BYTE.** Con nombres acentuados
     * destrozaba la palabra sin dar error: `«José Pérez Núñez»` salía como `JOSO` y `REZ`. En este
     * expediente estaba latente —los doce nombres con tilde compartían otras palabras— pero
     * «José Pérez» a secas habría sacado un «el nombre no coincide» falso.
     *
     * `Transliterator` cubre cualquier alfabeto, no sólo la lista de acentos que uno recuerde.
     *
     * @return list<string>
     */
    private static function palabras(string $texto): array
    {
        static $translit = null;
        $translit ??= \Transliterator::create('Any-Latin; Latin-ASCII; Upper');

        $limpio = $translit?->transliterate($texto) ?: mb_strtoupper($texto);
        $soloLetras = (string) preg_replace('/[^A-Z ]/', ' ', $limpio);

        // Las partículas no distinguen a nadie: «DE», «DEL» y «LA» aparecen en media lista.
        return array_values(array_unique(array_diff(
            array_filter(explode(' ', $soloLetras), static fn (string $p): bool => strlen($p) > 2),
            ['DEL', 'LOS', 'LAS', 'VAN', 'VON'],
        )));
    }
}
