<?php
declare(strict_types=1);

namespace App\Service;

use App\Dto\ExchangeRateDto;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Maestro\MaestroTipocambio;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly string $sunatApiToken
    ) {}

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

        // 2) Consultar API (usamos la fecha original para el request, da igual la hora)
        $dtos = $this->fetchExternalData($fechaBuscada);

        // 3) Fallback si API falla
        if (empty($dtos)) {
            return $this->findLastAvailableInDb($fechaBuscada);
        }

        // 4) Guardado Masivo
        $this->persistMonthData($dtos, $fechaBuscada);

        // 5) Retorno: Buscar match en la data fresca
        $bestDto = $this->findBestMatch($dtos, $fechaBuscada);

        if (!$bestDto instanceof ExchangeRateDto) {
            return $this->findLastAvailableInDb($fechaBuscada);
        }

        // Buscamos de nuevo en BD (IdentityMap lo hará instantáneo)
        // Asegurándonos de usar la fecha del DTO normalizada
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
     * @return list<array<string, mixed>>
     */
    private function callApi(array $queryParams): array
    {
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
        } catch (Exception $e) {
            $this->logger->error('Error API tipo de cambio: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $lista
     *
     * @return array<string, ExchangeRateDto> Indexadas por fecha `Y-m-d`.
     */
    private function parseResponse(array $lista): array
    {
        $dtos = [];
        foreach ($lista as $item) {
            if (!isset($item[self::CAMPO_FECHA], $item[self::CAMPO_COMPRA], $item[self::CAMPO_VENTA])) {
                continue;
            }
            $fechaStr = substr((string) $item[self::CAMPO_FECHA], 0, 10);

            $dtos[$fechaStr] = new ExchangeRateDto(
                new DateTimeImmutable($fechaStr), // El time vendrá 00:00:00 por defecto en immutable desde Y-m-d
                (string) $item[self::CAMPO_COMPRA],
                (string) $item[self::CAMPO_VENTA],
                (string) ($item['base_currency'] ?? self::MONEDA_TARGET)
            );
        }
        return $dtos;
    }

    /**
     * @param array<string, ExchangeRateDto> $dtos Indexadas por fecha `Y-m-d`.
     */
    private function persistMonthData(array $dtos, DateTime $fechaReferencia): void
    {
        $inicio = (clone $fechaReferencia)->modify('first day of this month')->setTime(0,0,0);
        $fin    = (clone $fechaReferencia)->modify('last day of this month')->setTime(23,59,59);

        // Obtenemos solo las fechas existentes
        $existingRows = $this->em->createQueryBuilder()
            ->select('tc.fecha')
            ->from(MaestroTipocambio::class, 'tc')
            ->where('tc.moneda = :moneda')
            ->andWhere('tc.fecha BETWEEN :inicio AND :fin')
            ->setParameter('moneda', $this->getUsdRef()) // Usamos referencia aquí también
            ->setParameter('inicio', $inicio)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getScalarResult();

        $existingMap = [];
        foreach ($existingRows as $row) {
            $fechaDb = is_string($row['fecha']) ? substr($row['fecha'], 0, 10) : $row['fecha']->format('Y-m-d');
            $existingMap[$fechaDb] = true;
        }

        $monedaRef = $this->getUsdRef();
        $batchSize = 20;
        $i = 0;

        foreach ($dtos as $dateKey => $dto) {
            // `MONEDA_TARGET` ES 'USD' (MaestroMoneda::DB_ID_USD), así que comparar contra las dos
            // era la misma comprobación escrita dos veces. Se queda la constante, que es la que
            // sigue al maestro si algún día ese id deja de ser el literal.
            if ($dto->currencyCode !== self::MONEDA_TARGET) {
                continue;
            }
            if (isset($existingMap[$dateKey])) {
                continue;
            }

            $entity = new MaestroTipocambio();
            // Aseguramos medianoche al persistir
            $entity->setFecha(DateTime::createFromImmutable($dto->date)->setTime(0, 0, 0));
            $entity->setCompra($dto->buy);
            $entity->setVenta($dto->sell);
            $entity->setMoneda($monedaRef);

            $this->em->persist($entity);

            if ((++$i % $batchSize) === 0) {
                $this->em->flush();
            }
        }

        if ($i > 0) {
            $this->em->flush();
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
        return $repo->createQueryBuilder('tc')
            ->where('tc.moneda = :moneda')
            ->andWhere('tc.fecha <= :fecha')
            ->setParameter('moneda', $usdRef)
            ->setParameter('fecha', $fecha)
            ->orderBy('tc.fecha', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
            ?? $repo->findOneBy(['moneda' => $usdRef], ['fecha' => 'DESC']);
    }

    /**
     * Devuelve el Proxy (Referencia) de la moneda.
     * Doctrine no hace SELECT, solo crea el objeto envoltorio con el ID.
     */
    private function getUsdRef(): MaestroMoneda
    {
        return $this->em->getReference(MaestroMoneda::class, self::MONEDA_TARGET);
    }
}