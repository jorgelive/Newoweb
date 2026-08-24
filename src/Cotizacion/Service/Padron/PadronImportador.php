<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroGrupo;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Cotizacion\Enum\PasajeroTipoEnum;
use App\Entity\Maestro\MaestroPais;
use App\Enum\DocumentoTipoEnum;
use App\Enum\SexoEnum;
use App\Service\Nombre\NombreSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Lee un padrón y lo vuelca al expediente.
 *
 * Consume {@see PadronFormato}, la misma definición con la que se genera la plantilla: si sólo
 * hubiera dos, el día que alguien añada una columna la plantilla saldría con ella y esto la
 * ignoraría — sin error, sin aviso, con el dato perdido.
 *
 * ## Idempotente, porque el archivo se sube varias veces
 *
 * Un padrón de colegio se corrige tres o cuatro veces antes del viaje. Las tres claves que lo
 * permiten ya existen en el modelo:
 *
 * - **La persona** se casa por su documento —`(tipo, numero)`— no por el nombre: los nombres se
 *   escriben distinto cada vez y el orden de apellidos cambia.
 * - **Los grupos**, por `(file, tipo, clave)` único.
 * - **Las identificaciones**, por `(pasajero, tipo)` único.
 *
 * ## ⚠️ Lo que NO hace: borrar
 *
 * A quien esté en el sistema y no en el archivo **se le avisa, no se le borra**. Una fila que falta
 * puede ser una baja o puede ser que alguien filtró el Excel antes de mandarlo, y borrar a cuarenta
 * personas por un filtro mal puesto no se deshace. Ver `CLAUDE.md`: «No se borra: se marca».
 *
 * Las **pertenencias** sí se sincronizan —si el archivo dice que ya no va a Coco Bongo, deja de
 * ir—, porque eso es precisamente lo que se está corrigiendo al resubir.
 */
