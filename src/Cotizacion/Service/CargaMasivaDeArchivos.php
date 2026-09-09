<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionVuelo;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use ZipArchive;

/**
 * Reparte un ZIP de boarding passes entre las personas y los vuelos del expediente.
 *
 * ── Por qué ────────────────────────────────────────────────────────────────
 * Un grupo de 133 personas que vuela Cusco–Lima, Lima–Panamá y Panamá–Punta Cana ida y vuelta son
 * **~1 060 boarding passes**. Subirlos de uno en uno por un formulario no es una molestia: es
 * inviable.
 *
 * ── La convención del nombre ────────────────────────────────────────────────
 * `DOCUMENTO-VUELO.pdf` — «12345678-DM6771.pdf».
 *
 * El **documento** y no el nombre, porque el nombre trae tildes, se escribe en otro orden y se
 * repite; un DNI no. El **número de vuelo** y no el PNR, porque un PNR cubre ida y vuelta y
 * volvería a no distinguir `DM6771` de `DM6770`.
 *
 * ⚠️ **El separador es tolerante** —guion, guion bajo o espacio— y el orden da igual: se prueban
 * todos los trozos contra los documentos y contra los vuelos. Quien renombra mil ficheros a mano
 * no debería perder la tarde por haber usado `_` en vez de `-`.
 *
 * ── Lo que de verdad protege: la validación cruzada ─────────────────────────
 * 🔥 El camino `pasajero → subgrupo(reserva_aerea) → vuelo` **ya existe**, así que no basta con
 * que el documento y el vuelo existan por separado: se comprueba que **esa persona vuele ese
 * vuelo**. Un renombrado mal hecho —el DNI de uno con el vuelo de otro— se marca en vez de
 * guardarse torcido, que es el fallo que nadie descubriría hasta el gate.
 *
 * ── Nada se guarda sin verse ────────────────────────────────────────────────
 * `planificar()` no escribe: devuelve fila por fila qué haría. Lo que casa se aplica; lo que no,
 * se repasa a mano. Con mil ficheros, aplicar a ciegas es pedir un desastre silencioso.
 */
