<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Enum\DocumentoTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
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
        private EntityManagerInterface $em,
    ) {}

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
            $this->em->flush();

            return null;
        }

        $archivo->registrarLectura($crudo);

        // 🔥 **Se guarda AQUÍ, no al final de la tanda.** La lectura es lo único caro —3,5 s y
        // dinero— y php-fpm corta a los 90 s: un expediente nuevo son ~183 lecturas, o sea diez
        // minutos. Con un solo `flush()` al final, el corte tiraba **todas las lecturas ya
        // pagadas** y la siguiente pulsación volvía a pagarlas para tampoco terminar. Guardando
        // según se leen, cada pulsación avanza lo que le dé tiempo y nada se paga dos veces.
        $this->em->flush();

        return $this->lector->interpretar($crudo);
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

    private static function mismoNombre(DatosDeDocumento $leido, CotizacionFilepasajero $pasajero): bool
    {
        if (trim(($leido->nombres ?? '') . ($leido->apellidos ?? '')) === ''
            || trim(($pasajero->getNombre() ?? '') . ($pasajero->getApellido() ?? '')) === '') {
            return false;
        }

        // Se reutiliza el mismo criterio flojo del cotejo: si allí «ERIKSSON, Anna María» y «Anna
        // Maria Eriksson» son la misma persona, aquí tienen que serlo también, o el proceso se
        // contradice consigo mismo según por qué rama entre.
        return Cotejo::de(
            new DatosDeDocumento(numero: 'x', nombres: $leido->nombres, apellidos: $leido->apellidos),
            new FichaGuardada(numero: 'x', nombres: $pasajero->getNombre(), apellidos: $pasajero->getApellido()),
        )->discrepancias === [];
    }

    private static function soloAlfanumerico(string $valor): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $valor));
    }
}
