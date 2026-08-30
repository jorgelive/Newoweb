<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Entity\PmsUnidad;
use DateTimeImmutable;

final readonly class BookingsPullMappingStrategy implements MappingStrategyInterface
{
    /**
     * Los estados que se piden a Beds24.
     *
     * `cancelled` está aquí porque este pull es la red que recoge lo que el webhook perdió, y
     * una cancelación no avisada es justo el caso que importa. `inquiry` porque el PMS lo
     * modela como estado «abierto» y el persister lo trata aparte
     * ({@see BookingPullPersister::resolveEstado()}).
     *
     * @var list<string>
     */
    private const array ESTADOS_CONSULTADOS = [
        'confirmed',
        'new',
        'request',
        'cancelled',
        'black',
        'inquiry',
    ];

    /**
     * Construye la petición GET.
     */
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $config = $batch->getConfig();
        $endpoint = $batch->getEndpoint();

        // 1. Construcción de URL Base
        $fullUrl = rtrim($config->getBaseUrl(), '/') . '/' . ltrim((string)$endpoint->getEndpoint(), '/');

        // 2. Extracción del Job (Pull siempre es 1 ítem por lote lógico)
        /** @var PmsBookingsPullQueue $job */
        $job = $batch->getItems()[0];

        // 3. Definición de Fechas (Con Fallback de seguridad)
        $arrivalFrom = $job->getArrivalFrom() ?? new DateTimeImmutable('today');
        $arrivalTo = $job->getArrivalTo();

        // 4. Construcción de la query — A MANO, no por `payload`
        //
        // ⚠️ El cliente hace `'query' => $payload` y Symfony serializa un array con
        // `http_build_query`, que produce `status[0]=confirmed&status[1]=new…`. Beds24 NO lo
        // interpreta como parámetro repetido: se queda sin filtro de estado y aplica el suyo por
        // defecto, que EXCLUYE las canceladas. Ése era el motivo de que una cancelación cuyo
        // webhook se perdió no la recuperase nunca este cron: el `status` estaba escrito, pero
        // no llegaba.
        //
        // Beds24 quiere el parámetro repetido: `?status=confirmed&status=cancelled&…`. Mismo
        // caso, misma API y mismo cliente que lo ya documentado en
        // {@see \App\Pms\Service\Exchange\Tasks\InvoiceReceive\Beds24InvoiceReceiveMappingStrategy}
        // para `bookingId`.
        $partes = [
            'arrivalFrom=' . rawurlencode($arrivalFrom->format('Y-m-d')),
            // Aquí se piden RESERVAS. Los cargos NO: llegan por su propia vía
            // (`GET /bookings/invoices?bookingId=…`, Camino D — ver §11 del doc), y pedirlos
            // aquí sólo engordaría la respuesta con datos que este handler no mira.
            //
            // ⚠️ Antes iba `includeInvoice`, que NO es un parámetro de Beds24 —el suyo se llama
            // `includeInvoiceItems`—, así que llevaba todo este tiempo sin efecto alguno.
            //
            // Estos dos SÍ se piden, aunque `Beds24BookingDto` todavía no los declare y el
            // denormalizador los descarte: quedan enteros en `lastResponseRaw` de la fila de
            // cola, que es de donde se sacará la forma real cuando toque implementarlos.
            'includeInfoItems=true',
            'includeGuests=true',
        ];

        if ($arrivalTo) {
            $partes[] = 'arrivalTo=' . rawurlencode($arrivalTo->format('Y-m-d'));
        }

        foreach (self::ESTADOS_CONSULTADOS as $estado) {
            $partes[] = 'status=' . rawurlencode($estado);
        }

        // 5. Filtrado de Habitaciones (Scope Isolation)
        // Solo solicitamos las habitaciones vinculadas a *esta* configuración específica.
        $roomIds = [];
        /** @var PmsUnidad $unidad */
        foreach ($job->getUnidades() as $unidad) {
            foreach ($unidad->getBeds24Maps() as $map) {
                // Validación estricta: el mapa debe pertenecer a la cuenta que estamos
                // consultando.
                //
                // ⚠️ Aquí había `$map->getConfig()`, un método que NO EXISTE en
                // `PmsUnidadBeds24Map` —ni la columna—: era un `Error` fatal esperando. No había
                // saltado nunca porque este bucle sólo corre con jobs acotados a unidades y no
                // hay ninguno (0 filas en `pms_pull_queue_job_unidad`). El día que se creara el
                // primero, el pull entero se caía.
                //
                // La cuenta se alcanza por la relación que sí está modelada:
                // mapa → establecimiento virtual → establecimiento → config.
                $configDelMapa = $map->getVirtualEstablecimiento()?->getEstablecimiento()?->getBeds24Config();

                if (null !== $configDelMapa && $configDelMapa->getId() === $config->getId()) {
                    $roomIds[] = (int)$map->getBeds24RoomId();
                }
            }
        }

        // Si hay habitaciones específicas, filtramos. Si no, Beds24 devuelve todo (según permisos del token).
        //
        // Repetido, igual que `status`: antes iba `roomId=501,502` separado por comas. Nadie
        // llegó a comprobar que Beds24 aceptase esa forma, y no saltó nunca porque este bucle
        // sólo corre con jobs acotados a unidades y no hay ninguno.
        foreach (array_unique($roomIds) as $roomId) {
            $partes[] = 'roomId=' . $roomId;
        }

        // El payload va VACÍO a propósito: la query entera viaja en la URL. Encaja con el bucle
        // de paginación del cliente, que al saltar de página sustituye la URL por `nextPageLink`
        // —que ya trae los parámetros originales— y vacía el payload para no duplicarlos.
        return new MappingResult(
            method: (string)$endpoint->getMetodo(),
            fullUrl: $fullUrl . '?' . implode('&', $partes),
            payload: [],
            config: $config,
            correlationMap: ['job' => (string)$job->getId()]
        );
    }

    /**
     * Procesa la respuesta masiva.
     * Beds24 v2 GET bookings suele devolver un array de objetos o un wrapper con error.
     *
     * @param array<array-key, mixed> $apiResponse Puede venir como mapa o como lista: el código comprueba ambas.
     * @return array<string, mixed>
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        $jobId = $mapping->correlationMap['job'];

        // 1. Detección de Errores Lógicos (API responde 200 pero con success: false)
        if (isset($apiResponse['success']) && $apiResponse['success'] === false) {
            $msg = $apiResponse['message'] ?? 'Error lógico en API al descargar reservas';

            // Si hay errores detallados, tomamos el primero
            if (isset($apiResponse['errors'][0]['message'])) {
                $msg .= ': ' . $apiResponse['errors'][0]['message'];
            }
            return [$jobId => new ItemResult($jobId, false, $msg)];
        }

        // 2. Normalización de Datos
        // La API puede devolver los datos directamente en la raíz (array) o dentro de 'data'
        // Si es un array secuencial (lista de reservas), lo usamos directo.
        $bookingsData = $apiResponse;

        if (isset($apiResponse['data']) && is_array($apiResponse['data'])) {
            $bookingsData = $apiResponse['data'];
        } elseif (isset($apiResponse['id']) && !isset($apiResponse[0])) {
            $bookingsData = [$apiResponse];
        }

        $count = count($bookingsData);

        // 3. Retorno Exitoso
        // Pasamos TODO el array de reservas en 'extraData'.
        // El Handler se encargará de iterarlas y persistirlas una por una.
        return [
            $jobId => new ItemResult(
                queueItemId: $jobId,
                success: true,
                message: "Descarga completada: $count reservas recuperadas.",
                remoteId: null,
                extraData: $bookingsData
            )
        ];
    }
}