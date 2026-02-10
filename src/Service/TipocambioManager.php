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

    public function getTipodecambio(DateTime $fechaInput): ?MaestroTipocambio
    {
        // ✅ CORRECCIÓN 1: Normalizamos a medianoche para "match exacto"
        // Clonamos para no modificar la fecha original que pasó el controlador
        $fechaBuscada = (clone $fechaInput)->setTime(0, 0, 0);

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
                return isset($raw['fecha']) ? [$raw] : $raw;
            }
        } catch (Exception $e) {
            $this->logger->error('Error API SUNAT: ' . $e->getMessage());
        }

        return [];
    }

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
            if ($dto->currencyCode !== 'USD' && $dto->currencyCode !== self::MONEDA_TARGET) {
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