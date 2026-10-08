<?php

declare(strict_types=1);

namespace App\Finanzas\Service;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Entity\FinPasarelaCobroAudit;
use App\Finanzas\Enum\FinPasarela;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Escribe la traza de cada intento de cobro. Ver {@see FinPasarelaCobroAudit} para el porqué.
 *
 * ⚠️ **Auditar NUNCA puede tumbar un cobro.** Todo lo que hace esta clase va envuelto: si la
 * escritura falla, se registra en el log y el pago sigue su camino. Es la misma regla que
 * `CulqiWebhookController::cerrarAudit()`, y va en el sentido contrario al habitual — aquí la
 * observabilidad vale menos que la operación que observa.
 */
final readonly class FinCobroAuditor
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /**
     * Abre la fila ANTES de llamar a la pasarela.
     *
     * Devuelve `null` si no se pudo escribir: quien llama sigue adelante sin auditoría, que es
     * peor que tenerla y mucho mejor que quedarse sin cobrar.
     */
    public function abrir(FinEnlacePago $enlace, FinPasarela $pasarela, bool $con3DS): ?FinPasarelaCobroAudit
    {
        try {
            $audit = new FinPasarelaCobroAudit();
            $audit
                ->setPasarela($pasarela)
                ->setEnlaceId($enlace->getId())
                ->setCon3DS($con3DS);

            $this->em->persist($audit);
            $this->em->flush();

            return $audit;
        } catch (Throwable $e) {
            $this->logger->error('[finanzas] no se pudo abrir la auditoría del cobro', [
                'enlace' => (string) $enlace->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Una fila por cada cosa que el NAVEGADOR cuenta del reto 3DS (`DESENLACES_DEL_NAVEGADOR`).
     *
     * El tramo entre `reto_3ds` y el segundo cobro ocurre entero en el navegador, y cuando el reto
     * no volvía no quedaba nada: ni si llegó a lanzarse, ni qué dijo la librería de Culqi, ni si
     * el huésped recargó. Va con `con_3ds` a 1 porque es parte del reto.
     *
     * El detalle lo escribe el navegador —es un endpoint público—: se recorta, y nunca lleva datos
     * de la tarjeta (sólo el texto de error de la librería). Como todo aquí, no lanza.
     */
    public function anotarReto(FinEnlacePago $enlace, FinPasarela $pasarela, string $evento, ?string $detalle): void
    {
        try {
            $audit = (new FinPasarelaCobroAudit())
                ->setPasarela($pasarela)
                ->setEnlaceId($enlace->getId())
                ->setCon3DS(true)
                ->setDesenlace($evento)
                ->setMotivo($detalle !== null && $detalle !== '' ? mb_substr($detalle, 0, 500) : null);

            $this->em->persist($audit);
            $this->em->flush();
        } catch (Throwable $e) {
            $this->logger->error('[finanzas] no se pudo anotar el reto 3DS', [
                'enlace' => (string) $enlace->getId(),
                'evento' => $evento,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cierra la fila con lo que contestó la pasarela.
     *
     * @param array<string, mixed> $cuerpo Respuesta cruda; se guarda sin datos del titular.
     */
    public function cerrar(
        ?FinPasarelaCobroAudit $audit,
        string $desenlace,
        array $cuerpo = [],
        ?string $motivo = null,
    ): void {
        if ($audit === null) {
            return;
        }

        try {
            $senales = self::senalesDe($cuerpo);

            $audit
                ->setDesenlace($desenlace)
                ->setObjeto($senales['objeto'])
                ->setActionCode($senales['actionCode'])
                ->setOutcomeType($senales['outcomeType'])
                ->setOutcomeCode($senales['outcomeCode'])
                ->setCargoId($senales['cargoId'])
                ->setMotivo($motivo ?? $senales['motivo'])
                ->setRespuesta($cuerpo === [] ? null : self::sinDatosDelTitular($cuerpo));

            $this->em->flush();
        } catch (Throwable $e) {
            $this->logger->error('[finanzas] no se pudo cerrar la auditoría del cobro', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Los campos que hacen falta para leer una secuencia sin abrir el JSON.
     *
     * **Pura y estática a propósito**: es la única parte de esta clase con decisiones dentro, y
     * así se puede probar sin contenedor ni base de datos — que es todo lo que hay hoy en
     * `tests/`. Lee las dos formas en que Culqi dice lo mismo: `outcome.x` cuando hay cargo y
     * `x` a secas cuando devuelve un error.
     *
     * @param array<string, mixed> $cuerpo
     * @return array{objeto: ?string, actionCode: ?string, outcomeType: ?string, outcomeCode: ?string, cargoId: ?string, motivo: ?string}
     */
    public static function senalesDe(array $cuerpo): array
    {
        /** @var array<string, mixed> $outcome */
        $outcome = is_array($cuerpo['outcome'] ?? null) ? $cuerpo['outcome'] : [];

        return [
            'objeto' => self::texto($cuerpo['object'] ?? null),
            'actionCode' => self::texto($cuerpo['action_code'] ?? null),
            'outcomeType' => self::texto($outcome['type'] ?? null),
            'outcomeCode' => self::texto($outcome['code'] ?? $cuerpo['code'] ?? null),
            'cargoId' => self::texto($cuerpo['id'] ?? null),
            'motivo' => self::texto(
                $outcome['merchant_message'] ?? $cuerpo['merchant_message'] ?? $cuerpo['user_message'] ?? null
            ),
        ];
    }

    /**
     * El cuerpo sin lo que identifica al titular.
     *
     * `source` lleva la tarjeta enmascarada, el correo y la huella del dispositivo;
     * `antifraud_details`, el nombre y el teléfono. Nada de eso hace falta para entender qué
     * respondió la pasarela, y esto se consulta meses después y desde muchos sitios.
     *
     * @param array<string, mixed> $cuerpo
     * @return array<string, mixed>
     */
    public static function sinDatosDelTitular(array $cuerpo): array
    {
        unset($cuerpo['source'], $cuerpo['antifraud_details'], $cuerpo['client']);

        return $cuerpo;
    }

    private static function texto(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
