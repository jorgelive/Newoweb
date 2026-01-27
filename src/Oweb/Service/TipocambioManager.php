<?php
declare(strict_types=1);

namespace App\Oweb\Service;

use App\Dto\ExchangeRateDto;
use App\Oweb\Entity\MaestroMoneda;
use App\Oweb\Entity\MaestroTipocambio;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TipocambioManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly string $sunatApiToken
    ) {}

    public function getTipodecambio(DateTime $fecha): ?MaestroTipocambio
    {
        $repo = $this->em->getRepository(MaestroTipocambio::class);

        // 1) Caché Local (BD): día exacto
        $enDB = $repo->findOneBy([
            'moneda' => MaestroMoneda::DB_VALOR_DOLAR,
            'fecha' => $fecha
        ]);

        if ($enDB instanceof MaestroTipocambio) {
            return $enDB;
        }

        // 2) Consultar API (mes o día)
        $dtos = $this->fetchExternalData($fecha);

        // 3) Si API no responde / vacío => fallback a última fecha disponible en BD
        if (empty($dtos)) {
            return $this->findLastAvailableInDb($fecha);
        }

        // 4) Guardado Masivo
        $this->persistMonthData($dtos, $fecha);

        // 5) Retorno: buscamos el día exacto o el último hábil anterior (de la data de API)
        $bestDto = $this->findBestMatch($dtos, $fecha);

        if (!$bestDto instanceof ExchangeRateDto) {
            // Por seguridad: si vino data pero no match, igual caemos a BD
            return $this->findLastAvailableInDb($fecha);
        }

        // Buscamos la entidad recién creada para retornarla (o la existente)
        $entity = $repo->findOneBy([
            'moneda' => MaestroMoneda::DB_VALOR_DOLAR,
            'fecha' => DateTime::createFromImmutable($bestDto->date)
        ]);

        // Si por alguna razón no quedó en BD, fallback final
        return $entity instanceof MaestroTipocambio ? $entity : $this->findLastAvailableInDb($fecha);
    }

    private function fetchExternalData(DateTime $fecha): array
    {
        $headersNavegador = [
            'Authorization' => 'Bearer ' . $this->sunatApiToken,
            'Referer'       => 'https://apis.net.pe/tipo-de-cambio-sunat-api',
            'User-Agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept'        => 'application/json',
        ];

        // --- INTENTO A: Traer todo el MES (v1) ---
        try {
            $response = $this->client->request('GET', 'https://api.apis.net.pe/v1/tipo-cambio-sunat', [
                'query' => [
                    'month' => $fecha->format('m'),
                    'year'  => $fecha->format('Y'),
                ],
                'headers' => $headersNavegador,
                'timeout' => 8,
            ]);

            if ($response->getStatusCode() === 200) {
                return $this->parseResponse($response->toArray());
            }

            $this->logger->warning(sprintf('Consulta mensual v1 falló con código %d. Intentando diaria.', $response->getStatusCode()));

        } catch (Exception $e) {
            $this->logger->warning('Error conexión mensual v1: ' . $e->getMessage());
        }

        // --- INTENTO B: Fallback al DÍA EXACTO (v1) ---
        try {
            $response = $this->client->request('GET', 'https://api.apis.net.pe/v1/tipo-cambio-sunat', [
                'query' => [
                    'fecha' => $fecha->format('Y-m-d')
                ],
                'headers' => $headersNavegador,
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                return $this->parseResponse($response->toArray());
            }

            return [];

        } catch (Exception $e) {
            $this->logger->critical('Error fatal API SUNAT: ' . $e->getMessage());
            return [];
        }
    }

    private function parseResponse(array $data): array
    {
        $listaProcesar = [];

        if (isset($data['fecha'])) {
            $listaProcesar = [$data];
        } else {
            $listaProcesar = $data;
        }

        $dtos = [];
        foreach ($listaProcesar as $item) {
            if (!isset($item['fecha'], $item['compra'], $item['venta'])) {
                continue;
            }

            $fechaStr = substr((string)$item['fecha'], 0, 10);

            // 🔥 CORRECCIÓN AQUÍ: Casting explícito a (string)
            $dtos[$fechaStr] = new ExchangeRateDto(
                new DateTimeImmutable($fechaStr),
                (string) $item['compra'], // Force String
                (string) $item['venta'],  // Force String
                (string) ($item['moneda'] ?? MaestroMoneda::DB_CODIGO_DOLAR)
            );
        }

        return $dtos;
    }

    private function persistMonthData(array $dtos, DateTime $fechaReferencia): void
    {
        $inicio = (clone $fechaReferencia)->modify('first day of this month')->setTime(0,0,0);
        $fin    = (clone $fechaReferencia)->modify('last day of this month')->setTime(23,59,59);

        $existingRows = $this->em->getRepository(MaestroTipocambio::class)
            ->createQueryBuilder('tc')
            ->select('tc.fecha')
            ->where('tc.moneda = :moneda')
            ->andWhere('tc.fecha >= :inicio AND tc.fecha <= :fin')
            ->setParameter('moneda', MaestroMoneda::DB_VALOR_DOLAR)
            ->setParameter('inicio', $inicio)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();

        $existingMap = [];
        foreach ($existingRows as $row) {
            $f = $row['fecha'] instanceof \DateTimeInterface ? $row['fecha'] : new DateTime((string)$row['fecha']);
            $existingMap[$f->format('Y-m-d')] = true;
        }

        $monedaRef = $this->em->getReference(MaestroMoneda::class, MaestroMoneda::DB_VALOR_DOLAR);
        $batchSize = 20;
        $i = 0;

        foreach ($dtos as $dto) {
            if ($dto->currencyCode !== 'USD' && $dto->currencyCode !== MaestroMoneda::DB_CODIGO_DOLAR) {
                continue;
            }

            $key = $dto->date->format('Y-m-d');

            if (isset($existingMap[$key])) {
                continue;
            }

            $entity = new MaestroTipocambio();
            $entity->setFecha(DateTime::createFromImmutable($dto->date));
            // Ahora $dto->buy y $dto->sell son strings, así que esto funcionará perfecto
            $entity->setCompra($dto->buy);
            $entity->setVenta($dto->sell);
            $entity->setMoneda($monedaRef);

            $this->em->persist($entity);
            $existingMap[$key] = true;

            if (($i++ % $batchSize) === 0) {
                $this->em->flush();
            }
        }
        $this->em->flush();
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
        // Preferencia: último <= fecha solicitada (lo más correcto para contabilidad)
        $qb = $this->em->getRepository(MaestroTipocambio::class)->createQueryBuilder('tc');

        $result = $qb
            ->where('tc.moneda = :moneda')
            ->andWhere('tc.fecha <= :fecha')
            ->setParameter('moneda', MaestroMoneda::DB_VALOR_DOLAR)
            ->setParameter('fecha', $fecha)
            ->orderBy('tc.fecha', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($result instanceof MaestroTipocambio) {
            return $result;
        }

        // Si no existe nada <= fecha (BD vacía o solo fechas futuras), devolvemos el último global
        $qb2 = $this->em->getRepository(MaestroTipocambio::class)->createQueryBuilder('tc');
        return $qb2
            ->where('tc.moneda = :moneda')
            ->setParameter('moneda', MaestroMoneda::DB_VALOR_DOLAR)
            ->orderBy('tc.fecha', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}