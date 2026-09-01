<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\EmailSend;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Mapping\ItemResult;
use App\Exchange\Service\Mapping\MappingResult;
use App\Exchange\Service\Mapping\MappingStrategyInterface;
use App\Message\Entity\EmailSendQueue;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\MessageDataResolverRegistry;

/**
 * Convierte los elementos de la cola en correos listos para entregar al mailer.
 *
 * ── Qué se resuelve aquí y qué venía congelado ──────────────────────────────
 * El **destino** y el **asunto** ya venían decididos desde el encolado: entre programar un
 * recordatorio y mandarlo pueden pasar días, y el correo de alguien puede cambiar en ese rato.
 *
 * Lo que sí se resuelve ahora es el **cuerpo y las variables**: el texto de la plantilla y sus
 * marcadores se hidratan en el momento del envío —como en los otros canales— para que lleven los
 * datos del día en que sale, no los de cuando se programó. El asunto también se hidrata aunque
 * venga congelado: congelarlo protege de que cambie la plantilla, no de que falte un dato.
 */
final readonly class EmailSendMappingStrategy implements MappingStrategyInterface
{
    public function __construct(private MessageDataResolverRegistry $resolvers)
    {
    }

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
            // Mismo criterio que en `variables()`: el idioma del CUERPO. Aquí acertaba de
            // rebote —`extract()` cae al inglés cuando no encuentra la lengua— pero con dos
            // criterios distintos para la misma pregunta, el día que uno cambie el otro miente.
            $idioma = self::idiomaDelCuerpo($mensaje?->getConversation());

            $cuerpo = trim((string) $mensaje?->getContentExternal());

            if ($cuerpo === '' && ($plantilla = $mensaje?->getTemplate()) instanceof MessageTemplate) {
                $cuerpo = trim((string) $plantilla->getEmailBody($idioma));
            }

            if ($cuerpo === '') {
                $cuerpo = trim((string) $mensaje?->getContentLocal());
            }

            // ⚠️ **Se hidrata aquí, y antes NO se hidrataba nada** pese a que el comentario de
            // esta clase lo afirmaba. Una plantilla con `Hola {{guest_name}}` salía por correo
            // con las llaves puestas mientras los otros dos canales la resolvían bien.
            //
            // El asunto también, aunque venga congelado de la cola: congelarlo protege de que
            // cambie la plantilla, no de que las variables lleguen sin resolver.
            $variables = $this->variables($item);

            $payload[$clave] = [
                'to' => $item->getDestinationEmail(),
                'subject' => $this->hidratar((string) $item->getSubject(), $variables, $item),
                'text' => $this->hidratar($cuerpo, $variables, $item),
            ];

            $correlacion[$clave] = $clave;
        }

        // `fullUrl` vacío: el correo no va a una ruta. Ver `MailerExchangeClient`.
        return new MappingResult('POST', '', $payload, $batch->getConfig(), $correlacion);
    }

    /**
     * En qué idioma va el CUERPO de este mensaje. **No es el del huésped.**
     *
     * Los idiomas con prioridad 0 son los que no traducimos: la plantilla cae al inglés. Pasarle
     * a las variables el del huésped componía el bloque de dinero en su lengua —cuerpo en
     * inglés, rótulos en japonés— y como `TextosUi` no tiene esa lengua, los rótulos salían
     * VACÍOS: «*:* USD 60.96».
     *
     * Es la misma bifurcación que hacen `Beds24SendMappingStrategy` y
     * `WhatsappMetaSendMappingStrategy` con su `$templateLang`.
     */
    private static function idiomaDelCuerpo(?MessageConversation $conversacion): string
    {
        $idioma = $conversacion?->getIdioma();

        if ($idioma === null) {
            return 'es';
        }

        return $idioma->getPrioridad() > 0 ? strtolower($idioma->getId()) : 'en';
    }

    /**
     * Las variables del asunto del mensaje, más las que el propio mensaje traiga.
     *
     * ⚠️ El orden importa: las del MENSAJE ganan. Un aviso de escalado lleva sus datos ahí
     * —no en el contexto— y sin esa precedencia saldría con huecos.
     *
     * @return array<string, mixed>
     */
    private function variables(EmailSendQueue $item): array
    {
        $mensaje = $item->getMessage();
        $conversacion = $mensaje?->getConversation();

        if ($mensaje === null || $conversacion === null) {
            return [];
        }

        $asuntoType = $mensaje->getAsuntoType() ?? $conversacion->getContextType();
        $asuntoId = $mensaje->getAsuntoId() ?? $conversacion->getContextId();

        // ⚠️ **El idioma del CUERPO, no el del huésped**, que es la bifurcación que hacen los
        // otros dos mapeadores y éste no. Con prioridad 0 —un idioma fuera de los siete que
        // traducimos— la plantilla cae al inglés, y pasando aquí el del huésped se componía el
        // bloque de dinero en su lengua: cuerpo en inglés, rótulos en japonés. Y como `TextosUi`
        // no tiene esa lengua, los rótulos salían VACÍOS: «*:* USD 60.96».
        //
        // Hoy es latente —las 14 plantillas tienen el correo apagado— y por eso mismo hay que
        // arreglarlo ahora: el día que se encienda el primero, nadie va a estar mirando.
        $idioma = self::idiomaDelCuerpo($conversacion);

        $delAsunto = $this->resolvers->getResolver($asuntoType)?->getMessageVariables($asuntoId, $idioma) ?? [];

        return $mensaje->getVariablesPlantilla() + $delAsunto;
    }

    /**
     * Sustituye `{{marcador}}` y **avisa de lo que quede sin resolver**.
     *
     * ⚠️ Un marcador sin valor se enviaba crudo al huésped, en los tres canales, sin log ni
     * marca. Aquí al menos queda registrado: un `{{guest_name}}` literal en la bandeja de
     * alguien es de las cosas que sólo se descubren si el cliente lo dice.
     *
     * @param array<string, mixed> $variables
     */
    private function hidratar(string $texto, array $variables, EmailSendQueue $item): string
    {
        if (!str_contains($texto, '{{')) {
            return $texto;
        }

        $faltantes = [];

        $resuelto = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            static function (array $m) use ($variables, &$faltantes): string {
                if (!array_key_exists($m[1], $variables)) {
                    $faltantes[] = $m[1];

                    return $m[0];
                }

                return (string) $variables[$m[1]];
            },
            $texto
        );

        if ($faltantes !== []) {
            $item->setFailedReason(sprintf(
                'Marcadores sin resolver, se envían crudos: %s',
                implode(', ', array_unique($faltantes))
            ));
        }

        return $resuelto;
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

        foreach (array_keys($mapping->correlationMap) as $clave) {
            $id = $mapping->idDeCola($clave);
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
