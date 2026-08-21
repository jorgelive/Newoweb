<?php

declare(strict_types=1);

namespace App\Exchange\Service\Client;

use App\Exchange\Entity\EmailConfig;
use App\Exchange\Service\Common\ExchangeNetworkResult;
use App\Exchange\Service\Contract\ExchangeClientInterface;
use App\Exchange\Service\Mapping\MappingResult;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * El transporte del canal de correo.
 *
 * ── Por qué el mailer y no un cliente HTTP ──────────────────────────────────
 * El resto de clientes de Exchange hablan HTTP porque al otro lado hay una API. Aquí no: el
 * transporte es Microsoft Graph a través del puente de Symfony, configurado por `MAILER_DSN`
 * —ver `docs/CorreoSaliente.md`—. Reimplementar Graph a mano sería mantener por nuestra cuenta
 * la autenticación, los reintentos y el formato MIME que el puente ya resuelve.
 *
 * Lo que se conserva del motor es lo que de verdad importaba: la cola, los reintentos con
 * espera, el bloqueo por worker y la auditoría. Por eso esto es un `ExchangeClientInterface` y
 * no una llamada suelta a `MailerInterface` desde un listener.
 *
 * ⚠️ **El mailer no devuelve códigos HTTP.** O entrega al transporte o lanza. Se responde `200`
 * cuando sale y `502` cuando el transporte falla, para que el motor pueda decidir el reintento
 * con el mismo criterio que usa con los demás canales. Ese `502` es una convención de aquí, no
 * algo que dijera un servidor: leerlo como respuesta remota sería malinterpretarlo.
 *
 * ⚠️ **Un envío por elemento, sin lote.** Cada correo va a un destinatario distinto y con su
 * propio asunto: agruparlos sería mandar el mismo mensaje a varias personas, que es justo lo que
 * no se quiere. El `getMaxBatchSize()` de la tarea existe para no bloquear la cola, no para
 * juntar destinatarios.
 */
#[AutoconfigureTag('app.exchange.client')]
final readonly class MailerExchangeClient implements ExchangeClientInterface
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    public static function getClientAlias(): string
    {
        return 'email';
    }

    public function send(MappingResult $mapping): ExchangeNetworkResult
    {
        $config = $mapping->config;

        if (!$config instanceof EmailConfig) {
            throw new RuntimeException('El canal de correo necesita una EmailConfig.');
        }

        $remitente = trim($config->getRemitente());

        if ($remitente === '') {
            throw new RuntimeException('La configuración de correo no tiene buzón remitente.');
        }

        $resultados = [];
        $fallos = [];

        foreach ($mapping->payload as $clave => $correo) {
            $destino = trim((string) ($correo['to'] ?? ''));

            if ($destino === '') {
                $fallos[(string) $clave] = 'Sin destinatario.';
                continue;
            }

            try {
                $mensaje = (new Email())
                    ->from(new Address($remitente, $config->getRemitenteNombre() ?? ''))
                    ->to($destino)
                    ->subject((string) ($correo['subject'] ?? ''))
                    ->text((string) ($correo['text'] ?? ''));

                if (($responderA = trim((string) $config->getResponderA())) !== '') {
                    $mensaje->replyTo($responderA);
                }

                $this->mailer->send($mensaje);

                // El `Message-ID` que el puente deja en la cabecera es lo único que permite
                // rastrear después un envío concreto en el buzón.
                $resultados[(string) $clave] = ['messageId' => $mensaje->getHeaders()->get('Message-ID')?->getBodyAsString()];
            } catch (TransportExceptionInterface $e) {
                $fallos[(string) $clave] = $e->getMessage();
            }
        }

        // Basta con que uno falle para que el lote se dé por fallido: el motor reintenta el
        // elemento, no el lote, así que un éxito parcial no se pierde.
        $codigo = $fallos === [] ? 200 : 502;

        return new ExchangeNetworkResult(
            ['enviados' => $resultados, 'fallos' => $fallos],
            (string) json_encode(['enviados' => $resultados, 'fallos' => $fallos], JSON_UNESCAPED_UNICODE),
            $codigo
        );
    }
}
