<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Enum\DocumentoTipoEnum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * El estado de los escaneos de identidad, persona a persona, en una hoja.
 *
 * ## Para qué, si el manifiesto ya los muestra
 *
 * Porque la pregunta que se hace de verdad no es «¿qué tiene Fulano?» sino «¿a quién le escribo
 * hoy?». Con 133 personas eso es una lista que se ordena, se filtra y se pega en un mensaje —y eso
 * es una hoja de cálculo, no un panel—. La columna que importa es la última, {@see self::COL_FALTA}:
 * dice qué pedir, ya redactado.
 *
 * ## Las columnas de estado las decide el EXPEDIENTE
 *
 * Una por cada documento que ese expediente exige ({@see CotizacionFile::$documentosPedidos}), así
 * que dos expedientes distintos dan hojas de distinto ancho. Antes eran tres fijas y por eso la
 * hoja podía decir «Completo» a quien le faltaba el E-Ticket — ver {@see self::escaneosPedidos()}.
 *
 * ⚠️ El DNI va por caras —dos columnas— porque en un control migratorio no vale sólo el anverso:
 * con una columna sola, «le falta el reverso» sería indistinguible de «no ha subido nada».
 *
 * ## ⚠️ Cuenta ARCHIVOS, no marca sí/no
 *
 * Cada celda lleva la fecha de subida y, **si hay más de uno de ese tipo, cuántos hay**. No es
 * adorno: la vía del pasajero reemplaza el escaneo anterior al subir otro, pero la del operador
 * —API Platform, el formulario de la bóveda— no lo hace, y no hay índice único que lo impida. Si
 * algún día se acumulan dos pasaportes de la misma persona, esta hoja es donde se ve.
 *
 * @see \App\Cotizacion\Service\Padron\PadronPlantillaGenerador el otro .xlsx, el de los datos
 */
