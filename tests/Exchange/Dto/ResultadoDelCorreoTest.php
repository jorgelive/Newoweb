<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Dto;

use App\Exchange\Dto\Correo\CorreoSaliente;
use App\Exchange\Dto\Correo\ResultadoDelCorreo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Los dos DTO del correo viajan por el motor como arrays: lo que uno escribe, el otro lo tiene que
 * leer igual, y la forma escrita es la que se guarda en la auditoría de la cola.
 */
#[CoversClass(CorreoSaliente::class)]
#[CoversClass(ResultadoDelCorreo::class)]
final class ResultadoDelCorreoTest extends TestCase
{
    public function testElCorreoSeLeeComoSeEscribioYConservaLasClavesDeLaAuditoria(): void
    {
        $correo = new CorreoSaliente('huesped@example.com', 'Tu llegada', 'Hola');

        self::assertSame(['to' => 'huesped@example.com', 'subject' => 'Tu llegada', 'text' => 'Hola'], $correo->toArray());
        self::assertEquals($correo, CorreoSaliente::fromArray($correo->toArray()));
    }

    public function testUnCorreoSinDestinatarioLlegaSinDestinatario(): void
    {
        $correo = CorreoSaliente::fromArray(['to' => null, 'subject' => 'x', 'text' => 'y']);

        self::assertNull($correo->para);
    }

    public function testElResultadoConservaLaFormaGuardadaYSeLeeIgual(): void
    {
        $resultado = new ResultadoDelCorreo(['id-1' => '<abc@graph>', 'id-2' => null], ['id-3' => 'buzón lleno']);
        $forma = $resultado->toArray();

        // La forma de `last_response_raw`, la de antes: `{enviados: {id: {messageId}}, fallos: {id: motivo}}`.
        self::assertSame(['id-1' => ['messageId' => '<abc@graph>'], 'id-2' => ['messageId' => null]], $forma['enviados']);
        self::assertSame(['id-3' => 'buzón lleno'], $forma['fallos']);
        self::assertEquals($resultado, ResultadoDelCorreo::fromArray($forma));
    }

    /** `fallos` vacío llega como lista JSON (`[]`), no como objeto: no es un fallo de nadie. */
    public function testSinFallosLaListaVaciaNoEsUnFallo(): void
    {
        $leido = ResultadoDelCorreo::fromArray(['enviados' => ['id-1' => ['messageId' => null]], 'fallos' => []]);

        self::assertSame([], $leido->fallos);
        self::assertArrayHasKey('id-1', $leido->enviados);
    }
}
