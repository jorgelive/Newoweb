<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Enum\DocumentoTipoEnum;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Transliterator;
use Vich\UploaderBundle\Storage\StorageInterface;
use ZipArchive;

/**
 * El ZIP de escaneos de identidad que se le manda al hotel.
 *
 * ── Para qué ────────────────────────────────────────────────────────────────
 * Un alojamiento pide los documentos de los huéspedes antes de la llegada —en Perú los necesita
 * para el registro—. Hasta ahora eso se hacía descargando los ficheros uno a uno de la bóveda y
 * renombrándolos a mano: con un grupo de treinta es media tarde y un reparto que se tuerce.
 *
 * ── La convención del nombre, y por qué NO es la de entrada ─────────────────
 * Al subir por lote la convención es `DOCUMENTO-VUELO` ({@see CargaMasivaDeArchivos}), y su
 * docblock explica por qué el número y no el nombre: «el nombre trae tildes, se escribe en otro
 * orden y se repite; un DNI no». Ahí el lector es una MÁQUINA que tiene que casar sin ambigüedad.
 *
 * Aquí el lector es **una persona en recepción** que busca a un huésped por su apellido en una
 * lista. Por eso el nombre va delante y el número detrás:
 *
 *     Diaz Arredondo, Ray Dante - PAS 125998545.jpg
 *     Quispe Mamani, Ana - DNI 46523178.jpg
 *
 * El apellido primero hace que el ZIP salga **ordenado por persona** al abrirlo, que es como se
 * recorre. El número sigue estando porque es lo único que no se repite, y el prefijo del tipo
 * distingue a quien manda pasaporte y DNI.
 *
 * ⚠️ **Los nombres van sin tildes ni «ñ», y no es descuido.** Un ZIP recorrido en Windows con las
 * herramientas del sistema sigue interpretando los nombres en CP437: `Núñez` llega como `NÃºÃ±ez`
 * al otro lado. Se transliteran con la misma pieza que ya usa el resto del proyecto.
 *
 * ── Lo que NO entra, y por qué se dice ──────────────────────────────────────
 * Un escaneo **sin pasajero asignado** no se puede nombrar: no sabemos de quién es. En vez de
 * meterlo con su nombre original —que es como se cuela el pasaporte de alguien en el sobre de
 * otro— se queda fuera y aparece contado en `LEEME.txt`. Lo mismo con quien no tiene ningún
 * escaneo: el hueco se dice, no se calla.
 *
 * 🔥 **Dentro va también la hoja del manifiesto**, la misma que genera {@see ReporteDeDocumentos}.
 * Es lo que convierte un montón de imágenes en algo que el hotel puede cotejar, y no cuesta nada:
 * ese informe ya existía.
 */
