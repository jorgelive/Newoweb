<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Dto;

use App\Exchange\Dto\Beds24\Beds24Respuesta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Las respuestas de Beds24. Que el DTO lee lo mismo que el código de antes sobre las respuestas
 * guardadas lo comprueba `tools/pruebas/probar-dto-canales.php`; aquí, los casos que no quedan
 * guardados (la paginación, los sobres de error) y las diferencias deliberadas con `(bool)`/`(string)`.
 */
#[CoversClass(Beds24Respuesta::class)]
final class Beds24RespuestaTest extends TestCase
{
    public function testUnaPiezaDeEscrituraTraeSuIdNuevoSinPasarloATexto(): void
    {
        $pieza = Beds24Respuesta::dePieza(['success' => true, 'new' => ['id' => 93628254]]);

        self::assertTrue($pieza->exito);
        // Sin convertir: acaba en `execution_result` y ahí siempre fue un número.
        self::assertSame(93628254, $pieza->idNuevo);
        self::assertNull($pieza->id);
    }

    public function testElPrimerErrorYElMensajeSeLeenAparte(): void
    {
        $pieza = Beds24Respuesta::dePieza([
            'success' => false,
            'message' => 'general',
            'errors' => [['field' => 'arrival', 'message' => 'fecha inválida']],
        ]);

        self::assertFalse($pieza->exito);
        self::assertSame('fecha inválida', $pieza->primerError);
        self::assertSame('general', $pieza->mensaje);
    }

    /** Sin `success` no se sabe: cada consumidor decide su valor por defecto (`?? false` o `?? true`). */
    public function testSinSuccessElExitoEsDesconocido(): void
    {
        self::assertNull(Beds24Respuesta::dePieza(['id' => 1])->exito);
        self::assertNull(Beds24Respuesta::dePieza('no soy un objeto')->exito);
    }

    /** `declaraFallo` es el `=== false` estricto de los sobres: un «false» de texto no lo es. */
    public function testDeclaraFalloSoloConElBooleanoFalse(): void
    {
        self::assertTrue(Beds24Respuesta::fromArray(['success' => false])->declaraFallo);
        self::assertFalse(Beds24Respuesta::fromArray(['success' => 'false'])->declaraFallo);
        self::assertFalse(Beds24Respuesta::fromArray([])->declaraFallo);
    }

    public function testLasFilasDelPullSalenDeDataDeUnaReservaSueltaODeLaRaiz(): void
    {
        self::assertSame([['id' => 1]], Beds24Respuesta::fromArray(['success' => true, 'data' => [['id' => 1]]])->filas);
        self::assertSame([['id' => 7, 'status' => 'new']], Beds24Respuesta::fromArray(['id' => 7, 'status' => 'new'])->filas);
        self::assertSame([['id' => 1], ['id' => 2]], Beds24Respuesta::fromArray([['id' => 1], ['id' => 2]])->filas);
    }

    public function testModifiedComoLoDejabaElCastAArray(): void
    {
        self::assertSame(['roomId' => 5], Beds24Respuesta::dePieza(['success' => true, 'modified' => ['roomId' => 5]])->modificadoOCrudo);
        self::assertSame(['x'], Beds24Respuesta::dePieza(['modified' => 'x'])->modificadoOCrudo);
        self::assertSame(['success' => true], Beds24Respuesta::dePieza(['success' => true])->modificadoOCrudo);
    }

    public function testLaSiguientePaginaSoloSiBeds24DiceQueExisteYDaElEnlace(): void
    {
        $conSiguiente = ['data' => [], 'pages' => ['nextPageExists' => true, 'nextPageLink' => 'https://api.beds24.com/v2/bookings?page=2']];
        self::assertSame('https://api.beds24.com/v2/bookings?page=2', Beds24Respuesta::fromArray($conSiguiente)->siguientePagina);

        self::assertNull(Beds24Respuesta::fromArray(['pages' => ['nextPageExists' => false, 'nextPageLink' => 'https://x']])->siguientePagina);
        self::assertNull(Beds24Respuesta::fromArray(['pages' => ['nextPageExists' => true, 'nextPageLink' => '']])->siguientePagina);
        // Un enlace que no es texto no se sigue: antes llegaba a `request()` y reventaba con un TypeError.
        self::assertNull(Beds24Respuesta::fromArray(['pages' => ['nextPageExists' => true, 'nextPageLink' => ['x']]])->siguientePagina);
    }

    /** La clave de reparto es TEXTO: PHP convierte a int las claves numéricas y la búsqueda fallaba. */
    public function testElBookingIdDeRepartoEsTexto(): void
    {
        self::assertSame('93648770', Beds24Respuesta::bookingIdDe(['bookingId' => 93648770]));
        self::assertSame('93648770', Beds24Respuesta::bookingIdDe(['bookingId' => '93648770']));
        self::assertSame('', Beds24Respuesta::bookingIdDe([]));
        self::assertSame('', Beds24Respuesta::bookingIdDe(['bookingId' => ['raro']]));
    }
}
