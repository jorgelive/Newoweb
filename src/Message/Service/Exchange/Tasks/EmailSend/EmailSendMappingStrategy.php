<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\EmailSend;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Message\Entity\EmailSendQueue;
use App\Message\Entity\MessageTemplate;

/**
 * Convierte los elementos de la cola en correos listos para entregar al mailer.
 *
 * ── Qué se resuelve aquí y qué venía congelado ──────────────────────────────
 * El **destino** y el **asunto** ya venían decididos desde el encolado: entre programar un
 * recordatorio y mandarlo pueden pasar días, y el correo de alguien puede cambiar en ese rato.
 *
 * Lo que sí se resuelve ahora es el **cuerpo**, y a propósito: si lleva plantilla, su texto se
 * hidrata en el momento del envío —es como funcionan todos los canales— para que las variables
 * lleven los datos del día en que sale, no los de cuando se programó.
 */
final readonly class EmailSendMappingStrategy implements MappingStrategyInterface
{
    public function map(HomogeneousBatch $batch): MappingResult
    {
        $payload = [];
        $correlacion = [];

        foreach ($batch->getItems() as $item) {
            if (!$item instanceof EmailSendQueue) {
                continue;
            }

            $clave = (string) $item->getId();
            $mensaje = $item->getMessage();
            $idioma = $mensaje?->getConversation()?->getIdioma()->getId() ?? 'es';

            $cuerpo = trim((string) $mensaje?->getContentExternal());

            if ($cuerpo === '' && ($plantilla = $mensaje?->getTemplate()) instanceof MessageTemplate) {
                $cuerpo = trim((string) $plantilla->getEmailBody($idioma));
            }

            if ($cuerpo === '') {
                $cuerpo = trim((string) $mensaje?->getContentLocal());
            }

            $payload[$clave] = [
                'to' => $item->getDestinationEmail(),
                'subject' => $item->getSubject(),
                'text' => $cuerpo,
            ];

            $correlacion[$clave] = $clave;
        }

        // `fullUrl` vacío: el correo no va a una ruta. Ver `MailerExchangeClient`.
        return new MappingResult('POST', '', $payload, $batch->getConfig(), $correlacion);
    }

    /**
     * Traduce lo que devolvió el cliente a un resultado por elemento.
     *
     * El cliente responde `{enviados: {id: {...}}, fallos: {id: motivo}}` — un mapa por id de
     * cola, no una lista posicional. Es deliberado: con correos a destinatarios distintos,
     * emparejar por posición es la clase de error que marca un envío como bueno cuando falló
     * otro.
     *
     * @param array<string, mixed> $apiResponse
     * @return array<string, ItemResult>
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array
    {
        /** @var array<string, array<string, mixed>> $enviados */
        $enviados = is_array($apiResponse['enviados'] ?? null) ? $apiResponse['enviados'] : [];
        /** @var array<string, string> $fallos */
        $fallos = is_array($apiResponse['fallos'] ?? null) ? $apiResponse['fallos'] : [];

        $resultados = [];

        foreach ($mapping->correlationMap as $clave) {
            $id = (string) $clave;
            $fallo = $fallos[$id] ?? null;

            $resultados[$id] = new ItemResult(
                queueItemId: $id,
                success: $fallo === null,
                message: $fallo,
                remoteId: $enviados[$id]['messageId'] ?? null,
                extraData: $enviados[$id] ?? [],
            );
        }

        return $resultados;
    }
}