final readonly class PaqueteDeEscaneos
{
    /**
     * Qué escaneos se mandan.
     *
     * El reverso del DNI entra —el registro peruano lo pide— y la autorización notarial no: es un
     * permiso de viaje de un menor, no una identidad, y no es lo que el hotel está pidiendo.
     */
    private const TIPOS = [
        ArchivoTipoEnum::PASAPORTE,
        ArchivoTipoEnum::DNI_ANVERSO,
        ArchivoTipoEnum::DNI_REVERSO,
    ];

    /** Prefijo corto por tipo: cabe en el nombre y se lee de un vistazo. */
    private const PREFIJO = [
        'pasaporte' => 'PAS',
        'dni_anverso' => 'DNI',
        'dni_reverso' => 'DNI-rev',
    ];

    public function __construct(
        private StorageInterface $almacen,
        private ReporteDeDocumentos $reporte,
        private LoggerInterface $logger,
    ) {}

    /**
     * Arma el ZIP y devuelve su ruta temporal. Quien llama es responsable de borrarla.
     *
     * ⚠️ **Devuelve una RUTA y no los bytes.** Un expediente grande son decenas de escaneos a
     * 2400 px: cargarlo entero en memoria para devolverlo como string es pedir un
     * `memory_limit` justo el día que alguien exporte el grupo de sesenta. Con la ruta, el
     * controlador lo sirve con `BinaryFileResponse`, que va en trozos.
     *
     * @param list<string>|null $soloEstos Ids de pasajero, o `null` para todo el expediente.
     */
    public function generar(CotizacionFile $file, ?array $soloEstos = null): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'escaneos_');

        if ($ruta === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del ZIP.');
        }

        $zip = new ZipArchive();

        if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo abrir el ZIP para escribir.');
        }

        $permitidos = $soloEstos === null ? null : array_flip($soloEstos);
        $incluidos = 0;
        $sinEscaneo = [];
        $usados = [];

        foreach ($file->getFilepasajeros() as $pasajero) {
            if ($permitidos !== null && !isset($permitidos[(string) $pasajero->getId()])) {
                continue;
            }

            $suyos = 0;

            foreach ($this->escaneosDe($file, $pasajero) as $archivo) {
                $origen = $this->rutaFisica($archivo);

                if ($origen === null) {
                    continue;
                }

                $nombre = $this->nombreEnElZip($pasajero, $archivo, $origen, $usados);
                $usados[$nombre] = true;

                if ($zip->addFile($origen, $nombre)) {
                    // ⚠️ **Sin comprimir, y está medido.** Un JPEG ya está comprimido: sobre los
                    // 374 escaneos del expediente más grande (104 MB), DEFLATE tarda 4,2 s y
                    // deja 107,4 MB; STORE tarda 0,5 s y deja 107,7 MB. Se pagan casi cuatro
                    // segundos de CPU por el 0,3 % — y php-fpm corta a los 90 s.
                    $zip->setCompressionIndex($zip->numFiles - 1, ZipArchive::CM_STORE);
                    $incluidos++;
                    $suyos++;
                }
            }

            if ($suyos === 0) {
                $sinEscaneo[] = $this->personaEnUnaLinea($pasajero);
            }
        }

        // La hoja del manifiesto: los datos, para que las imágenes se puedan cotejar.
        // Estos dos SÍ se comprimen: son texto, y ahí DEFLATE sí gana.
        $zip->addFromString('manifiesto.xlsx', $this->reporte->generar($file, $soloEstos));
        $zip->addFromString('LEEME.txt', $this->leeme($file, $incluidos, $sinEscaneo, $this->sueltos($file)));

        $zip->close();

        $this->logger->info('Paquete de escaneos generado.', [
            'expediente' => (string) $file->getId(),
            'escaneos' => $incluidos,
            'sin_escaneo' => count($sinEscaneo),
        ]);

        return $ruta;
    }

    /**
     * Los escaneos de identidad de esta persona.
     *
     * Se recorre desde el EXPEDIENTE y se filtra por pasajero, y no al revés, porque el archivo
     * es quien apunta al pasajero ({@see CotizacionFilearchivo::getPasajero()}): no hay colección
     * inversa que recorrer.
     *
     * @return list<CotizacionFilearchivo>
     */
    private function escaneosDe(CotizacionFile $file, CotizacionFilepasajero $pasajero): array
    {
        $suyos = [];

        foreach ($file->getFilearchivos() as $archivo) {
            $tipo = $archivo->getTipoArchivo();

            if ($tipo === null || !in_array($tipo, self::TIPOS, true)) {
                continue;
            }

            $dueno = $archivo->getPasajero();

            if ($dueno !== null && (string) $dueno->getId() === (string) $pasajero->getId()) {
                $suyos[] = $archivo;
            }
        }

        return $suyos;
    }

    /**
     * `Apellidos, Nombres - PAS 125998545.jpg`
     *
     * El número sale de la identificación del manifiesto que ESE escaneo respalda
     * ({@see ArchivoTipoEnum::respaldaA()}, la única definición de esa pareja). Si la persona no
     * tiene ese número cargado, el nombre se queda sin él en vez de inventarse otro: un pasaporte
     * etiquetado con el número del DNI es peor que uno sin número.
     *
     * @param array<string, true> $usados
     */
    private function nombreEnElZip(
        CotizacionFilepasajero $pasajero,
        CotizacionFilearchivo $archivo,
        string $origen,
        array $usados,
    ): string {
        $tipo = $archivo->getTipoArchivo();
        $prefijo = self::PREFIJO[$tipo?->value] ?? 'DOC';
        $numero = $this->numeroQueRespalda($pasajero, $tipo?->respaldaA());

        $base = sprintf(
            '%s - %s%s',
            $this->personaEnUnaLinea($pasajero),
            $prefijo,
            $numero === null ? '' : ' ' . $numero,
        );

        $extension = strtolower(pathinfo($origen, PATHINFO_EXTENSION)) ?: 'jpg';
        $nombre = $this->sinTildes($base) . '.' . $extension;

        // Dos escaneos del mismo tipo para la misma persona —pasa: dos páginas del pasaporte—.
        // Se numeran en vez de pisarse: `addFile` con un nombre repetido deja uno solo dentro.
        $n = 2;
        while (isset($usados[$nombre])) {
            $nombre = $this->sinTildes($base) . ' (' . $n++ . ').' . $extension;
        }

        return $nombre;
    }

    private function numeroQueRespalda(CotizacionFilepasajero $pasajero, ?DocumentoTipoEnum $tipo): ?string
    {
        if ($tipo === null) {
            return null;
        }

        foreach ($pasajero->getIdentificaciones() as $identificacion) {
            if ($identificacion->getTipo() === $tipo) {
                $numero = trim((string) $identificacion->getNumero());

                return $numero === '' ? null : $numero;
            }
        }

        return null;
    }

    private function personaEnUnaLinea(CotizacionFilepasajero $pasajero): string
    {
        return trim(sprintf(
            '%s, %s',
            trim((string) $pasajero->getApellido()),
            trim((string) $pasajero->getNombre()),
        ), ', ');
    }

    /**
     * Sin tildes, sin «ñ» y sin lo que no cabe en un nombre de fichero.
     *
     * Misma pieza que usa el resto del proyecto para normalizar nombres. Ver el aviso de la
     * cabecera: un ZIP recorrido en Windows con las herramientas del sistema todavía interpreta
     * los nombres en CP437.
     */
    private function sinTildes(string $texto): string
    {
        static $translit = null;
        $translit ??= Transliterator::create('Any-Latin; Latin-ASCII');

        $plano = $translit?->transliterate($texto) ?? $texto;
        $plano = (string) preg_replace('/[^A-Za-z0-9 ,._()-]+/', '', $plano);

        return trim((string) preg_replace('/\s+/', ' ', $plano));
    }

    /** Ruta física del escaneo, o `null` si el fichero no está donde dice la base. */
    private function rutaFisica(CotizacionFilearchivo $archivo): ?string
    {
        $ruta = $this->almacen->resolvePath($archivo, 'imageFile');

        if ($ruta === null || !is_file($ruta)) {
            $this->logger->warning('Escaneo sin fichero en disco: se queda fuera del paquete.', [
                'archivo' => (string) $archivo->getId(),
                'imageName' => $archivo->getImageName(),
            ]);

            return null;
        }

        return $ruta;
    }

    /** Cuántos escaneos de identidad hay sin dueño: no se pueden nombrar, así que no viajan. */
    private function sueltos(CotizacionFile $file): int
    {
        $n = 0;

        foreach ($file->getFilearchivos() as $archivo) {
            $tipo = $archivo->getTipoArchivo();

            if ($tipo !== null && in_array($tipo, self::TIPOS, true) && $archivo->getPasajero() === null) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * El texto que explica el sobre.
     *
     * ⚠️ **Los huecos se cuentan dentro del propio paquete.** Quien lo reenvía al hotel no va a
     * volver al panel a comprobar quién falta, así que si falta alguien tiene que enterarse
     * abriendo el ZIP — que es lo único que va a abrir.
     *
     * @param list<string> $sinEscaneo
     */
    private function leeme(CotizacionFile $file, int $incluidos, array $sinEscaneo, int $sueltos): string
    {
        $lineas = [
            sprintf('Escaneos de identidad — %s', (string) $file->getNombreGrupo()),
            sprintf('Generado el %s', date('d/m/Y H:i')),
            '',
            sprintf('%d escaneos incluidos.', $incluidos),
            'La hoja «manifiesto.xlsx» lleva los datos de cada persona.',
            '',
            'Los ficheros se llaman: Apellidos, Nombres - TIPO numero.ext',
        ];

        if ($sinEscaneo !== []) {
            $lineas[] = '';
            $lineas[] = sprintf('FALTAN los documentos de %d persona(s):', count($sinEscaneo));
            foreach ($sinEscaneo as $quien) {
                $lineas[] = '  - ' . $quien;
            }
        }

        if ($sueltos > 0) {
            $lineas[] = '';
            $lineas[] = sprintf(
                'Hay %d escaneo(s) en el expediente sin asignar a nadie. NO van en este paquete: '
                . 'sin saber de quién son no se pueden nombrar, y mandarlos con su nombre original '
                . 'es como el documento de alguien acaba en el sobre de otro.',
                $sueltos,
            );
        }

        return implode("\n", $lineas) . "\n";
    }
}
