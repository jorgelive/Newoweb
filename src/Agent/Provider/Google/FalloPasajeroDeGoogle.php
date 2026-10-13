<?php

declare(strict_types=1);

namespace App\Agent\Provider\Google;

use RuntimeException;

/**
 * Un fallo de Google que se arregla solo: un 5xx, una respuesta que no es JSON, la red.
 *
 * Es un `RuntimeException` como los demás —quien llama al cliente no tiene que distinguirlo—; la
 * distinción sólo la usa {@see GoogleAIClient::generarContenido()} para decidir si reintenta.
 */
final class FalloPasajeroDeGoogle extends RuntimeException
{
}
