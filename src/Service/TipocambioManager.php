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
        // Intento A: Mes completo
        $data = $this->callApi([
            'month' => $fecha->format('m'),
            'year'  => $fecha->format('Y'),
        ]);

        if (!empty($data)) {
            return $this->parseResponse($data);
        }

        $this->logger->warning('Consulta mensual SUNAT vacía. Intentando diaria.');

        // Intento B: Día exacto
        $data = $this->callApi([
            'fecha' => $fecha->format('Y-m-d')
        ]);

        return $this->parseResponse($data);
    }

    /**
     * Llama a la API y devuelve SIEMPRE una lista de filas, venga una o vengan treinta.
     *
     * La API contesta de dos formas según se le pida un día o un mes: un objeto suelto
     * (`{fecha, compra, venta}`) o una lista de esos objetos. Normalizar aquí es lo que le
     * permite a `parseResponse()` recorrer sin preguntarse cuál de las dos le tocó.
     *
     * @param array<string, string> $queryParams
     *
     * @return list<array<string, mixed>>
     */
    private function callApi(array $queryParams): array
    {
        try {
            $response = $this->client->request('GET', 'https://api.apis.net.pe/v1/tipo-cambio-sunat', [
                'query' => $queryParams,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->sunatApiToken,
                    'Referer'       => 'https://apis.net.pe/tipo-de-cambio-sunat-api',
                    'Accept'        => 'application/json',
                ],
                'timeout' => 8,
            ]);

            if ($response->getStatusCode() === 200) {
                $raw = $response->toArray();

                if (isset($raw['fecha'])) {
                    return [$raw];
                }

                // Lo que no sea una fila se descarta aquí en vez de más adelante: `parseResponse()`
                // ya lo ignoraba —un `isset($item['fecha'])` sobre un escalar es falso—, así que
                // no cambia lo que entra, sólo dónde se decide.
                return array_values(array_filter($raw, 'is_array'));
            }
        } catch (Exception $e) {
            $this->logger->error('Error API SUNAT: ' . $e->getMessage());
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
            if (!isset($item['fecha'], $item['compra'], $item['venta'])) {
                continue;
            }
            $fechaStr = substr((string)$item['fecha'], 0, 10);

            $dtos[$fechaStr] = new ExchangeRateDto(
                new DateTimeImmutable($fechaStr), // El time vendrá 00:00:00 por defecto en immutable desde Y-m-d
                (string) $item['compra'],
                (string) $item['venta'],
                (string) ($item['moneda'] ?? self::MONEDA_TARGET)
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