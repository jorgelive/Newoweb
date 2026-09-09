<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Enum\DocumentoTipoEnum;
use DateTimeImmutable;
use Throwable;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * El proceso de control: lee el escaneo, lo coteja con el manifiesto y **propone** qué hacer.
 *
 * ⚠️ **No escribe nada.** Devuelve un {@see ResultadoDeValidacion} y quien lo llama decide si lo
 * aplica — mismo reparto que la carga por ZIP, y por el mismo motivo: aquí lo que se escribiría
 * son personas del manifiesto, y con cientos de documentos escribir a ciegas es pedir un desastre
 * callado.
 *
 * ### Los tres caminos, que son los tres estados del expediente
 *
 * ```
 *   ¿el archivo tiene dueño?
 *     sí  → cotejar contra SU ficha                       → VALIDADO / OBSERVADO
 *     no  → ¿hay alguien con ese MISMO NÚMERO?
 *             sí  → proponer asociar          (seguro)
 *             no  → ¿hay alguien con ese NOMBRE?
 *                     sí  → proponer asociar  (sugerencia: hay que mirarlo)
 *                     no  → proponer CREAR la ficha
 * ```
 *
 * 🔑 **El orden importa y no es negociable.** Primero el número, que es la clave natural del
 * documento y no se escribe de quince maneras; el nombre sólo cuando no hay número que case.
 * `PadronImportador` llegó a la misma conclusión por las malas —«los nombres se escriben mal»— y
 * **aborta** ante dos personas con el mismo documento porque una vez una sobrescribió a otra y
 * desapareció del padrón. Aquí no se sobrescribe nada, pero la jerarquía se respeta igual.
 */
