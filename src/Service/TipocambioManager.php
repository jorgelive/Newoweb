<?php
declare(strict_types=1);

namespace App\Service;

use App\Dto\Lee;
use App\Dto\ExchangeRateDto;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Maestro\MaestroTipocambio;
use App\Entity\Maestro\MaestroTipocambioConsulta;
use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TipocambioManager
{
    private const MONEDA_TARGET = MaestroMoneda::DB_ID_USD;

    /**
     * De dónde sale el tipo de cambio SUNAT.
     *
     * ⚠️ **Era `https://api.apis.net.pe/v1/tipo-cambio-sunat` y ese proveedor se mudó.**
     * apis.net.pe migró a decolecta.com y de paso reestructuró las rutas; la vieja empezó a
     * devolver 404 —con token y sin él— el 26/08/2026 y nadie se enteró hasta el 10/09, porque
     * el respaldo de `findLastAvailableInDb()` seguía sirviendo la última cotización buena.
     *
     * El token también es nuevo: se saca en https://decolecta.com/profile y NO es el de
     * apis.net.pe, que allí responde 401.
     */
    private const ENDPOINT = 'https://api.decolecta.com/v1/tipo-cambio/sunat';

    /**
     * Los nombres de los campos en la respuesta, que **también cambiaron**.
     *
     * ⚠️ Ésta es la mitad silenciosa de la migración y la que casi cuesta otra quincena a
     * ciegas. Antes venía `{fecha, compra, venta}`; ahora
     * `{date, buy_price, sell_price, base_currency, quote_currency}`. Si se hubiera cambiado
     * sólo la URL, `parseResponse()` habría descartado **todas** las filas en su `isset()` y
     * devuelto un array vacío: exactamente el mismo síntoma que el proveedor caído, con la API
     * funcionando. Un error que se disfraza del error anterior es el peor de depurar.
     *
     * Van como constantes para que el día que vuelvan a cambiar se vea en un sitio y no en tres.
     */
    private const CAMPO_FECHA = 'date';
    private const CAMPO_COMPRA = 'buy_price';
    private const CAMPO_VENTA = 'sell_price';

    /**
     * Peticiones HTTP al proveedor como máximo por día (hora de Lima), sumando todos los procesos.
     *
     * Pasado el tope no se llama hasta el día siguiente y rige la última cotización en base, que
     * es el mismo respaldo de cuando la API falla. En régimen normal se gastan ~12 al día (medido
     * del 1 al 8/10/2026), así que 20 no estorba; con la API caída, sin tope, eran ~170. Ver
     * {@see MaestroTipocambioConsulta}.
     */
    public const MAX_CONSULTAS_DIA = 20;

    /**
     * Minutos mínimos entre dos peticiones, de cualquier proceso.
     *
     * El tope diario solo no basta: deja gastar los 20 en una ráfaga de un minuto. Con esto el
     * peor caso son 3 por hora. ⚠️ Consecuencia buscada: el intento B de `fetchExternalData()`
     * (el día suelto tras un mes vacío) casi nunca sale en la misma pasada — espera 20 minutos y
     * lo vuelve a intentar quien pida la tasa después. Vale la pena: el mes vacío sólo ocurre el
     * día 1, antes de que SUNAT publique.
     */
    public const MINUTOS_ENTRE_CONSULTAS = 20;

    /**
     * Por qué falló la última llamada al proveedor, o `null` si la última fue bien.
     *
     * Existe para que el aviso de `app:pms:tipo-cambio:sincronizar` diga la CAUSA y no sólo el
     * síntoma. El 09/10/2026 se agotó la cuota del plan gratis (100 al mes) y decolecta pasó a
     * contestar 403; el aviso sólo podía decir «lleva N días sin actualizarse», y la causa
     * estaba en una línea de `tipo_cambio.log` que nadie abre.
     */
    private ?string $ultimoFallo = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly string $sunatApiToken
    ) {}

    /**
     * La causa del último fallo: el de este proceso o, si aquí no se llamó, el que anotó otro
     * proceso hoy en la tabla. El cron puede no llegar a llamar —tope gastado de madrugada—
     * y aun así tiene que poder decir por qué.
     */
    public function ultimoFallo(): ?string
    {
        if ($this->ultimoFallo !== null) {
            return $this->ultimoFallo;
        }

        // Protegido: lo llama la alarma del cron, y un fallo de la base aquí la mataría antes de
        // avisar a nadie (revisión de Fable, 13/10/2026). Sin causa es mejor que sin aviso.
        try {
            $guardado = $this->em->getConnection()->fetchOne(
                'SELECT ultimo_fallo FROM maestro_tipocambio_consulta WHERE dia = ?',
                [$this->hoyEnLima()]
            );
        } catch (Exception $e) {
            $this->logger->error('[TipoCambio] No se pudo leer la causa del último fallo: ' . $e->getMessage());

            return null;
        }

        return is_string($guardado) ? $guardado : null;
    }

    /**
     * Reserva UNA petición del cupo de hoy. `false` si ya se gastó o si la última salió hace
     * menos de {@see self::MINUTOS_ENTRE_CONSULTAS} minutos.
     *
     * ⚠️ Atómico a propósito: el `UPDATE … WHERE intentos < tope AND última <= hace 20 min` lo
     * decide MySQL, así que dos procesos a la vez no pueden pasarse de ninguna de las dos. Leer,
     * comparar y escribir por separado sí podría, y con php-fpm, workers y cron llamando a la
     * vez, pasaría. La hora es la de MySQL (`NOW()`) en la escritura y en la comparación: un solo
     * reloj, sin zonas de por medio.
     */
    private function reservarConsulta(): bool
    {
        $conexion = $this->em->getConnection();
        $hoy = $this->hoyEnLima();

        $conexion->executeStatement(
            'INSERT IGNORE INTO maestro_tipocambio_consulta (dia, intentos) VALUES (?, 0)',
            [$hoy]
        );

        $reservadas = $conexion->executeStatement(
            'UPDATE maestro_tipocambio_consulta SET intentos = intentos + 1, ultima_peticion = NOW() '
            . 'WHERE dia = ? AND intentos < ? '
            . 'AND (ultima_peticion IS NULL OR ultima_peticion <= NOW() - INTERVAL ? MINUTE)',
            [$hoy, self::MAX_CONSULTAS_DIA, self::MINUTOS_ENTRE_CONSULTAS]
        );

        return $reservadas > 0;
    }

    /** `null` = la última llamada fue bien: se borra la causa de hoy. */
    private function anotarFallo(?string $fallo): void
    {
        $this->ultimoFallo = $fallo;

        try {
            $this->em->getConnection()->executeStatement(
                'UPDATE maestro_tipocambio_consulta SET ultimo_fallo = ? WHERE dia = ?',
                [$fallo === null ? null : mb_substr($fallo, 0, 255), $this->hoyEnLima()]
            );
        } catch (Exception $e) {
            // Anotar la causa es para el aviso; no puede tumbar a quien pidió la tasa.
            $this->logger->error('[TipoCambio] No se pudo anotar el fallo: ' . $e->getMessage());
        }
    }

    /** Hoy en Lima, que es el día de SUNAT. El servidor va en UTC. */
    /** ¿Es posterior a hoy? Se compara el día de calendario de Lima, en texto `Y-m-d`. */
    public static function esFutura(\DateTimeInterface $fecha, string $hoyEnLima): bool
    {
        return $fecha->format('Y-m-d') > $hoyEnLima;
    }

    private function hoyEnLima(): string
    {
        return (new DateTimeImmutable('now', new \DateTimeZone('America/Lima')))->format('Y-m-d');
    }

    /**
     * ⚠️ Acepta `DateTimeInterface`, no `DateTime`. Con la firma estrecha, pasarle un
     * `DateTimeImmutable` —que es lo que devuelven media docena de getters del PMS— era un
     * `TypeError` en producción, la misma familia de fallo que tumbó la sincronización de
     * reservas canceladas el 15/08/2026 (`docs/Mensajeria.md` §22.16).
     *
     * El `createFromInterface` no es sólo por el tipo: sobre un `DateTimeImmutable`,
     * `(clone $f)->setTime(...)` **devuelve** una instancia nueva y descarta el cambio en la
     * clonada, así que la normalización a medianoche se perdía en silencio.
     */
    public function getTipodecambio(\DateTimeInterface $fechaInput): ?MaestroTipocambio
    {
        $fechaBuscada = DateTime::createFromInterface($fechaInput)->setTime(0, 0, 0);

        $repo = $this->em->getRepository(MaestroTipocambio::class);
        $usdRef = $this->getUsdRef(); // Obtenemos el Proxy una sola vez

        // ✅ CORRECCIÓN 2: Pasamos la Referencia (Objeto), no el string ID
        // 1) Caché Local (BD)
        $enDB = $repo->findOneBy([
            'moneda' => $usdRef,
            'fecha'  => $fechaBuscada
        ]);

        if ($enDB instanceof MaestroTipocambio) {
            return $enDB;
        }

        // 🔮 Una fecha FUTURA no se pregunta (19/10/2026). SUNAT no publica cambios por adelantado,
        // así que la respuesta es siempre vacía y se acaba en el respaldo de abajo — pero gastando
        // una consulta del cupo diario y armando los 20 minutos de espera entre consultas. Quien
        // la pide es el depósito automático de una OTA (`PmsPagoOtaAutomaticoService`), fechado el
        // día después de la llegada: una reserva de Airbnb para noviembre entrada el 18/10 a las
        // 21:24 pidió `month=11` y frenó la siguiente consulta de verdad.
        if (self::esFutura($fechaBuscada, $this->hoyEnLima())) {
            return $this->findLastAvailableInDb($fechaBuscada);
        }

        // 2) Consultar API (usamos la fecha original para el request, da igual la hora)
        $dtos = $this->fetchExternalData($fechaBuscada);

        // 3) Fallback si API falla
        if (empty($dtos)) {
            return $this->findLastAvailableInDb($fechaBuscada);
        }

        // 4) Guardado Masivo, por SQL directo: ver el docblock de `persistMonthData()`.
        $this->persistMonthData($dtos);

        // 5) Retorno: Buscar match en la data fresca
        $bestDto = $this->findBestMatch($dtos, $fechaBuscada);

        if (!$bestDto instanceof ExchangeRateDto) {
            return $this->findLastAvailableInDb($fechaBuscada);
        }

        // Se lee de la base la fila que acaba de entrar por SQL: hidratarla es un SELECT, que se
        // puede hacer en mitad de un flush sin tocar su UnitOfWork.
        $fechaDto = DateTime::createFromImmutable($bestDto->date)->setTime(0, 0, 0);

        return $repo->findOneBy([
            'moneda' => $usdRef,
            'fecha'  => $fechaDto
        ]);
    }

    /**
     * Las cotizaciones que devuelve SUNAT, indexadas por fecha `Y-m-d`.
     *
     * @return array<string, ExchangeRateDto>
     */
    private function fetchExternalData(DateTime $fecha): array
    {
        // Intento A: Mes completo. `month` va SIN cero delante —la API lo declara `integer`
        // del 1 al 12— y por eso es `(int)` y no `format('m')`, que daría «09».
        $data = $this->callApi([
            'month' => (string) (int) $fecha->format('m'),
            'year'  => $fecha->format('Y'),
        ]);

        if (!empty($data)) {
            return $this->parseResponse($data);
        }

        $this->logger->warning('Consulta mensual del tipo de cambio vacía. Intentando diaria.');

        // Intento B: Día exacto. El parámetro es `date`, no `fecha`.
        $data = $this->callApi([
            'date' => $fecha->format('Y-m-d')
        ]);

        return $this->parseResponse($data);
    }

    /**
     * Llama a la API y devuelve SIEMPRE una lista de filas, venga una o vengan treinta.
     *
     * La API contesta de dos formas según se le pida un día o un mes: un objeto suelto
     * (`{date, buy_price, sell_price}`) o una lista de esos objetos. Normalizar aquí es lo que le
     * permite a `parseResponse()` recorrer sin preguntarse cuál de las dos le tocó.
     *
     * @param array<string, string> $queryParams
     *
     * @return list<array<mixed>>
     */
    private function callApi(array $queryParams): array
    {
        // ⚠️ La reserva va FUERA del `try` de la llamada, con su propio `catch`. Dentro, un fallo de
        // la base se registraba como «sin conexión con decolecta»: el mismo disfraz que tapó el
        // 404 de agosto, ahora con la base de culpable y el proveedor de acusado (Fable,
        // 13/10/2026). Sin cupo reservable no se llama: falla cerrado, nunca abierto.
        try {
            $reservada = $this->reservarConsulta();
        } catch (Exception $e) {
            $this->logger->error('[TipoCambio] No se pudo reservar cupo en maestro_tipocambio_consulta: ' . $e->getMessage());
            $this->ultimoFallo = 'no se pudo reservar cupo en la tabla maestro_tipocambio_consulta (fallo de la base, no del proveedor)';

            return [];
        }

        if (!$reservada) {
            // Frenada por el tope o por la espera: se cuenta la causa que haya anotada HOY, y si
            // no hay ninguna —la última llamada fue bien— NO se inventa. Antes ponía «frenada por
            // el tope» y soporte iba a mirar la cuota cuando el proveedor contestaba 200 con datos
            // inservibles (Fable, 13/10/2026).
            $this->ultimoFallo ??= $this->ultimoFallo();
            $this->logger->notice(sprintf(
                '[TipoCambio] Consulta frenada: tope de %d al día o menos de %d min desde la última.',
                self::MAX_CONSULTAS_DIA,
                self::MINUTOS_ENTRE_CONSULTAS
            ));

            return [];
        }

        try {
            $response = $this->client->request('GET', self::ENDPOINT, [
                'query' => $queryParams,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->sunatApiToken,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'timeout' => 8,
            ]);

            $codigo = $response->getStatusCode();

            if ($codigo === 200) {
                $this->anotarFallo(null);
                $raw = $response->toArray();

                if (isset($raw[self::CAMPO_FECHA])) {
                    return [$raw];
                }

                // Lo que no sea una fila se descarta aquí en vez de más adelante: `parseResponse()`
                // ya lo ignoraba —un `isset()` sobre un escalar es falso—, así que no cambia lo
                // que entra, sólo dónde se decide.
                return array_values(array_filter($raw, 'is_array'));
            }

            // 🔴 **ESTO ES LO QUE FALTÓ QUINCE DÍAS.** El `catch` de abajo sólo ve excepciones de
            // red; un 404 o un 401 son respuestas perfectamente válidas de HttpClient y salían de
            // aquí como un `[]` mudo, indistinguible de «hoy no hay cotización». Con el respaldo
            // sirviendo la última tasa buena, el proveedor pudo morirse sin que nadie lo notara:
            // del 26/08 al 10/09/2026 se sellaron 49 cargos, 20 pagos y 18 fichas con la tasa del
            // 26/08, y el único rastro era un WARNING de «consulta vacía» repetido 74 veces.
            $this->logger->error(sprintf(
                'API de tipo de cambio devolvió HTTP %d para %s. Se usará la última cotización '
                . 'disponible, que puede estar desfasada.',
                $codigo,
                http_build_query($queryParams)
            ));

            // Un 403 de decolecta es una página de nginx, no su JSON: es la cuota mensual agotada
            // (o la llave bloqueada). Una llave inválida da 401. Medido el 13/10/2026.
            $this->anotarFallo($codigo === 403
                ? 'decolecta responde HTTP 403: cuota del plan agotada o llave bloqueada'
                : sprintf('decolecta responde HTTP %d', $codigo));
        } catch (Exception $e) {
            $this->logger->error('Error API tipo de cambio: ' . $e->getMessage());
            $this->anotarFallo('sin conexión con decolecta: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * La respuesta del proveedor, a DTO. Los importes se guardan como TEXTO tal cual llegan
     * (`Lee::texto()`: un número JSON pasa a texto igual que con el `(string)` de antes), porque
     * van a una columna DECIMAL y pasar por float los redondearía.
     *
     * ⚠️ **Un importe que no es un número deja la fila fuera**, igual que si faltara. Antes entraba
     * tal cual —un «N/A» del proveedor, o la palabra «Array»— hasta la columna DECIMAL, y ahí o
     * reventaba el `flush` o se guardaba un 0.000 como tipo de cambio de ese día. Sin la fila, rige
     * la última cotización buena, que es el respaldo que ya existe para «hoy no hay dato».
     *
     * @param list<array<mixed>> $lista
     *
     * @return array<string, ExchangeRateDto> Indexadas por fecha `Y-m-d`.
     */
    private function parseResponse(array $lista): array
    {
        $dtos = [];
        foreach ($lista as $item) {
            $fecha = Lee::texto($item[self::CAMPO_FECHA] ?? null);
            $compra = Lee::texto($item[self::CAMPO_COMPRA] ?? null);
            $venta = Lee::texto($item[self::CAMPO_VENTA] ?? null);

            if ($fecha === null || !is_numeric($compra) || !is_numeric($venta)) {
                // Ruidoso: el fallo del 26/08 costó quince días por un vacío mudo, y una fila
                // descartada sin rastro es lo mismo — se serviría la tasa vieja sin que nadie lo sepa.
                $this->logger->warning(sprintf(
                    'Tipo de cambio: fila descartada, sin fecha o con importe no numérico (fecha %s, compra %s, venta %s).',
                    $fecha ?? '—',
                    $compra ?? get_debug_type($item[self::CAMPO_COMPRA] ?? null),
                    $venta ?? get_debug_type($item[self::CAMPO_VENTA] ?? null),
                ));

                continue;
            }
            $fechaStr = substr($fecha, 0, 10);

            $dtos[$fechaStr] = new ExchangeRateDto(
                new DateTimeImmutable($fechaStr), // El time vendrá 00:00:00 por defecto en immutable desde Y-m-d
                $compra,
                $venta,
                Lee::texto($item['base_currency'] ?? null) ?? self::MONEDA_TARGET
            );
        }
        return $dtos;
    }

    /**
     * Guarda las cotizaciones que falten, **por SQL directo y sin pasar por el ORM**.
     *
     * 🔴 **Antes era `persist()` + `flush()`, y ese `flush` corría DENTRO de otro.** A esto se
     * llega desde `PmsTipoCambioSnapshotListener::sellarFicha()`, un `prePersist` que dispara
     * `PmsInformacionFinancieraCoherenciaListener::onFlush()` al crear la cabecera de una reserva
     * nueva. El `flush` interno volvía a ver la reserva como inserción pendiente y le creaba OTRA
     * cabecera, cuyo `prePersist` volvía a pedir la tasa —que aún no estaba en la base—, volvía a
     * llamar a decolecta, y vuelta a empezar. Al deshacerse la pila, la segunda cabecera chocaba
     * con `UNIQ_2AFB3104D67139E8` (`reserva_id`) y el EntityManager quedaba **cerrado**: lo que
     * viniera detrás en ese proceso fallaba con un `1064` sin sentido.
     *
     * En producción, el 09/10/2026 (2PFZNT) fueron 70 peticiones en dos segundos y el 13/10
     * (MDQNXT) **unas mil en dos minutos**, hasta que decolecta empezó a contestar 403: esa
     * recursión es la que agotó la cuota del plan. El tope diario del 13/10 la corta hoy en el
     * segundo nivel, pero el `flush` anidado seguía rompiendo el EntityManager.
     *
     * Por SQL no hay UnitOfWork que reabrir: quien pida la tasa —un listener, un comando, un
     * controlador— no dispara ningún flush. `MaestroTipocambio` no tiene listeners que saltarse
     * (sólo `created_at`, que se escribe aquí), así que no se pierde nada al no pasar por el ORM.
     *
     * ⚠️ **`ON DUPLICATE KEY UPDATE`, no `INSERT IGNORE`.** Los dos se apoyan en
     * `uniq_tipocambio_moneda_fecha` para que dos procesos a la vez no dupliquen el día (ya pasó
     * el 15/09/2026), pero `IGNORE` también rebaja a aviso un valor que no cabe en la columna
     * DECIMAL y guarda un 0.000 como tipo de cambio de ese día. Éste sólo perdona la clave
     * repetida.
     *
     * @param array<string, ExchangeRateDto> $dtos Indexadas por fecha `Y-m-d`.
     */
    private function persistMonthData(array $dtos): void
    {
        $conexion = $this->em->getConnection();
        // Misma hora que pondría `TimestampTrait::setTimestampsOnPersist()`.
        $ahora = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($dtos as $dto) {
            // `MONEDA_TARGET` ES 'USD' (MaestroMoneda::DB_ID_USD), así que comparar contra las dos
            // era la misma comprobación escrita dos veces. Se queda la constante, que es la que
            // sigue al maestro si algún día ese id deja de ser el literal.
            if ($dto->currencyCode !== self::MONEDA_TARGET) {
                continue;
            }

            $conexion->executeStatement(
                'INSERT INTO maestro_tipocambio (id, moneda_id, fecha, compra, venta, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE id = id',
                [
                    Uuid::v7()->toBinary(),
                    self::MONEDA_TARGET,
                    $dto->date->format('Y-m-d'),
                    $dto->buy,
                    $dto->sell,
                    $ahora,
                ],
                [ParameterType::BINARY]
            );
        }
    }

    /**
     * La cotización del día pedido o, si no la hay, la del día hábil anterior más cercano
     * dentro de una semana. SUNAT no publica fines de semana ni feriados.
     *
     * @param array<string, ExchangeRateDto> $dtos Indexadas por fecha `Y-m-d`.
     */
    private function findBestMatch(array $dtos, DateTime $targetDate): ?ExchangeRateDto
    {
        $tempDate = clone $targetDate;
        for ($i = 0; $i < 7; $i++) {
            $key = $tempDate->format('Y-m-d');
            if (isset($dtos[$key])) {
                return $dtos[$key];
            }
            $tempDate->modify('-1 day');
        }
        return null;
    }

    private function findLastAvailableInDb(DateTime $fecha): ?MaestroTipocambio
    {
        $repo = $this->em->getRepository(MaestroTipocambio::class);
        $usdRef = $this->getUsdRef();

        // Buscamos <= fecha (medianoche inclusive)
        /** @var \App\Entity\Maestro\MaestroTipocambio|null $resultado */
        $resultado = $repo->createQueryBuilder('tc')
            ->where('tc.moneda = :moneda')
            ->andWhere('tc.fecha <= :fecha')
            ->setParameter('moneda', $usdRef)
            ->setParameter('fecha', $fecha)
            ->orderBy('tc.fecha', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
            ?? $repo->findOneBy(['moneda' => $usdRef], ['fecha' => 'DESC']);

        return $resultado;
    }

    /**
     * Devuelve el Proxy (Referencia) de la moneda.
     * Doctrine no hace SELECT, solo crea el objeto envoltorio con el ID.
     */
    private function getUsdRef(): MaestroMoneda
    {
        // `getReference()` puede devolver null en Doctrine 3; sin la moneda en la base no hay a
        // qué referir el tipo de cambio.
        return $this->em->getReference(MaestroMoneda::class, self::MONEDA_TARGET)
            ?? throw new \LogicException('No existe la moneda ' . self::MONEDA_TARGET . ' en maestro_moneda.');
    }
}