final readonly class ReporteDeDocumentos
{
    private const AZUL = 'FF376875';
    private const VERDE = 'FFDCFCE7';
    private const ROJO = 'FFFEE2E2';
    private const AMBAR = 'FFFEF3C7';
    private const GRIS = 'FFF1F5F9';

    public const COL_FALTA = 'Qué falta';
    public const COL_ARCHIVOS = 'Archivos';

    /**
     * Lo que NO cuadra con el escaneo, para quien tenga que arreglarlo.
     *
     * ⚠️ Va **la última** a propósito. Las columnas de la izquierda contestan «¿me falta un
     * documento?», que es a lo que se abre esta hoja; ésta contesta «¿lo que tengo está bien?»,
     * que es otra pregunta y llegó después. Metida en medio, empujaría a la derecha las tres
     * columnas de estado que la gente ya sabe dónde están.
     */
    private const COL_OBSERVA = 'Observaciones';

    /** Las columnas fijas de la izquierda: quién es la persona. */
    private const CABECERAS_FIJAS = [
        'Grupo',
        'Apellidos',
        'Nombres',
        'Tipo',
        'DNI',
        'Pasaporte',
        'Otro documento',
    ];

    /**
     * Qué escaneo mira cada columna de estado: **lo que ESTE expediente pide**.
     *
     * 🔥 **Eran tres columnas fijas —DNI anverso, DNI reverso, pasaporte— y por eso la hoja
     * mentía.** Desde que lo obligatorio es configurable por expediente
     * ({@see CotizacionFile::$documentosPedidos}), un viaje que exige el E-Ticket migratorio no
     * tenía columna para él: la persona que no lo había mandado salía con «Completo» en la única
     * columna que se lee, que es justo la que contesta «¿a quién le escribo hoy?».
     *
     * Y no era un caso raro: en el expediente real lo pedían 134 personas y lo habían mandado 95.
     * La hoja decía que no faltaba nada por 39 veces.
     *
     * ⚠️ **El orden lo decide el ENUM, no `documentosPedidos`.** La lista guardada sale en el orden
     * en que el operador marcó las casillas, y una hoja que cambia el orden de sus columnas entre
     * dos descargas no se puede comparar con la anterior ni pegar en la misma plantilla.
     *
     * @return array<string, ArchivoTipoEnum> etiqueta de columna → tipo de archivo
     */
    private function escaneosPedidos(CotizacionFile $file): array
    {
        $pedidos = $file->getDocumentosPedidos();
        $columnas = [];

        foreach (ArchivoTipoEnum::cases() as $tipo) {
            if ($tipo->loSubeElPasajero() && in_array($tipo->value, $pedidos, true)) {
                $columnas[$tipo->getLabel()] = $tipo;
            }
        }

        return $columnas;
    }

    /**
     * Las columnas, en el orden en que se leen.
     *
     * @param array<string, ArchivoTipoEnum> $escaneos
     *
     * @return list<string>
     */
    private function cabeceras(array $escaneos): array
    {
        return [
            ...self::CABECERAS_FIJAS,
            ...array_keys($escaneos),
            self::COL_ARCHIVOS,
            self::COL_FALTA,
            ...array_map(static fn (string $e): string => self::COL_OBSERVA.' '.$e, array_keys($escaneos)),
        ];
    }

    /**
     * Cuántas columnas van DESPUÉS de las de observaciones. Hoy ninguna.
     *
     * ⚠️ Existe para que las posiciones no se calculen con números a mano. Las de estado y las de
     * observaciones crecen las dos con lo que pide el expediente, así que `$ultima - 2` —que era
     * como se colocaba «Archivos»— dejó de significar nada en cuanto hubo dos bloques variables.
     */
    private function dondeEmpiezanLasObservaciones(int $cuantosEscaneos): int
    {
        return count(self::CABECERAS_FIJAS) + $cuantosEscaneos + 3;
    }

    /**
     * Lo que el control encontró, **repartido por el documento al que pertenece**.
     *
     * 🔥 **Era UNA columna global y por eso no servía para trabajar.** Todas las observaciones de
     * una persona iban concatenadas en la misma celda: «DNI número: doc X ≠ guardado Y · PASAPORTE:
     * vencido · …». Con eso no se puede filtrar «enséñame a quién le falla el E-Ticket», que es la
     * única pregunta con la que se abre esta hoja, y con 134 filas leerlas una a una no es una
     * opción. Ahora hay **una columna de observaciones por cada documento que el expediente pide**,
     * simétrica con la columna de estado que ya tenía cada uno.
     *
     * ── De dónde sale cada observación, que era lo que faltaba ──────────────
     * Los veredictos viven en dos sitios y hasta ahora sólo se leía uno:
     *
     * | Documento | Dónde está su veredicto |
     * |---|---|
     * | DNI, pasaporte | `CotizacionPasajeroIdentificacion`, al lado del número que juzga |
     * | E-Ticket y demás | el propio `CotizacionFilearchivo` |
     *
     * 🔑 **La atribución es EXACTA y no se adivina**: `CotizacionPasajeroIdentificacion::$validadoCon`
     * apunta al archivo que produjo ese veredicto, así que la observación va a la columna de ese
     * tipo. Repartirlas por `respaldaA()` habría sido una regla paralela que algún día discreparía.
     *
     * ⚠️ **Y hace falta el respaldo para cuando `validadoCon` es nulo**, que no es raro: son los
     * veredictos que no salieron de ningún escaneo —«no hay escaneo con qué cotejar»—. En el
     * expediente real son 13 de 265, y **todos tienen observaciones**: dejarlos fuera habría
     * borrado justo las trece filas que más falta hacen. Ahí se cae a
     * {@see ArchivoTipoEnum::paraValidar()}.
     *
     * ⚠️ Se compone con el CAMPO y los dos valores —«número: doc X ≠ esperado Y»— y no con un
     * «tiene observaciones»: esta hoja se manda por correo a quien tiene que corregir, y ahí no hay
     * botón que pulsar para ver el detalle.
     *
     * ⚠️ Las notas del giro **no entran**: se resuelven con un botón en la pantalla y en una hoja de
     * correcciones sólo serían ruido.
     *
     * @param array<string, ArchivoTipoEnum> $escaneos
     *
     * @return array<string, list<string>> valor de `ArchivoTipoEnum` → sus frases
     */
    private function observacionesPorTipo(CotizacionFile $file, CotizacionFilepasajero $pasajero, array $escaneos): array
    {
        $porTipo = [];
        $id = (string) $pasajero->getId();

        foreach ($pasajero->getIdentificaciones() as $identificacion) {
            $tipo = $identificacion->getTipo();

            // El archivo que produjo el veredicto manda; si no lo hubo, el que habría hecho falta.
            $destino = $identificacion->getValidadoCon()?->getTipoArchivo()
                ?? ($tipo !== null ? ArchivoTipoEnum::paraValidar($tipo) : null);

            // ⚠️ **Y tiene que tener columna, o se cuenta lo que no se pinta.** La rama de archivos
            // ya filtraba por lo pedido y ésta no: un expediente que sólo pide el E-Ticket sumaba al
            // pie «N con observaciones» por discrepancias de DNI que no tienen dónde salir. El
            // recuento decía una cosa y la hoja otra.
            if ($destino === null || !in_array($destino, $escaneos, true)) {
                continue;
            }

            foreach ($this->frasesDe($identificacion->getDiscrepancias(), $identificacion->getNotasValidacion()) as $frase) {
                $porTipo[$destino->value][] = $frase;
            }
        }

        // Y los veredictos que son del propio archivo: el E-Ticket, y lo que venga después.
        //
        // ⚠️ **Sólo el MÁS RECIENTE de cada tipo.** La vía del operador no reemplaza el archivo
        // anterior al subir otro, así que puede haber dos E-Ticket de la misma persona: el viejo
        // observado y el nuevo bueno. Concatenar los dos deja a alguien que ya lo corrigió leyéndose
        // como observado, que es la forma más rápida de que se deje de mirar la columna. La celda de
        // estado sí avisa de que hay dos («2 · fecha», en ámbar), que es donde toca decirlo.
        $vigentes = [];

        foreach ($file->getFilearchivos() as $archivo) {
            $tipo = $archivo->getTipoArchivo();

            if ($tipo === null || !in_array($tipo, $escaneos, true)) {
                continue;
            }

            if ((string) $archivo->getPasajero()?->getId() !== $id) {
                continue;
            }

            $previo = $vigentes[$tipo->value] ?? null;

            if ($previo === null || $archivo->getCreatedAt() > $previo->getCreatedAt()) {
                $vigentes[$tipo->value] = $archivo;
            }
        }

        foreach ($vigentes as $valor => $archivo) {
            foreach ($this->frasesDe($archivo->getDiscrepancias(), $archivo->getNotasValidacion()) as $frase) {
                $porTipo[$valor][] = $frase;
            }
        }

        return $porTipo;
    }

    /**
     * @param list<array{campo: string, documento: string, manifiesto: string}> $discrepancias
     * @param list<string>                                                      $notas
     *
     * @return list<string>
     */
    private function frasesDe(array $discrepancias, array $notas): array
    {
        $frases = [];

        foreach ($discrepancias as $d) {
            // ⚠️ «esperado» y no «guardado»: desde el E-Ticket el otro lado puede ser el itinerario
            // y no el manifiesto. La CLAVE del array sigue llamándose `manifiesto` porque está
            // persistida en dos tablas; lo que se lee en la hoja ya dice la verdad.
            $frases[] = sprintf('%s: doc %s ≠ esperado %s', $d['campo'], $d['documento'], $d['manifiesto']);
        }

        foreach ($notas as $nota) {
            if (!str_contains($nota, 'girado')) {
                $frases[] = $nota;
            }
        }

        return $frases;
    }

    /**
     * @param list<string>|null $soloEstos ids de pasajero; `null` es el expediente entero
     */
    public function generar(CotizacionFile $file, ?array $soloEstos = null): string
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Documentos');

        // Las columnas de estado salen de lo que el expediente pide, así que la hoja cambia de
        // ancho entre expedientes. Todo lo que se posicionaba con `$ultima - N` sigue valiendo.
        $escaneos = $this->escaneosPedidos($file);
        $cabeceras = $this->cabeceras($escaneos);
        $ultima = count($cabeceras);
        $inicioObserva = $this->dondeEmpiezanLasObservaciones(count($escaneos));

        // Título: el expediente y CUÁNDO se sacó. Una hoja de faltantes sin fecha se reenvía
        // semanas después como si siguiera vigente.
        // ⚠️ **Y si es un SUBCONJUNTO, lo dice en el título.** La hoja respeta los filtros de la
        // pantalla, así que puede llevar 30 de 133 personas — y una vez descargada no hay forma de
        // saberlo: se reenvía por correo como si fuera el manifiesto entero, y quien la reciba
        // concluirá que a los otros 103 no les falta nada.
        $hoja->setCellValue([1, 1], sprintf(
            'Documentos de identidad — %s — generado el %s%s',
            (string) $file->getNombreGrupo(),
            (new \DateTimeImmutable())->format('d/m/Y H:i'),
            $soloEstos === null ? '' : sprintf(
                ' — ⚠ SELECCIÓN FILTRADA: %d de %d personas',
                count($soloEstos),
                count($file->getFilepasajeros()),
            ),
        ));
        $hoja->mergeCells([1, 1, $ultima, 1]);
        $hoja->getStyle([1, 1])->getFont()->setBold(true)->setSize(12);
        $hoja->getRowDimension(1)->setRowHeight(22);

        foreach ($cabeceras as $i => $cabecera) {
            $hoja->setCellValue([$i + 1, 2], $cabecera);
        }
        $hoja->getStyle([1, 2, $ultima, 2])->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getRowDimension(2)->setRowHeight(20);

        $permitidos = $soloEstos === null ? null : array_flip($soloEstos);
        $fila = 3;
        $completos = 0;
        $parciales = 0;
        $vacios = 0;
        $archivos = 0;
        $observados = 0;

        foreach ($this->ordenados($file, $permitidos) as $pasajero) {

            $subidos = $this->escaneosDe($file, $pasajero, $escaneos);
            $faltan = [];

            $this->texto($hoja, 1, $fila, $this->gruposDe($pasajero));
            $this->texto($hoja, 2, $fila, $pasajero->getApellido());
            $this->texto($hoja, 3, $fila, $pasajero->getNombre());
            $this->texto($hoja, 4, $fila, $pasajero->getTipo()?->label());
            $this->texto($hoja, 5, $fila, $this->documento($pasajero, DocumentoTipoEnum::DNI));
            $this->texto($hoja, 6, $fila, $this->documento($pasajero, DocumentoTipoEnum::PASAPORTE));
            $this->texto($hoja, 7, $fila, $this->otrosDocumentos($pasajero));

            $columna = 8;
            $total = 0;
            foreach ($escaneos as $etiqueta => $tipo) {
                /** @var list<\DateTimeImmutable|null> $cuando */
                $cuando = $subidos[$tipo->value] ?? [];
                $cuantos = count($cuando);

                if ($cuantos === 0) {
                    $faltan[] = $etiqueta;
                    $this->texto($hoja, $columna, $fila, '0');
                    $hoja->getStyle([$columna, $fila])->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::ROJO);
                } else {
                    // ⚠️ La CANTIDAD siempre delante, aunque sea «1». Escribirla sólo cuando hay
                    // varios convierte la ausencia del número en un dato que hay que deducir, y
                    // una columna en la que casi todo son fechas no se lee como un recuento: el
                    // duplicado pasaría desapercibido justo cuando importa.
                    $reciente = $cuando[0]?->format('d/m/Y');
                    $this->texto($hoja, $columna, $fila, $reciente === null
                        ? (string) $cuantos
                        : sprintf('%d · %s', $cuantos, $reciente));
                    // Ámbar para los duplicados: hay documento, pero hay que mirarlo.
                    $hoja->getStyle([$columna, $fila])->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()
                        ->setARGB($cuantos > 1 ? self::AMBAR : self::VERDE);
                }

                $total += $cuantos;
                ++$columna;
            }

            // El total al lado de las celdas de estado: con tantos como pedidos se sabe que está
            // completo sin sumarlas, y con uno más se sabe que sobra algo sin buscar cuál.
            $colTotal = $inicioObserva - 2;
            $hoja->setCellValueExplicit([$colTotal, $fila], (string) $total, DataType::TYPE_NUMERIC);
            $hoja->getStyle([$colTotal, $fila])->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            if ($total > count($escaneos)) {
                $hoja->getStyle([$colTotal, $fila])->getFont()->setBold(true);
                $hoja->getStyle([$colTotal, $fila])->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AMBAR);
            }

            $this->texto($hoja, $inicioObserva - 1, $fila, $faltan === [] ? 'Completo' : implode(', ', $faltan));
            if ($faltan !== []) {
                $hoja->getStyle([$inicioObserva - 1, $fila])->getFont()->setBold(true);
            }

            // Cada observación, en la columna de SU documento. Así se puede filtrar «enséñame a
            // quién le falla el E-Ticket», que con una celda global no se podía.
            $observaciones = $this->observacionesPorTipo($file, $pasajero, $escaneos);
            $columna = $inicioObserva;

            foreach ($escaneos as $tipo) {
                $suyas = $observaciones[$tipo->value] ?? [];

                if ($suyas !== []) {
                    $this->texto($hoja, $columna, $fila, implode(' · ', $suyas));
                    $hoja->getStyle([$columna, $fila])->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AMBAR);
                }

                ++$columna;
            }

            if ($observaciones !== []) {
                ++$observados;
            }

            $archivos += $total;

            match (count($faltan)) {
                0 => $completos++,
                count($escaneos) => $vacios++,
                default => $parciales++,
            };

            ++$fila;
        }

        $personas = $fila - 3;

        // El resumen ABAJO y no arriba: arriba desplazaría las filas y rompería el autofiltro.
        $hoja->setCellValue([1, $fila + 1], sprintf(
            '%s · %s · %d a medias · %d sin nada · %s en total%s',
            $this->plural($personas, 'persona', 'personas'),
            $this->plural($completos, 'completa', 'completas'),
            $parciales,
            $vacios,
            $this->plural($archivos, 'archivo', 'archivos'),
            $observados === 0 ? '' : sprintf(' · %d con observaciones', $observados),
        ));
        $hoja->mergeCells([1, $fila + 1, $ultima, $fila + 1]);
        $hoja->getStyle([1, $fila + 1])->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::GRIS]],
        ]);

        if ($personas > 0) {
            $hoja->getStyle([1, 2, $ultima, $fila - 1])->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
            $hoja->setAutoFilter($hoja->calculateWorksheetDimension());
        }

        // 🔥 **Sólo la CABECERA, no las columnas.** Congelaba también «Apellidos» y «Nombres» —al
        // desplazarse a la derecha se pierde de quién es la fila— y en el escritorio eso está bien.
        // En el móvil es lo contrario de útil: tres columnas congeladas se comen casi toda la
        // pantalla, el área que queda para desplazarse es una rendija y **la hoja se vuelve
        // imposible de leer**. Y esta hoja se abre en el móvil, que es donde se está cuando hay que
        // perseguir documentos.
        //
        // ⚠️ La fila de cabecera sí se queda: no cuesta ancho —congela hacia abajo, no hacia el
        // lado— y sin ella no se sabe qué columna se está mirando.
        //
        // Lo que se pierde en el escritorio tiene remedio a mano: el autofiltro ya está puesto, y
        // quien necesite seguir una fila concreta ordena o filtra por su apellido.
        $hoja->freezePane('A3');

        foreach (range(1, $ultima) as $c) {
            $hoja->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $escritor = new Xlsx($libro);
        ob_start();
        $escritor->save('php://output');
        $contenido = (string) ob_get_clean();
        $libro->disconnectWorksheets();

        return $contenido;
    }

    /** «1 persona» y no «1 personas»: el resumen filtrado llega a uno más a menudo de lo que parece. */
    private function plural(int $cuantos, string $singular, string $plural): string
    {
        return sprintf('%d %s', $cuantos, $cuantos === 1 ? $singular : $plural);
    }

    private function texto(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja, int $columna, int $fila, ?string $valor): void
    {
        if ($valor === null || $valor === '') {
            return;
        }
        // Explícito SIEMPRE: un DNI que empieza por cero deja de serlo si Excel lo lee como número.
        $hoja->setCellValueExplicit([$columna, $fila], $valor, DataType::TYPE_STRING);
    }

    /**
     * Los escaneos de esa persona, agrupados por tipo y **del más nuevo al más viejo**.
     *
     * ⚠️ Sólo los tipos que este expediente PIDE: son los que tienen columna. Uno que ya no se
     * exige pero que alguien mandó en su día no cuenta como archivo aquí, porque no hay dónde
     * enseñarlo y sumarlo al total haría que el recuento no cuadrase con las celdas de al lado.
     *
     * @param array<string, ArchivoTipoEnum> $escaneos
     *
     * @return array<string, list<\DateTimeImmutable|null>>
     */
    private function escaneosDe(CotizacionFile $file, CotizacionFilepasajero $pasajero, array $escaneos): array
    {
        $id = (string) $pasajero->getId();
        $porTipo = [];

        foreach ($file->getFilearchivos() as $archivo) {
            $tipo = $archivo->getTipoArchivo();
            if ($tipo === null || !in_array($tipo, $escaneos, true)) {
                continue;
            }
            if ((string) $archivo->getPasajero()?->getId() !== $id) {
                continue;
            }
            $porTipo[$tipo->value][] = $archivo->getCreatedAt();
        }

        foreach ($porTipo as &$fechas) {
            usort($fechas, static fn (?\DateTimeImmutable $a, ?\DateTimeImmutable $b): int
                => ($b?->getTimestamp() ?? 0) <=> ($a?->getTimestamp() ?? 0));
        }

        return $porTipo;
    }

    /** El número del documento de ese tipo, si lo tiene. */
    private function documento(CotizacionFilepasajero $pasajero, DocumentoTipoEnum $tipo): ?string
    {
        foreach ($pasajero->getIdentificaciones() as $identificacion) {
            if ($identificacion->getTipo() === $tipo) {
                return $identificacion->getNumero();
            }
        }

        return null;
    }

    /**
     * Lo que no es DNI ni pasaporte —CE, CI, RUC— con su tipo delante.
     *
     * Va en una sola columna porque son la excepción: darles una columna a cada uno dejaría tres
     * vacías en el 95 % de los expedientes.
     */
    private function otrosDocumentos(CotizacionFilepasajero $pasajero): ?string
    {
        $otros = [];

        foreach ($pasajero->getIdentificaciones() as $identificacion) {
            $tipo = $identificacion->getTipo();
            if ($tipo === null || $tipo === DocumentoTipoEnum::DNI || $tipo === DocumentoTipoEnum::PASAPORTE) {
                continue;
            }
            $otros[] = sprintf('%s %s', $tipo->value, (string) $identificacion->getNumero());
        }

        return $otros === [] ? null : implode(' · ', $otros);
    }

    /**
     * A qué grupo pertenece.
     *
     * Sólo los de tipo `grupo`: la habitación y la reserva aérea también son grupos suyos, pero
     * quien reclama documentos lo hace por aula, no por vuelo.
     */
    private function gruposDe(CotizacionFilepasajero $pasajero): string
    {
        $claves = [];

        foreach ($pasajero->grupos() as $grupo) {
            if ($grupo->getTipo() !== GrupoTipoEnum::GRUPO) {
                continue;
            }
            $claves[] = (string) $grupo->getClave();
        }

        // ⚠️ Explícito y no en blanco. 23 de las 133 personas del padrón real —acompañantes,
        // supervisores, invitados— no van en ningún grupo, y una celda vacía en una hoja de
        // faltantes se lee como «esto no se ha rellenado», que es justo lo contrario.
        return $claves === [] ? '— sin grupo' : implode(', ', $claves);
    }

    /**
     * Las personas que entran en la hoja, **por grupo y dentro de él por rango**.
     *
     * Ordenar por grupo es lo que convierte la hoja en la lista con la que se reclama: los
     * documentos se piden por grupo, y el coordinador de ese grupo encabeza su bloque porque es a
     * quien se le escribe. Dentro del mismo rango, alfabético.
     *
     * ⚠️ Los que no van en ningún grupo caen al final en bloque: el `— sin grupo` ordena después
     * de cualquier cifra.
     *
     * @param array<string, int>|null $permitidos
     *
     * @return list<CotizacionFilepasajero>
     */
    private function ordenados(CotizacionFile $file, ?array $permitidos): array
    {
        $personas = [];

        foreach ($file->getFilepasajeros() as $pasajero) {
            if ($permitidos !== null && !isset($permitidos[(string) $pasajero->getId()])) {
                continue;
            }
            $personas[] = $pasajero;
        }

        usort($personas, static function (CotizacionFilepasajero $a, CotizacionFilepasajero $b): int {
            $grupoA = self::claveDeOrden($a);
            $grupoB = self::claveDeOrden($b);

            return [$grupoA, $a->getTipo()?->rangoDeLectura() ?? 90, mb_strtolower(trim(sprintf('%s %s', $a->getApellido(), $a->getNombre())))]
                <=> [$grupoB, $b->getTipo()?->rangoDeLectura() ?? 90, mb_strtolower(trim(sprintf('%s %s', $b->getApellido(), $b->getNombre())))];
        });

        return $personas;
    }

    /**
     * Con qué se ordena el grupo de alguien.
     *
     * ⚠️ Los grupos son «1»…«9» pero la clave es TEXTO libre —lo mismo vale «A» o «Bus rojo»—, así
     * que `'10'` iría antes que `'9'` si se comparase como cadena. Se acolcha a la izquierda para
     * que las cifras ordenen como cifras sin dejar de admitir lo que no lo es.
     */
    private static function claveDeOrden(CotizacionFilepasajero $pasajero): string
    {
        $claves = [];

        foreach ($pasajero->grupos() as $grupo) {
            if ($grupo->getTipo() !== GrupoTipoEnum::GRUPO) {
                continue;
            }
            $clave = (string) $grupo->getClave();
            $claves[] = ctype_digit($clave) ? str_pad($clave, 6, '0', STR_PAD_LEFT) : $clave;
        }

        sort($claves);

        // «~» ordena después de las cifras y de las letras: los sin grupo, al final.
        return $claves === [] ? '~' : $claves[0];
    }
}