final readonly class CargaMasivaDeArchivos
{
    /** Un ZIP de boarding passes de un grupo grande cabe de sobra; más que esto, es otra cosa. */
    private const int MAX_ENTRADAS = 2000;

    /** Por fichero. Un boarding pass son ~200 KB; 10 MB es holgado y corta una bomba. */
    private const int MAX_BYTES_ENTRADA = 10 * 1024 * 1024;

    private const array EXTENSIONES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Qué haría con este ZIP, sin tocar nada.
     *
     * @return list<array{fichero: string, pasajero: ?CotizacionFilepasajero, vuelo: ?CotizacionVuelo, problema: ?string, ruta: ?string, reemplaza: bool}>
     */
    public function planificar(CotizacionFile $file, string $rutaZip): array
    {
        $zip = new ZipArchive();

        if ($zip->open($rutaZip) !== true) {
            throw new RuntimeException('No se pudo abrir el ZIP: ¿está completo?');
        }

        if ($zip->numFiles > self::MAX_ENTRADAS) {
            $zip->close();

            throw new RuntimeException(sprintf(
                'El ZIP trae %d ficheros y el tope son %d. Pártelo en varios.',
                $zip->numFiles,
                self::MAX_ENTRADAS,
            ));
        }

        $porDocumento = $this->pasajerosPorDocumento($file);
        [$porVuelo, $ambiguos] = $this->vuelosPorNumero($file);
        $destino = $this->prepararTemporal();

        $plan = [];
        /** @var array<string, string> $nombresOriginales  fichero extraído → nombre del ZIP */
        $nombresOriginales = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $estado = $zip->statIndex($i);

            if ($estado === false) {
                continue;
            }

            $interno = (string) $estado['name'];

            // Carpetas y basura de macOS: un ZIP hecho desde el Finder trae `__MACOSX` y `.DS_Store`
            // y sin esto salen como veinte «ficheros» que no casan con nadie.
            if (str_ends_with($interno, '/') || str_contains($interno, '__MACOSX') || str_starts_with(basename($interno), '.')) {
                continue;
            }

            $nombre = basename(str_replace('\\', '/', $interno));
            $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

            if (!in_array($extension, self::EXTENSIONES, true)) {
                $plan[] = $this->fila($file, $nombre, null, null, sprintf('extensión «%s» no admitida', $extension));
                continue;
            }

            if ((int) $estado['size'] > self::MAX_BYTES_ENTRADA) {
                $plan[] = $this->fila($file, $nombre, null, null, 'pesa más de 10 MB');
                continue;
            }

            [$pasajero, $vuelo] = $this->casar($nombre, $porDocumento, $porVuelo);

            $problema = $this->queFalta($nombre, $pasajero, $vuelo, $ambiguos);

            // ⚠️ Se extrae por índice y con un nombre NUESTRO: `extractTo` con el nombre del ZIP
            // permite `../../` —el «zip slip»— y escribe donde no debe. Aquí el nombre de destino
            // no viene del fichero.
            $ruta = null;
            if ($problema === null) {
                $contenido = $zip->getFromIndex($i);

                if ($contenido === false) {
                    $problema = 'no se pudo leer del ZIP';
                } else {
                    $ruta = $destino . '/' . bin2hex(random_bytes(8)) . '.' . $extension;
                    file_put_contents($ruta, $contenido);
                    $nombresOriginales[basename($ruta)] = $nombre;
                }
            }

            $plan[] = $this->fila($file, $nombre, $pasajero, $vuelo, $problema, $ruta);
        }

        $zip->close();

        // ⚠️ El nombre original se guarda aparte y NO como nombre del fichero extraído: si el
        // fichero se llamara igual que en el ZIP, un `../` en ese nombre escribiría fuera —el
        // «zip slip»—. Así el nombre viaja como dato y el fichero se llama como queremos.
        file_put_contents($destino . '/.nombres', json_encode($nombresOriginales, JSON_UNESCAPED_UNICODE));

        return $plan;
    }

    /**
     * ¿Falta algo para poder guardarlo?
     *
     * El orden de las comprobaciones es el orden en que se entiende el error: primero si se
     * reconoce a la persona, después el vuelo, y sólo entonces si encajan entre sí.
     */
    /** @param array<string, list<string>> $ambiguos */
    private function queFalta(
        string $fichero,
        ?CotizacionFilepasajero $pasajero,
        ?CotizacionVuelo $vuelo,
        array $ambiguos = [],
    ): ?string {
        if ($pasajero === null) {
            return 'no se reconoce el documento';
        }

        if ($vuelo === null) {
            // ⚠️ Antes de decir «no se reconoce», mirar si es que se reconoce DEMASIADO: ese
            // número vuela dos veces y el nombre no dice cuál. Es un error distinto y se arregla
            // distinto —añadiendo la fecha—, así que decirlo importa.
            foreach ($ambiguos as $numero => $fechas) {
                if (in_array($numero, array_map($this->normalizar(...), preg_split('/[-_\s.]+/', pathinfo($fichero, PATHINFO_FILENAME)) ?: []), true)) {
                    return sprintf(
                        'el %s vuela el %s: añade la fecha al nombre',
                        $numero,
                        implode(' y el ', $fechas),
                    );
                }
            }

            return 'no se reconoce el número de vuelo';
        }

        if (!$this->vuelaEseVuelo($pasajero, $vuelo)) {
            return sprintf(
                'esa persona NO vuela el %s — revisa el renombrado',
                (string) $vuelo->getNumero(),
            );
        }

        return null;
    }

    /**
     * ¿Esta persona vuela de verdad ese vuelo?
     *
     * Por el camino que ya existía: sus subgrupos de reserva aérea y los vuelos de cada uno. Es la
     * comprobación que convierte «los dos existen» en «los dos van juntos».
     *
     * ⚠️ **Aquí había un `?? $suyo->getId()`, y decía lo contrario de lo que hay que decir.** Si el
     * vuelo que llega no tuviera id, comparaba el vuelo CONSIGO MISMO: `true` contra el primer
     * vuelo de la persona, o sea **aprobar la pareja sin comprobarla**. Ante la duda, esta función
     * tiene que decir que no — su único trabajo es cazar el renombrado torcido, y un fichero
     * aprobado a ciegas sale en verde en el plan y no se descubre hasta la puerta de embarque.
     *
     * Y sobraba: {@see \Symfony\Component\Uid\AbstractUid::equals()} acepta `mixed` y ya
     * devuelve `false` con un `null`. Era un guarda de más que apagaba el guarda de verdad.
     */
    private function vuelaEseVuelo(CotizacionFilepasajero $pasajero, CotizacionVuelo $vuelo): bool
    {
        foreach ($pasajero->getPertenencias() as $pertenencia) {
            foreach ($pertenencia->getGrupo()?->getVuelos() ?? [] as $suyo) {
                if ($suyo->getId()?->equals($vuelo->getId()) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parte el nombre y prueba cada trozo contra documentos y vuelos.
     *
     * @param array<string, CotizacionFilepasajero> $porDocumento
     * @param array<string, CotizacionVuelo> $porVuelo
     *
     * @return array{0: ?CotizacionFilepasajero, 1: ?CotizacionVuelo}
     */
    private function casar(string $nombre, array $porDocumento, array $porVuelo): array
    {
        $sinExtension = pathinfo($nombre, PATHINFO_FILENAME);
        $trozos = preg_split('/[-_\s.]+/', $sinExtension) ?: [];

        $pasajero = null;
        $vuelo = null;

        foreach ($trozos as $trozo) {
            $clave = $this->normalizar($trozo);

            if ($clave === '') {
                continue;
            }

            $pasajero ??= $porDocumento[$clave] ?? null;
            $vuelo ??= $porVuelo[$clave] ?? null;
        }

        return [$pasajero, $vuelo];
    }

    /**
     * Sin espacios, sin guiones y en mayúsculas.
     *
     * ⚠️ El número de vuelo se guarda a veces con espacio —«H2 5002»— y quien renombra escribe
     * «H25002». Normalizando los dos lados, casan.
     */
    private function normalizar(string $texto): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $texto));
    }

    /** @return array<string, CotizacionFilepasajero> */
    private function pasajerosPorDocumento(CotizacionFile $file): array
    {
        $mapa = [];

        foreach ($file->getFilepasajeros() as $pasajero) {
            foreach ($pasajero->getIdentificaciones() as $identificacion) {
                $clave = $this->normalizar((string) $identificacion->getNumero());

                if ($clave !== '') {
                    $mapa[$clave] = $pasajero;
                }
            }
        }

        return $mapa;
    }

    /**
     * Los vuelos del expediente por número — y 🔥 **los repetidos se quedan FUERA**.
     *
     * `CotizacionVuelo` es único por `(numero, fecha)`, no por número: el JA7027 vuela el 25 y el
     * 27. Con un mapa `numero → vuelo`, el último gana y `12345678-JA7027.pdf` se archiva contra
     * el tramo equivocado. Y no salta ninguna alarma: la persona vuela los dos —mismo PNR—, así
     * que `vuelaEseVuelo()` dice que sí y el plan lo pinta en verde. El pasajero abre en la puerta
     * el boarding pass del otro día.
     *
     * Por eso el ambiguo no se adivina: se saca del mapa y {@see self::queFalta()} lo cuenta,
     * para que el operador ponga la fecha en el nombre.
     *
     * @return array{0: array<string, CotizacionVuelo>, 1: array<string, list<string>>}
     *         el mapa utilizable, y los números ambiguos con sus fechas para explicarlo
     */
    private function vuelosPorNumero(CotizacionFile $file): array
    {
        /** @var array<string, list<CotizacionVuelo>> $porNumero */
        $porNumero = [];

        foreach ($file->getVuelos() as $vuelo) {
            $clave = $this->normalizar((string) $vuelo->getNumero());

            if ($clave !== '') {
                $porNumero[$clave][] = $vuelo;
            }
        }

        $mapa = [];
        $ambiguos = [];

        foreach ($porNumero as $clave => $vuelos) {
            if (count($vuelos) === 1) {
                $mapa[$clave] = $vuelos[0];

                continue;
            }

            $ambiguos[$clave] = array_map(
                static fn (CotizacionVuelo $v): string => ($v->getSalida() ?? $v->getFecha())?->format('d/m') ?? '?',
                $vuelos,
            );
        }

        return [$mapa, $ambiguos];
    }

    /**
     * @return array{fichero: string, pasajero: ?CotizacionFilepasajero, vuelo: ?CotizacionVuelo, problema: ?string, ruta: ?string, reemplaza: bool}
     */
    private function fila(
        CotizacionFile $file,
        string $fichero,
        ?CotizacionFilepasajero $pasajero,
        ?CotizacionVuelo $vuelo,
        ?string $problema,
        ?string $ruta = null,
    ): array {
        $reemplaza = $problema === null && $this->boletoPrevio($file, $pasajero, $vuelo) !== null;

        return compact('fichero', 'pasajero', 'vuelo', 'problema', 'ruta', 'reemplaza');
    }

    /**
     * El boarding pass que esa persona YA tiene para ese vuelo, si lo hay.
     *
     * 🔥 **Es lo que impide que el pasajero acabe con dos.** Los ZIP llegan dos y tres veces —uno
     * corregido, otro con los que faltaban, otro «por si acaso»— y sin esto cada pasada añade una
     * copia más. En el gate eso no es un duplicado: es el cliente eligiendo entre dos documentos
     * sin saber cuál vale, que es justo lo que esta pantalla existe para evitar.
     */
    private function boletoPrevio(
        CotizacionFile $file,
        ?CotizacionFilepasajero $pasajero,
        ?CotizacionVuelo $vuelo,
    ): ?CotizacionFilearchivo {
        if ($pasajero === null || $vuelo === null) {
            return null;
        }

        foreach ($file->getFilearchivos() as $previo) {
            if ($previo->getTipoArchivo() !== ArchivoTipoEnum::BOLETO) {
                continue;
            }

            $mismoPasajero = $previo->getPasajero()?->getId()?->equals($pasajero->getId() ?? $previo->getId()) === true;
            $mismoVuelo = $previo->getVuelo()?->getId()?->equals($vuelo->getId() ?? $previo->getId()) === true;

            if ($mismoPasajero && $mismoVuelo) {
                return $previo;
            }
        }

        return null;
    }

    /** Una carpeta por carga. La borra {@see self::limpiar()}, no se va sola. */
    private function prepararTemporal(): string
    {
        $this->barrerCargasViejas();

        $ruta = $this->projectDir . '/var/documentos/zip-' . bin2hex(random_bytes(6));

        if (!is_dir($ruta) && !mkdir($ruta, 0o775, true) && !is_dir($ruta)) {
            throw new RuntimeException('No se pudo preparar la carpeta temporal de la carga.');
        }

        return $ruta;
    }

    /**
     * Aplica una carga ya extraída, **recalculando a quién pertenece cada fichero**.
     *
     * ⚠️ **No se confía en lo que diga el navegador.** Si el cliente pudiera mandar «este fichero
     * es de esta persona», bastaría con editar la petición para colgarle a alguien el boarding
     * pass de otro. El nombre original viaja dentro del propio fichero extraído —se guardó con él
     * en un `.nombres`— y de ahí se vuelve a casar contra el padrón.
     *
     * @return list<CotizacionFilearchivo>
     */
    public function aplicarDesdeCarpeta(CotizacionFile $file, string $carpeta): array
    {
        $ruta = $this->projectDir . '/var/documentos/' . $carpeta;
        $indice = $ruta . '/.nombres';

        if (!is_dir($ruta) || !is_file($indice)) {
            throw new RuntimeException('Esa carga ya no está: vuelve a subir el ZIP.');
        }

        /** @var array<string, string> $nombres  fichero extraído → nombre original */
        $nombres = json_decode((string) file_get_contents($indice), true) ?: [];

        $porDocumento = $this->pasajerosPorDocumento($file);
        [$porVuelo, $ambiguos] = $this->vuelosPorNumero($file);

        $plan = [];

        foreach ($nombres as $extraido => $original) {
            $fichero = $ruta . '/' . basename((string) $extraido);

            if (!is_file($fichero)) {
                continue;
            }

            [$pasajero, $vuelo] = $this->casar((string) $original, $porDocumento, $porVuelo);
            $plan[] = $this->fila($file, (string) $original, $pasajero, $vuelo, $this->queFalta((string) $original, $pasajero, $vuelo, $ambiguos), $fichero);
        }

        return $this->aplicar($file, $plan);
    }

    /**
     * Convierte en adjuntos las filas del plan que no tienen problema.
     *
     * @param list<array{fichero: string, pasajero: ?CotizacionFilepasajero, vuelo: ?CotizacionVuelo, problema: ?string, ruta: ?string, reemplaza: bool}> $plan
     *
     * @return list<CotizacionFilearchivo>
     */
    public function aplicar(CotizacionFile $file, array $plan): array
    {
        $creados = [];
        /** @var array<string, true> $vistos  pasajero|vuelo ya servido en esta misma pasada */
        $vistos = [];

        foreach ($plan as $fila) {
            if ($fila['problema'] !== null || $fila['ruta'] === null || !is_file($fila['ruta'])) {
                continue;
            }

            // ⚠️ Se vuelve a buscar aquí y no se confía en el `reemplaza` del plan: entre la
            // previsualización y el «guardar» pudo entrar otro ZIP.
            $previo = $this->boletoPrevio($file, $fila['pasajero'], $fila['vuelo']);

            if ($previo !== null) {
                $this->em->remove($previo);
            }

            // 🔥 **Y el duplicado dentro del PROPIO ZIP.** `setFile()` es un setter plano: el
            // adjunto nuevo no entra en `$file->getFilearchivos()`, así que `boletoPrevio()` sólo
            // ve la base y dos entradas que casan igual —`12345678-DM6771.pdf` y
            // `12345678_DM6771.jpg`, o el mismo nombre en `ida/` y en `vuelta/`— se creaban las
            // dos. Es exactamente el duplicado que todo esto viene a evitar, colado por dentro.
            $huella = sprintf('%s|%s', (string) $fila['pasajero']?->getId(), (string) $fila['vuelo']?->getId());

            if (isset($vistos[$huella])) {
                continue;
            }

            $vistos[$huella] = true;

            $archivo = new CotizacionFilearchivo();
            $archivo->setFile($file);
            $archivo->setTipoArchivo(ArchivoTipoEnum::BOLETO);
            $archivo->setPasajero($fila['pasajero']);
            $archivo->setVuelo($fila['vuelo']);
            $archivo->setNombre([[
                'language' => 'es',
                'content' => sprintf(
                    '%s %s → %s',
                    (string) $fila['vuelo']?->getNumero(),
                    (string) $fila['vuelo']?->getOrigen(),
                    (string) $fila['vuelo']?->getDestino(),
                ),
            ]]);

            // Vich se encarga del nombre en disco y de moverlo al destino privado.
            $archivo->setImageFile(new File($fila['ruta']));

            $creados[] = $archivo;
        }

        return $creados;
    }

    /**
     * Borra una carga extraída.
     *
     * 🔥 **Hay que llamarla DESPUÉS del `flush()`, nunca antes.** Vich no mueve el fichero: para un
     * `File` que no es un `UploadedFile` hace `copy()` —lo hemos leído en `FileSystemStorage`—, y
     * esa copia ocurre al guardar. Borrar la carpeta antes deja los adjuntos sin contenido.
     *
     * Y por eso mismo la carpeta hay que borrarla: si Vich moviera, se vaciaría sola. Como copia,
     * cada ZIP aplicado dejaba **el extracto entero duplicado**, para siempre.
     */
    public function limpiar(string $carpeta): void
    {
        if (!preg_match('/^zip-[0-9a-f]{12}$/', $carpeta)) {
            return;
        }

        $this->borrarCarpeta($this->projectDir . '/var/documentos/' . $carpeta);
    }

    /**
     * Las cargas que nadie aplicó ni descartó.
     *
     * ⚠️ El operador que sube un ZIP, ve 40 fallos y cierra la pestaña **no pasa por ningún
     * endpoint**: su extracto se queda ahí. Con ZIP de cientos de megas y tres o cuatro intentos
     * hasta acertar con el renombrado, eso es lo que llena el disco — y un disco lleno aquí ya
     * tumbó producción una vez.
     *
     * Un día es de sobra: la revisión se hace en el momento.
     */
    private function barrerCargasViejas(): void
    {
        $limite = time() - 86400;

        foreach (glob($this->projectDir . '/var/documentos/zip-*') ?: [] as $vieja) {
            if (is_dir($vieja) && (int) filemtime($vieja) < $limite) {
                $this->borrarCarpeta($vieja);
            }
        }
    }

    private function borrarCarpeta(string $ruta): void
    {
        if (!is_dir($ruta)) {
            return;
        }

        // ⚠️ `scandir()` y no `glob()`: el índice se llama `.nombres` y `glob()` no ve los
        // ocultos, así que la carpeta nunca quedaría vacía y `rmdir()` fallaría en silencio.
        foreach (scandir($ruta) ?: [] as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }

            if (is_file($ruta . '/' . $entrada)) {
                unlink($ruta . '/' . $entrada);
            }
        }

        rmdir($ruta);
    }
}