final readonly class ValidadorDeDocumento
{
    public function __construct(
        private LectorDeDocumentoIdentidad $lector,
        private StorageInterface $almacen,
    ) {}

    public function analizar(CotizacionFilearchivo $archivo): ResultadoDeValidacion
    {
        $leido = $this->lecturaDe($archivo);
        if ($leido === null) {
            return ResultadoDeValidacion::ilegible($archivo->getLecturaError() ?? 'no se pudo leer el documento');
        }

        $dueno = $archivo->getPasajero();

        if ($dueno !== null) {
            return ResultadoDeValidacion::de(
                Cotejo::de($leido, $this->fichaDe($dueno, $leido->tipo)),
                $leido,
                Accion::NINGUNA,
            );
        }

        // Sin dueño no hay contra qué cotejar, así que el cotejo sale OBSERVADO — y **además** se
        // busca a quién podría pertenecer. Las dos cosas a la vez: el estado describe el
        // documento, la acción describe el trabajo pendiente.
        $cotejo = Cotejo::de($leido, null);
        [$candidato, $motivo] = $this->buscarDueno($archivo, $leido);

        if ($candidato === null) {
            return ResultadoDeValidacion::de(
                $cotejo,
                $leido,
                Accion::CREAR,
                motivo: 'nadie del manifiesto casa por número ni por nombre',
            );
        }

        return ResultadoDeValidacion::de($cotejo, $leido, Accion::ASOCIAR, $candidato, $motivo);
    }

    /**
     * La lectura del documento, **pagando la IA como mucho una vez en su vida**.
     *
     * 🔑 Si ya se leyó, se reinterpreta lo guardado —gratis, sin red— y encima **con el criterio
     * de hoy**: afinar una regla no obliga a releer nada. Si nunca se leyó, se lee y se guarda.
     *
     * ⚠️ **Un error de lectura también se guarda.** Sin eso, un documento ilegible y otro que
     * nunca se intentó son indistinguibles —los dos con la lectura vacía— y la tanda lo
     * reintentaría en cada pasada, pagando cada vez por el mismo fallo.
     */
    public function lecturaDe(CotizacionFilearchivo $archivo): ?DatosDeDocumento
    {
        $guardado = $archivo->getDatosLeidos();
        if ($guardado !== null) {
            return $this->lector->interpretar($guardado);
        }

        if ($archivo->seIntentoLeer()) {
            return null;   // se intentó y falló; el motivo está en `lecturaError`
        }

        $ruta = $this->almacen->resolvePath($archivo, 'imageFile');
        if (!is_string($ruta) || !is_readable($ruta)) {
            $archivo->registrarLectura(null, 'el fichero no está en disco');

            return null;
        }

        try {
            $crudo = $this->lector->extraer((string) file_get_contents($ruta), (string) mime_content_type($ruta));
        } catch (Throwable $e) {
            // No se propaga: en una tanda de cien, uno ilegible no puede parar los otros 99.
            $archivo->registrarLectura(null, mb_substr($e->getMessage(), 0, 255));

            return null;
        }

        $archivo->registrarLectura($crudo);

        return $this->lector->interpretar($crudo);
    }

    /**
     * Su ficha para ese tipo de documento. Si no tiene una de ese tipo, se coteja contra la que
     * tenga: alguien con DNI guardado que sube su pasaporte no es un desacuerdo, y `Cotejo` ya
     * sabe decir «es un PASAPORTE y está guardado como DNI».
     */
    private function fichaDe(CotizacionFilepasajero $pasajero, ?DocumentoTipoEnum $tipo): FichaGuardada
    {
        $identificaciones = $pasajero->getIdentificaciones();
        $elegida = null;

        foreach ($identificaciones as $identificacion) {
            if ($tipo !== null && $identificacion->getTipo() === $tipo) {
                $elegida = $identificacion;
                break;
            }
            $elegida ??= $identificacion;
        }

        $vencimiento = $elegida?->getVencimiento();

        $nacimiento = $pasajero->getFechanacimiento();

        return new FichaGuardada(
            numero: $elegida?->getNumero(),
            tipo: $elegida?->getTipo()?->value,
            vencimiento: $vencimiento !== null ? DateTimeImmutable::createFromInterface($vencimiento) : null,
            nombreCompleto: trim(($pasajero->getNombre() ?? '') . ' ' . ($pasajero->getApellido() ?? '')),
            nacimiento: $nacimiento !== null ? DateTimeImmutable::createFromInterface($nacimiento) : null,
            // El id de `MaestroPais` ES el ISO-2, así que no hay nada que traducir de este lado.
            nacionalidad: $pasajero->getPais()?->getId(),
        );
    }

    /**
     * A quién podría pertenecer, dentro de SU expediente. **Todos los candidatos**, no el mejor.
     *
     * ⚠️ **Dentro de su expediente y sólo ahí.** Buscar por todo el sistema encontraría al mismo
     * DNI en otro grupo del año pasado y propondría cruzar dos expedientes — que es justo lo que
     * `validarDuenoDelMismoExpediente()` prohíbe al guardar.
     *
     * 🔥 **Devuelve la lista entera y NO resuelve la ambigüedad.** Una versión anterior devolvía
     * `null` cuando había dos personas con el mismo nombre, que es «no sé» dicho de la peor
     * manera: el panel no podía ofrecer las dos y quien miraba tenía que buscarlas a mano. Dos
     * hermanos con los mismos apellidos no son un fallo del buscador — son el caso normal de una
     * familia, y lo único que falta es que alguien señale cuál.
     *
     * @return list<Candidato>
     */
    public function candidatosPara(CotizacionFilearchivo $archivo, DatosDeDocumento $leido): array
    {
        $numero = self::soloAlfanumerico((string) $leido->numero);
        $seguros = [];
        $porNombre = [];

        foreach ($archivo->getFile()?->getFilepasajeros() ?? [] as $pasajero) {
            $casaNumero = false;
            foreach ($pasajero->getIdentificaciones() as $identificacion) {
                if ($numero !== '' && self::soloAlfanumerico((string) $identificacion->getNumero()) === $numero) {
                    $casaNumero = true;
                    break;
                }
            }

            if ($casaNumero) {
                $seguros[] = Candidato::porNumero($pasajero, (string) $leido->numero);
                continue;
            }

            if (self::mismoNombre($leido, $pasajero)) {
                $porNombre[] = Candidato::porNombre($pasajero);
            }
        }

        // Los seguros delante, pero **sin descartar los otros**: si el número casa con alguien y
        // el nombre con otro, eso es una contradicción que hay que poder ver, no esconder.
        return [...$seguros, ...$porNombre];
    }

    /**
     * @return array{CotizacionFilepasajero|null, string}
     */
    private function buscarDueno(CotizacionFilearchivo $archivo, DatosDeDocumento $leido): array
    {
        $candidatos = $this->candidatosPara($archivo, $leido);

        if ($candidatos === []) {
            return [null, ''];
        }

        $seguros = array_values(array_filter($candidatos, static fn (Candidato $c): bool => $c->esSeguro()));
        if (count($seguros) === 1) {
            return [$seguros[0]->pasajero, $seguros[0]->motivo];
        }

        // 🔥 Varios candidatos no se resuelve adivinando: es el caso de las familias, y elegir uno
        // sería colgarle a alguien el documento de su hermano con cara de acierto.
        if (count($candidatos) > 1) {
            return [null, sprintf('hay %d personas posibles: hay que elegir a mano', count($candidatos))];
        }

        return [$candidatos[0]->pasajero, $candidatos[0]->motivo];
    }

    private static function mismoNombre(DatosDeDocumento $leido, CotizacionFilepasajero $pasajero): bool
    {
        $leidoCompleto = trim(($leido->nombres ?? '') . ' ' . ($leido->apellidos ?? ''));
        $suyo = trim(($pasajero->getNombre() ?? '') . ' ' . ($pasajero->getApellido() ?? ''));

        if ($leidoCompleto === '' || $suyo === '') {
            return false;
        }

        // Se reutiliza el mismo criterio flojo del cotejo: si allí «ERIKSSON, Anna María» y «Anna
        // Maria Eriksson» son la misma persona, aquí tienen que serlo también, o el proceso se
        // contradice consigo mismo según por qué rama entre.
        return Cotejo::de(
            new DatosDeDocumento(numero: 'x', nombres: $leido->nombres, apellidos: $leido->apellidos),
            new FichaGuardada(numero: 'x', nombreCompleto: $suyo),
        )->discrepancias === [];
    }

    private static function soloAlfanumerico(string $valor): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $valor));
    }
}
