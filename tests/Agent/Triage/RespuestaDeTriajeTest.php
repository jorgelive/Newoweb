<?php

declare(strict_types=1);

namespace App\Tests\Agent\Triage;

use App\Agent\Triage\RespuestaDeTriaje;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El JSON del clasificador leído con tipo: lo mismo que los `(string)` de `Triaje::interpretar()`,
 * menos la palabra «Array».
 */
#[CoversClass(RespuestaDeTriaje::class)]
final class RespuestaDeTriajeTest extends TestCase
{
    public function testUnaRespuestaCompleta(): void
    {
        $r = RespuestaDeTriaje::fromArray([
            'tipo' => 'peticion',
            'skill' => ' consultar_disponibilidad ',
            'candidatos' => ['evaluar_cambio_horario', 'aplicar_cambio_horario'],
            'pista' => ' wifi ',
            'tema_id' => '0199aa…',
            'respuesta' => '',
            'motivo' => 'pregunta por fechas',
            'resumen' => 'Quiere saber si hay sitio.',
        ]);

        self::assertSame('peticion', $r->tipo);
        self::assertSame('consultar_disponibilidad', $r->skill);
        self::assertSame(['evaluar_cambio_horario', 'aplicar_cambio_horario'], $r->candidatos);
        self::assertSame('wifi', $r->pista);
        self::assertSame('0199aa…', $r->temaId);
        self::assertSame('', $r->respuesta);
        self::assertSame('pregunta por fechas', $r->motivo);
        self::assertSame('Quiere saber si hay sitio.', $r->resumen);
    }

    /** `tipo` se deja sin recortar: `TipoDeMensaje::tryFrom()` lo recibía así. */
    public function testElTipoVaTalCualYNullSiNoVino(): void
    {
        self::assertSame(' peticion', RespuestaDeTriaje::fromArray(['tipo' => ' peticion'])->tipo);
        self::assertNull(RespuestaDeTriaje::fromArray([])->tipo);
    }

    /** Un nombre suelto donde iba la lista es una lista de uno, como con el `(array)` de antes. */
    public function testUnCandidatoSueltoEsUnaListaDeUno(): void
    {
        self::assertSame(['evaluar_cambio_horario'], RespuestaDeTriaje::fromArray(['candidatos' => 'evaluar_cambio_horario'])->candidatos);
        self::assertSame([], RespuestaDeTriaje::fromArray(['candidatos' => null])->candidatos);
        self::assertSame([], RespuestaDeTriaje::fromArray([])->candidatos);
    }

    /** Lo que no es texto es «no vino»: antes, un array era «Array» y un aviso en el log. */
    public function testLoQueNoEsTextoNoVino(): void
    {
        $r = RespuestaDeTriaje::fromArray([
            'skill' => ['consultar_disponibilidad'],
            'candidatos' => ['evaluar_cambio_horario', ['anidado'], 7],
        ]);

        self::assertSame('', $r->skill);
        self::assertSame(['evaluar_cambio_horario', '7'], $r->candidatos);
    }
}