final readonly class PadronImportador
{
    public function __construct(
        private EntityManagerInterface $em,
        private NombreSanitizer $nombreSanitizer = new NombreSanitizer(),
    ) {}

    /**
     * @param string $rutaArchivo el .xlsx ya en disco
     */
    public function importar(CotizacionFile $file, string $rutaArchivo, bool $seco = true): ResultadoDelPadron
    {
        $resultado = new ResultadoDelPadron();

        try {
            $libro = IOFactory::load($rutaArchivo);
        } catch (\Throwable $e) {
            $resultado->error('No se pudo abrir el archivo: '.$e->getMessage());

            return $resultado;
        }

        // ⚠️ Se buscan la HOJA y la FILA, no se dan por hechas. El padrón real trae «Resumen»
        // primero y las cabeceras en la cuarta línea de la segunda hoja. Exigir «hoja 1, fila 1»
        // sería exigir que reformateen el archivo antes de subirlo.
        [$filas, $indiceCabecera, $nombreHoja] = $this->buscarHojaYCabecera($libro);

        if ($indiceCabecera === null) {
            $resultado->error(sprintf(
                'No encontré las cabeceras en ninguna hoja: hace falta una columna «%s» (o «Nombres y Apellidos») '
                .'en alguna de las primeras %d filas.',
                PadronFormato::COL_NOMBRES,
                self::FILAS_A_MIRAR,
            ));

            return $resultado;
        }

        if ($libro->getSheetCount() > 1) {
            $resultado->aviso(sprintf('Se leyó la hoja «%s», fila %d de cabeceras.', $nombreHoja, $indiceCabecera + 1));
        }

        $cabeceras = $filas[$indiceCabecera];
        $filas = array_slice($filas, $indiceCabecera + 1);
        $columnas = $this->mapearCabeceras($cabeceras, $resultado);
        if (!isset($columnas['fijas'][PadronFormato::COL_NOMBRES])) {
            $resultado->error(sprintf('Falta la columna «%s», que es la única imprescindible.', PadronFormato::COL_NOMBRES));

            return $resultado;
        }

        // ⚠️ ANTES del bucle de pasajeros, y ahí está el porqué de que sea una hoja aparte: cuando
        // la fila de alguien cree el grupo, su nombre ya tiene que estar puesto. Si el nombre
        // viniera en la fila del pasajero se reescribiría una vez por persona y ganaría la última
        // del bucle. Ver PadronFormato::HOJA_GRUPOS.
        $nombresDeGrupo = $this->leerHojaDeGrupos($libro, $nombreHoja, $resultado);

        $this->denunciarDocumentosRepetidos($filas, $columnas, $indiceCabecera, $resultado);
        if ($resultado->tieneErrores()) {
            return $resultado;
        }

        $vistos = [];

        foreach ($filas as $n => $fila) {
            $numeroFila = $indiceCabecera + $n + 2;   // +1 por la cabecera, +1 porque Excel empieza en 1
            $nombre = trim((string) ($fila[$columnas['fijas'][PadronFormato::COL_NOMBRES]] ?? ''));

            if ($nombre === '') {
                continue;   // fila vacía o la del aviso de ejemplos
            }

            if ($this->pareceTotal($nombre)) {
                // Un padrón real acaba en «TOTAL "SI"». Leerla como persona crea un pasajero
                // llamado TOTAL, y peor: puede casar por un número que en esa fila es un recuento.
                $resultado->aviso(sprintf('Fila %d ignorada: «%s» parece una fila de totales.', $numeroFila, $nombre));
                continue;
            }

            ++$resultado->filasLeidas;

            try {
                $pasajero = $this->pasajeroDeLaFila($file, $fila, $columnas, $resultado);
                $this->aplicarIdentificaciones($pasajero, $fila, $columnas, $resultado);
                $this->aplicarGrupos($file, $pasajero, $fila, $columnas, $nombresDeGrupo, $resultado);
                $vistos[(string) spl_object_id($pasajero)] = true;
            } catch (\Throwable $e) {
                $resultado->error(sprintf('Fila %d (%s): %s', $numeroFila, $nombre, $e->getMessage()));
            }
        }

        foreach ($file->getFilepasajeros() as $existente) {
            if (!isset($vistos[(string) spl_object_id($existente)])) {
                $resultado->noEstanEnElArchivo[] = trim($existente->getNombre().' '.$existente->getApellido());
            }
        }

        if ($resultado->tieneErrores()) {
            return $resultado;
        }

        // ⚠️ El ensayo TAMBIÉN hace flush, dentro de una transacción que se deshace.
        //
        // La primera versión se limitaba a no guardar, y eso hacía el ensayo más permisivo que la
        // carga: un `NOT NULL` sólo salta al escribir. Un padrón real pasó el ensayo limpio y
        // reventó al aplicar por dos celdas de género vacías — que es exactamente lo que un ensayo
        // existe para evitar.
        //
        // Escribiendo y deshaciendo, lo que se enseña antes de aceptar es lo que va a pasar,
        // constraints incluidas.
        $conexion = $this->em->getConnection();
        $conexion->beginTransaction();

        try {
            $this->em->flush();

            if ($seco) {
                $conexion->rollBack();
            } else {
                $conexion->commit();
            }
        } catch (\Throwable $e) {
            if ($conexion->isTransactionActive()) {
                $conexion->rollBack();
            }
            $resultado->error('Al guardar: '.$this->mensajeUtil($e));
        }

        return $resultado;
    }

    /**
     * Traduce el error de base a algo que sirva para arreglar el archivo.
     *
     * «Column 'sexo' cannot be null» no le dice a nadie qué fila mirar; «falta el género en alguna
     * fila» sí, y de paso dice qué columna llenar.
     */
    private function mensajeUtil(\Throwable $e): string
    {
        $bruto = $e->getMessage();

        foreach ([
            "Column 'sexo' cannot be null" => 'falta el género (M/F) en alguna fila.',
            "Column 'pais_id' cannot be null" => 'falta la nacionalidad en alguna fila y el expediente tampoco tiene país.',
            'Duplicate entry' => 'hay un duplicado que la base rechaza: revisa documentos o claves de grupo repetidos.',
        ] as $fragmento => $traduccion) {
            if (str_contains($bruto, $fragmento)) {
                return $traduccion;
            }
        }

        return $bruto;
    }

    /**
     * ¿Esta fila es un total y no una persona?
     *
     * Los padrones reales acaban con una línea de recuento. Sin esto se crea un pasajero llamado
     * «TOTAL» y, peor, esa fila puede traer números en las columnas de documento que son cuentas y
     * no documentos — casando con alguien de verdad.
     */
    private function pareceTotal(string $nombre): bool
    {
        $limpio = mb_strtoupper(trim($nombre));

        foreach (['TOTAL', 'SUBTOTAL', 'SUMA', 'RESUMEN', 'CANTIDAD'] as $palabra) {
            if (str_starts_with($limpio, $palabra)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dos personas con el mismo documento: **se aborta, no se fusiona**.
     *
     * ⚠️ Es el fallo más caro posible de una importación. El padrón real de Punta Cana traía el
     * pasaporte `123343260` en dos filas —una alumna y una coordinadora— y sin esta comprobación la
     * segunda casaba con la primera por documento y **la sobrescribía**: una persona desaparecía del
     * viaje sin que nada lo dijera.
     *
     * Cazarlo antes de tocar nada es lo que permite decir los dos nombres.
     *
     * @param list<list<mixed>>                                                                                                       $filas
     * @param array{fijas: array<string, int>, docs: array<string, array{col: int, venc: ?int}>, ejes: array<int, array{tipo: GrupoTipoEnum, subeje: ?string}>, servicios: array<int, string>, nombreCompleto: bool} $columnas
     */
    private function denunciarDocumentosRepetidos(array $filas, array $columnas, int $indiceCabecera, ResultadoDelPadron $resultado): void
    {
        $vistos = [];
        $ids = [];

        foreach ($filas as $n => $fila) {
            $nombre = trim((string) ($fila[$columnas['fijas'][PadronFormato::COL_NOMBRES]] ?? ''));
            if ($nombre === '' || $this->pareceTotal($nombre)) {
                continue;
            }

            // Un `Id` repetido llega copiando una fila para crear a alguien parecido, y es tan
            // destructivo como un documento repetido: las dos filas escribirían sobre la misma
            // persona y una desaparecería.
            $id = trim((string) ($fila[$columnas['fijas'][PadronFormato::COL_ID] ?? -1] ?? ''));
            if ($id !== '') {
                if (isset($ids[$id])) {
                    $resultado->error(sprintf(
                        'El Id %s está en dos filas: «%s» y «%s». Si copiaste una fila para crear a otra '
                        .'persona, borra su Id: así se creará como nueva.',
                        $id, $ids[$id], $nombre,
                    ));
                } else {
                    $ids[$id] = $nombre;
                }
            }

            foreach ($columnas['docs'] as $valorTipo => $donde) {
                $numero = trim((string) ($fila[$donde['col']] ?? ''));
                if ($numero === '') {
                    continue;
                }

                $clave = $valorTipo.'|'.$numero;
                if (isset($vistos[$clave])) {
                    $resultado->error(sprintf(
                        'El %s %s está en dos filas: «%s» y «%s». Corrígelo antes de importar — con el mismo '
                        .'documento, una persona sobrescribiría a la otra.',
                        mb_strtoupper($valorTipo), $numero, $vistos[$clave], $nombre,
                    ));
                    continue;
                }

                $vistos[$clave] = $nombre;
            }
        }
    }

    /**
     * Cuántas filas de arriba se miran buscando la cabecera.
     *
     * ⚠️ Un padrón real **no empieza en la fila 1**: el de Punta Cana lleva tres filas de título
     * antes. Exigir que la cabecera sea la primera línea es exigir que reformateen el archivo, y
     * eso es fricción para nada — encontrarla cuesta un bucle.
     */
    private const FILAS_A_MIRAR = 10;

    /**
     * La primera hoja que tenga cabeceras reconocibles, y en qué fila están.
     *
     * @return array{0: list<list<mixed>>, 1: ?int, 2: string}
     */
    private function buscarHojaYCabecera(\PhpOffice\PhpSpreadsheet\Spreadsheet $libro): array
    {
        // ⚠️ La hoja «Grupos» se mira LA ÚLTIMA, y no es manía de orden.
        //
        // Su cabecera «Nombre» es alias de «Nombres» (PadronFormato::ALIAS), así que califica como
        // hoja de pasajeros. Con la pestaña «Grupos» arrastrada delante —o en un libro armado a
        // mano— se leía ella: entraban pasajeros llamados «JetSmart» y «ARAJET», y encima los
        // rótulos se perdían, porque `leerHojaDeGrupos()` se salta la hoja de la que salieron las
        // personas. Todo en silencio.
        //
        // Se deja como último recurso en vez de excluirla: si alguien llama «Grupos» a su hoja de
        // gente, sigue funcionando.
        $primero = [];
        $ultimo = [];
        foreach ($libro->getAllSheets() as $hoja) {
            if ($hoja->getTitle() === PadronFormato::HOJA_GRUPOS) {
                $ultimo[] = $hoja;
            } else {
                $primero[] = $hoja;
            }
        }

        foreach ([...$primero, ...$ultimo] as $hoja) {
            $filas = $hoja->toArray(null, true, false, false);

            foreach (array_slice($filas, 0, self::FILAS_A_MIRAR, true) as $i => $fila) {
                foreach ($fila as $celda) {
                    $texto = (string) $celda;
                    if (PadronFormato::canonica($texto) === PadronFormato::COL_NOMBRES || PadronFormato::esNombreCompleto($texto)) {
                        return [$filas, $i, $hoja->getTitle()];
                    }
                }
            }
        }

        return [[], null, ''];
    }

    /**
     * Cabecera → índice de columna, por FAMILIA.
     *
     * Se mapea por nombre y no por posición: el archivo se puede reordenar y las columnas que no se
     * usen se borran.
     *
     * @param list<mixed> $cabeceras
     *
     * @return array{fijas: array<string, int>, docs: array<string, array{col: int, venc: ?int}>, ejes: array<int, array{tipo: GrupoTipoEnum, subeje: ?string}>, servicios: array<int, string>, nombreCompleto: bool}
     */
    private function mapearCabeceras(array $cabeceras, ResultadoDelPadron $resultado): array
    {
        $mapa = ['fijas' => [], 'docs' => [], 'ejes' => [], 'servicios' => [], 'nombreCompleto' => false];
        $vencimientos = [];

        foreach ($cabeceras as $i => $bruta) {
            $cabecera = trim((string) $bruta);
            if ($cabecera === '') {
                continue;
            }

            if (PadronFormato::esColumnaDeEje($cabecera)) {
                $eje = PadronFormato::ejeDe($cabecera);
                if ($eje === null) {
                    // Se avisa con nombre y apellido en vez de tragárselo: un eje inventado sería
                    // un grupo fantasma que nadie sabría de dónde salió.
                    $resultado->aviso(sprintf('La columna «%s» no corresponde a ningún eje conocido: se ignora.', $cabecera));
                    continue;
                }
                $mapa['ejes'][$i] = $eje;
                continue;
            }

            if (PadronFormato::esColumnaDeServicio($cabecera)) {
                $mapa['servicios'][$i] = PadronFormato::servicioDe($cabecera);
                continue;
            }

            if (str_starts_with($cabecera, PadronFormato::PREFIJO_VENCIMIENTO)) {
                $vencimientos[mb_strtolower(substr($cabecera, mb_strlen(PadronFormato::PREFIJO_VENCIMIENTO)))] = $i;
                continue;
            }

            foreach (PadronFormato::columnasDeDocumento() as $doc) {
                if (mb_strtolower($doc['columna']) === mb_strtolower($cabecera)
                    || mb_strtolower($doc['tipo']->value) === mb_strtolower($cabecera)) {
                    $mapa['docs'][$doc['tipo']->value] = ['col' => $i, 'venc' => null];
                    continue 2;
                }
            }

            if (PadronFormato::esNombreCompleto($cabecera)) {
                $mapa['fijas'][PadronFormato::COL_NOMBRES] = $i;
                $mapa['nombreCompleto'] = true;
                $resultado->aviso(sprintf(
                    'La columna «%s» trae nombre y apellidos juntos. Se parten por convención peruana '
                    .'—las dos últimas palabras son apellidos—, que acierta con nombres locales y falla '
                    .'con extranjeros. Revísalos después.',
                    $cabecera,
                ));
                continue;
            }

            $mapa['fijas'][PadronFormato::canonica($cabecera)] = $i;
        }

        // Los vencimientos se casan después: la columna «Venc. X» puede ir antes que la de «X».
        foreach (PadronFormato::columnasDeDocumento() as $doc) {
            $clave = mb_strtolower($doc['columna']);
            if (isset($mapa['docs'][$doc['tipo']->value], $vencimientos[$clave])) {
                $mapa['docs'][$doc['tipo']->value]['venc'] = $vencimientos[$clave];
            }
        }

        return $mapa;
    }

    /**
     * @param list<mixed>                                                                                                            $fila
     * @param array{fijas: array<string, int>, docs: array<string, array{col: int, venc: ?int}>, ejes: array<int, array{tipo: GrupoTipoEnum, subeje: ?string}>, servicios: array<int, string>, nombreCompleto: bool} $columnas
     */
    private function pasajeroDeLaFila(CotizacionFile $file, array $fila, array $columnas, ResultadoDelPadron $resultado): CotizacionFilepasajero
    {
        $texto = fn (string $col): string => trim((string) ($fila[$columnas['fijas'][$col] ?? -1] ?? ''));

        // ── Identidad, en tres peldaños de menos a más adivinanza ───────────
        //
        // 1. El `Id` de la exportación: exacto. La fila vuelve a SU persona aunque le hayan
        //    cambiado el nombre y el documento a la vez.
        // 2. El documento: bueno, pero si alguien corrige un pasaporte esa persona se duplica.
        // 3. El nombre normalizado: el peor, y existe sólo para que quien no tiene documento no
        //    se duplique en cada resubida.
        $pasajero = $this->buscarPorId($file, $texto(PadronFormato::COL_ID))
            ?? $this->buscarPorDocumento($file, $fila, $columnas)
            ?? $this->buscarPorNombre($file, $texto(PadronFormato::COL_NOMBRES), $texto(PadronFormato::COL_APELLIDOS));

        if ($pasajero === null) {
            $pasajero = new CotizacionFilepasajero();
            $file->addFilepasajero($pasajero);
            $this->em->persist($pasajero);
            ++$resultado->pasajerosCreados;
        } else {
            ++$resultado->pasajerosActualizados;
        }

        // ⚠️ Los padrones vienen GRITADOS —«VALDIVIA BERRIOS»— porque se teclean con el bloqueo de
        // mayúsculas puesto, y de ahí salen a la ficha del huésped y a los mensajes. `NombreSanitizer`
        // sólo toca lo que está claramente gritado: «de la Cruz» o «McDonald» los escribió alguien así
        // a propósito y se quedan. Es el mismo que normaliza los nombres que llegan de Beds24.
        $propio = fn (string $v): string => (string) $this->nombreSanitizer->formatear($v);

        if ($columnas['nombreCompleto']) {
            [$nombres, $apellidos] = PadronFormato::partirNombre($texto(PadronFormato::COL_NOMBRES));
            $pasajero->setNombre($propio($nombres));
            $pasajero->setApellido($propio($apellidos));
        } else {
            $pasajero->setNombre($propio($texto(PadronFormato::COL_NOMBRES)));
            $pasajero->setApellido($propio($texto(PadronFormato::COL_APELLIDOS)) ?: $pasajero->getApellido() ?? '');
        }

        $rolEscrito = $texto(PadronFormato::COL_TIPO);
        if ($rolEscrito !== '') {
            $tipo = PasajeroTipoEnum::desdeTexto($rolEscrito);

            if ($tipo === null) {
                // Se denuncia en vez de ignorarlo: el rol decide qué ve cada uno, y un «Alumbo» mal
                // escrito que entrara como nada dejaría a alguien viendo de menos sin explicación.
                throw new \DomainException(sprintf(
                    'el rol «%s» no existe. Válidos: %s.',
                    $rolEscrito,
                    implode(', ', PasajeroTipoEnum::valoresValidos()),
                ));
            }

            $pasajero->setTipo($tipo);
        }
        if (($telefono = $texto(PadronFormato::COL_TELEFONO)) !== '') {
            $pasajero->setTelefono($telefono);
        }
        if (($obs = $texto(PadronFormato::COL_OBSERVACIONES)) !== '') {
            $pasajero->setObservaciones($obs);
        }
        if (($sexo = SexoEnum::tryFrom(mb_strtoupper($texto(PadronFormato::COL_SEXO)))) !== null) {
            $pasajero->setSexo($sexo);
        }
        if (($nacimiento = $this->fecha($texto(PadronFormato::COL_NACIMIENTO))) !== null) {
            $pasajero->setFechanacimiento($nacimiento);
        }

        $pais = $this->pais($texto(PadronFormato::COL_NACIONALIDAD)) ?? $pasajero->getPais() ?? $file->getPais();
        if ($pais === null) {
            // `pais` es NOT NULL en la entidad, así que sin él la fila no se puede guardar. Se dice
            // qué falta en vez de dejar que reviente al hacer flush con un mensaje de Doctrine.
            throw new \DomainException('sin nacionalidad, y el expediente tampoco tiene país.');
        }
        $pasajero->setPais($pais);

        return $pasajero;
    }

    /**
     * El pasajero por su `Id`, si la fila lo trae y es de ESTE expediente.
     *
     * ⚠️ Un id de otro expediente **no se ignora en silencio**: se denuncia. Llega ahí copiando
     * filas entre dos hojas exportadas, y tratarlo como «no lo encuentro» crearía un duplicado del
     * que nadie sospecharía.
     */
    private function buscarPorId(CotizacionFile $file, string $id): ?CotizacionFilepasajero
    {
        if ($id === '' || !Uuid::isValid($id)) {
            return null;
        }

        foreach ($file->getFilepasajeros() as $candidato) {
            if ((string) $candidato->getId() === mb_strtolower($id)) {
                return $candidato;
            }
        }

        throw new \DomainException(sprintf(
            'el Id «%s» no es de este expediente. ¿Copiaste filas de otra hoja? Bórralo y se creará como nuevo.',
            $id,
        ));
    }

    /**
     * @param list<mixed>                                                                                                            $fila
     * @param array{fijas: array<string, int>, docs: array<string, array{col: int, venc: ?int}>, ejes: array<int, array{tipo: GrupoTipoEnum, subeje: ?string}>, servicios: array<int, string>, nombreCompleto: bool} $columnas
     */
    private function buscarPorDocumento(CotizacionFile $file, array $fila, array $columnas): ?CotizacionFilepasajero
    {
        foreach ($columnas['docs'] as $valorTipo => $donde) {
            $numero = trim((string) ($fila[$donde['col']] ?? ''));
            if ($numero === '') {
                continue;
            }

            $tipo = DocumentoTipoEnum::from($valorTipo);

            foreach ($file->getFilepasajeros() as $candidato) {
                $suyo = $candidato->identificacionDe($tipo);
                if ($suyo !== null && $suyo->getNumero() === $numero) {
                    return $candidato;
                }
            }
        }

        return null;
    }

    /**
     * Último recurso: el nombre normalizado.
     *
     * ⚠️ Es peor clave que el documento y se sabe: los nombres se escriben distinto cada vez. Existe
     * sólo para que alguien sin documento cargado no se duplique en cada resubida — y en el padrón
     * real hay tres personas sin pasaporte.
     */
    private function buscarPorNombre(CotizacionFile $file, string $nombre, string $apellido): ?CotizacionFilepasajero
    {
        $normalizar = static fn (string $t): string => preg_replace('/\s+/', ' ', mb_strtolower(trim($t))) ?? '';
        $buscado = $normalizar($nombre.' '.$apellido);

        foreach ($file->getFilepasajeros() as $candidato) {
            if ($normalizar($candidato->getNombre().' '.$candidato->getApellido()) === $buscado) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * @param list<mixed>                                                                                                            $fila
     * @param array{fijas: array<string, int>, docs: array<string, array{col: int, venc: ?int}>, ejes: array<int, array{tipo: GrupoTipoEnum, subeje: ?string}>, servicios: array<int, string>, nombreCompleto: bool} $columnas
     */
    private function aplicarIdentificaciones(CotizacionFilepasajero $pasajero, array $fila, array $columnas, ResultadoDelPadron $resultado): void
    {
        foreach ($columnas['docs'] as $valorTipo => $donde) {
            $numero = trim((string) ($fila[$donde['col']] ?? ''));
            if ($numero === '') {
                continue;
            }

            $tipo = DocumentoTipoEnum::from($valorTipo);
            $identificacion = $pasajero->identificacionDe($tipo);

            if ($identificacion === null) {
                $identificacion = new CotizacionPasajeroIdentificacion();
                $identificacion->setTipo($tipo);
                $pasajero->addIdentificacion($identificacion);
                $this->em->persist($identificacion);
                ++$resultado->identificacionesCreadas;
            }

            $identificacion->setNumero($numero);

            $vencimiento = $donde['venc'] !== null ? $this->fecha(trim((string) ($fila[$donde['venc']] ?? ''))) : null;
            if ($vencimiento !== null) {
                $identificacion->setVencimiento($vencimiento);
            }
        }
    }

    /**
     * @param list<mixed>                                                                                                            $fila
     * @param array{fijas: array<string, int>, docs: array<string, array{col: int, venc: ?int}>, ejes: array<int, array{tipo: GrupoTipoEnum, subeje: ?string}>, servicios: array<int, string>, nombreCompleto: bool} $columnas
     * @param array<string, array{nombre: string, detalle: string}>                                                                  $nombresDeGrupo
     */
    private function aplicarGrupos(CotizacionFile $file, CotizacionFilepasajero $pasajero, array $fila, array $columnas, array $nombresDeGrupo, ResultadoDelPadron $resultado): void
    {
        $deseados = [];

        foreach ($columnas['ejes'] as $i => $eje) {
            $clave = trim((string) ($fila[$i] ?? ''));
            if ($clave !== '') {
                $deseados[] = $this->grupo($file, $eje['tipo'], $clave, $nombresDeGrupo, $resultado, $eje['subeje']);
            }
        }

        foreach ($columnas['servicios'] as $i => $servicio) {
            if (PadronFormato::participa($fila[$i] ?? null)) {
                $deseados[] = $this->grupo($file, GrupoTipoEnum::SERVICIO, $servicio, $nombresDeGrupo, $resultado);
            }
        }

        // ── Sincronizar, no sólo añadir ─────────────────────────────────────
        // Si el archivo corregido dice que ya NO va a Coco Bongo, tiene que dejar de ir: eso es
        // justo lo que se está corrigiendo al resubir. Distinto de borrar personas, que no se hace.
        foreach ($pasajero->getPertenencias() as $pertenencia) {
            if (!in_array($pertenencia->getGrupo(), $deseados, true)) {
                $pasajero->removePertenencia($pertenencia);
                $this->em->remove($pertenencia);
                ++$resultado->pertenenciasQuitadas;
            }
        }

        foreach ($deseados as $grupo) {
            if ($this->yaPertenece($pasajero, $grupo)) {
                continue;
            }

            $pertenencia = new CotizacionPasajeroGrupo();
            $pasajero->addPertenencia($pertenencia);
            $grupo->addMiembro($pertenencia);
            $this->em->persist($pertenencia);
            ++$resultado->pertenenciasCreadas;
        }
    }

    private function yaPertenece(CotizacionFilepasajero $pasajero, CotizacionFileGrupo $grupo): bool
    {
        foreach ($pasajero->getPertenencias() as $pertenencia) {
            if ($pertenencia->getGrupo() === $grupo) {
                return true;
            }
        }

        return false;
    }

    /** El grupo `(file, tipo, clave)`, creándolo si hace falta. */
    /**
     * Lee la hoja «Grupos», si la hay.
     *
     * Es **opcional**: un expediente de dos personas no la necesita, y un padrón que venga del
     * colegio no la traerá. Sin ella los grupos se crean igual, sólo que sin nombre.
     *
     * ⚠️ Se salta la hoja de la que salieron los pasajeros: si alguien renombra su hoja de datos
     * «Grupos», leerla como tabla de nombres daría basura en vez de un error.
     *
     * @return array<string, array{nombre: string, detalle: string}>
     */
    private function leerHojaDeGrupos(
        \PhpOffice\PhpSpreadsheet\Spreadsheet $libro,
        string $hojaDeLosPasajeros,
        ResultadoDelPadron $resultado,
    ): array {
        $hoja = $libro->getSheetByName(PadronFormato::HOJA_GRUPOS);
        if ($hoja === null || $hoja->getTitle() === $hojaDeLosPasajeros) {
            return [];
        }

        $filas = $hoja->toArray(null, true, false, false);
        if ($filas === []) {
            return [];
        }

        $cabeceras = array_map(static fn ($c): string => trim((string) $c), array_shift($filas) ?? []);

        // ⚠️ La PRIMERA que aparezca, no la última. `array_flip` se queda con la última, y una
        // cabecera repetida —copiar la hoja y olvidar borrar la columna vieja— hacía que se leyera
        // la columna vacía de la derecha: los detalles entraban en blanco sin un solo aviso.
        // (En la hoja de PASAJEROS sí se repiten a propósito: dos ejes iguales son dos columnas.)
        $col = [];
        foreach ($cabeceras as $i => $cabecera) {
            if ($cabecera !== '' && !isset($col[$cabecera])) {
                $col[$cabecera] = $i;
            }
        }
        // Sólo el eje y la clave son imprescindibles. «Nombre» y «Detalle» son opcionales: hay
        // quien escribe «Y9KZ7J Jetsmart» de un tirón en la clave, y hay hojas de tres columnas
        // ya circulando.
        foreach ([PadronFormato::COL_GRUPO_EJE, PadronFormato::COL_GRUPO_CLAVE] as $necesaria) {
            if (!isset($col[$necesaria])) {
                $resultado->aviso(sprintf(
                    'La hoja «%s» no tiene columna «%s»: se ignora entera y los grupos entrarán sin nombre.',
                    PadronFormato::HOJA_GRUPOS,
                    $necesaria,
                ));

                return [];
            }
        }

        $nombres = [];
        foreach ($filas as $fila) {
            $eje = trim((string) ($fila[$col[PadronFormato::COL_GRUPO_EJE]] ?? ''));
            $clave = trim((string) ($fila[$col[PadronFormato::COL_GRUPO_CLAVE]] ?? ''));
            $nombre = isset($col[PadronFormato::COL_GRUPO_NOMBRE])
                ? trim((string) ($fila[$col[PadronFormato::COL_GRUPO_NOMBRE]] ?? ''))
                : '';
            $detalle = isset($col[PadronFormato::COL_GRUPO_DETALLE])
                ? trim((string) ($fila[$col[PadronFormato::COL_GRUPO_DETALLE]] ?? ''))
                : '';

            // «Y9KZ7J Jetsmart» en una sola celda: se parte por el primer espacio, y SÓLO si la
            // columna «Nombre» no dijo nada. Con las dos columnas puestas manda la explícita.
            if ($nombre === '' && $clave !== '') {
                [$clave, $nombre] = PadronFormato::partirClaveYNombre($clave);
            }

            // Basta con que traiga UNO de los dos: hay grupos con itinerario y sin rótulo corto.
            if ($eje === '' || ($nombre === '' && $detalle === '')) {
                continue;
            }

            $valor = ['nombre' => $nombre, 'detalle' => $detalle];

            // Un servicio no lleva clave —es binario—, así que su identidad es el eje a secas.
            if (PadronFormato::esColumnaDeServicio($eje)) {
                // En mayúsculas porque así es como `grupo()` normaliza la clave del servicio:
                // si aquí entrara «Coco Bongo» y allí «COCO BONGO», el nombre no se encontraría.
                $nombres[PadronFormato::claveDeGrupo(mb_strtoupper(PadronFormato::servicioDe($eje)), '')] = $valor;
                continue;
            }

            // Un eje con valor y sin clave no identifica a nadie: la fila entraría en el mapa con
            // una llave que ningún grupo puede tener y no haría NADA. Callarlo es lo peor de los
            // dos mundos —el operador cree que puso el rótulo y no aparece por ningún sitio—.
            if ($clave === '') {
                $resultado->aviso(sprintf(
                    'La hoja «%s» trae «%s» sin clave: sin el código no se sabe a qué grupo pone el '
                    .'rótulo. Esa fila se ignora.',
                    PadronFormato::HOJA_GRUPOS,
                    $eje,
                ));
                continue;
            }

            $resuelto = PadronFormato::ejeDe($eje);
            if ($resuelto === null) {
                $resultado->aviso(sprintf(
                    'La hoja «%s» nombra un eje que no existe: «%s». Esa fila se ignora.',
                    PadronFormato::HOJA_GRUPOS,
                    $eje,
                ));
                continue;
            }

            $nombres[PadronFormato::claveDeGrupo($resuelto['tipo']->value, $clave, $resuelto['subeje'])] = $valor;
        }

        if ($nombres !== []) {
            $resultado->aviso(sprintf('Se leyeron %d rótulos de grupo de la hoja «%s».', count($nombres), PadronFormato::HOJA_GRUPOS));
        }

        return $nombres;
    }

    /** @param array<string, array{nombre: string, detalle: string}> $nombresDeGrupo */
    private function grupo(
        CotizacionFile $file,
        GrupoTipoEnum $tipo,
        string $clave,
        array $nombresDeGrupo,
        ResultadoDelPadron $resultado,
        ?string $subeje = null,
    ): CotizacionFileGrupo {
        $normalizada = mb_strtoupper(trim($clave));
        $identidad = PadronFormato::claveDeGrupo(
            $tipo === GrupoTipoEnum::SERVICIO ? $normalizada : $tipo->value,
            $tipo === GrupoTipoEnum::SERVICIO ? '' : $normalizada,
            $tipo === GrupoTipoEnum::SERVICIO ? null : $subeje,
        );
        $rotulo = $nombresDeGrupo[$identidad] ?? null;

        // ⚠️ El tramo se compara SIN mayúsculas, como lo compara MySQL.
        //
        // La colación es `utf8mb4_unicode_ci`, así que para el índice único «Nacional» y «nacional»
        // son el mismo. Comparando aquí con `===` se intentaría crear el segundo y saltaría una
        // violación de unicidad al hacer flush — un error feo por una diferencia que a nadie le
        // importa. Se reutiliza el que hay, que es lo que la base ya había decidido.
        $mismoTramo = static fn (?string $a, ?string $b): bool =>
            mb_strtolower(trim($a ?? '')) === mb_strtolower(trim($b ?? ''));

        foreach ($file->getGrupos() as $grupo) {
            if ($grupo->getTipo() === $tipo && $mismoTramo($grupo->getSubeje(), $subeje) && $grupo->getClave() === $normalizada) {
                // Se refresca en el grupo que ya existía: es la vía por la que se CORRIGE un vuelo
                // que cambió de aerolínea, y sin ella habría que borrar el grupo para renombrarlo.
                //
                // ⚠️ Sólo lo que la hoja TRAE. Una celda vacía es «no lo digo», no «bórralo»: si
                // borrara, una hoja con la columna «Detalle» en blanco vaciaría los itinerarios de
                // los 106 grupos sin que nadie lo pidiera.
                if ($rotulo !== null) {
                    if ($rotulo['nombre'] !== '') { $grupo->setNombre($rotulo['nombre']); }
                    if ($rotulo['detalle'] !== '') { $grupo->setDetalle($rotulo['detalle']); }
                }

                return $grupo;
            }
        }

        // ⚠️ «Y9KZ7J JETS MART» en la fila de un PASAJERO: el rótulo se coló donde va sólo el
        // código. Sin esto se crea un grupo aparte de «Y9KZ7J» —dos reservas donde hay una— y no
        // lo denuncia nadie: las dos claves son válidas por separado.
        //
        // Se reutiliza el que ya existe en vez de adivinar, porque hay PRUEBA de que es el mismo:
        // el grupo del primer token ya está creado. Y se avisa, que el archivo hay que arreglarlo.
        if ($tipo !== GrupoTipoEnum::SERVICIO && str_contains($normalizada, ' ')) {
            [$soloClave] = PadronFormato::partirClaveYNombre($normalizada);
            $declarada = isset($nombresDeGrupo[PadronFormato::claveDeGrupo($tipo->value, $soloClave)]);

            // La PRUEBA es que la hoja «Grupos» declara ese código, no que exista ya un grupo:
            // los grupos se crean sobre la marcha, así que mirar los existentes dependía del orden
            // de las filas —si la fila con el rótulo pegado venía primero, ganaba ella—.
            $yaCreado = false;
            foreach ($file->getGrupos() as $existente) {
                if ($existente->getTipo() === $tipo && $mismoTramo($existente->getSubeje(), $subeje) && $existente->getClave() === $soloClave) {
                    $yaCreado = true;
                    break;
                }
            }

            if ($declarada || $yaCreado) {
                $resultado->aviso(sprintf(
                    '«%s» trae el rótulo pegado al código: se usa «%s». En la hoja de pasajeros va '
                    .'SÓLO el código; el rótulo va en la hoja «%s».',
                    $normalizada, $soloClave, PadronFormato::HOJA_GRUPOS,
                ));

                return $this->grupo($file, $tipo, $soloClave, $nombresDeGrupo, $resultado, $subeje);
            }
        }

        $grupo = new CotizacionFileGrupo();
        $grupo->setTipo($tipo)->setSubeje($subeje)->setClave($normalizada);
        if ($rotulo !== null) {
            $grupo->setNombre($rotulo['nombre'] ?: null);
            $grupo->setDetalle($rotulo['detalle'] ?: null);
        }
        $file->addGrupo($grupo);
        $this->em->persist($grupo);
        ++$resultado->gruposCreados;

        return $grupo;
    }

    /** Acepta el ISO de dos letras o el nombre del país. */
    private function pais(string $texto): ?MaestroPais
    {
        if ($texto === '') {
            return null;
        }

        $repo = $this->em->getRepository(MaestroPais::class);

        return $repo->find(mb_strtoupper($texto)) ?? $repo->findOneBy(['nombre' => $texto]);
    }

    /**
     * Fechas del Excel: texto `DD/MM/AAAA` o el serial de la hoja.
     *
     * ⚠️ Se prueba primero el formato de la plantilla y **no se cae a `strtotime()`**: éste lee
     * `03/04/2026` como el 4 de marzo, y en un padrón donde una fecha decide si alguien embarca,
     * equivocarse de mes en silencio es peor que no leerla.
     */
    private function fecha(string $texto): ?\DateTimeImmutable
    {
        if ($texto === '') {
            return null;
        }

        $fecha = \DateTimeImmutable::createFromFormat('!d/m/Y', $texto);
        if ($fecha instanceof \DateTimeImmutable) {
            return $fecha;
        }

        if (is_numeric($texto)) {
            $serial = (int) $texto;

            return (new \DateTimeImmutable('1899-12-30'))->modify(sprintf('+%d days', $serial));
        }

        return null;
    }
}
