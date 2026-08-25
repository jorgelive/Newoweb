<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill;

use App\Agent\Skill\RastroDeSkill;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Lo que se prueba aquí NO es el formato bonito del log: es `fueFallo()`, de la que cuelga el
 * corte del bucle del motor. Si un día deja de reconocer `error_de_nombre`, el corte no salta y
 * el turno vuelve a costar 200 000 tokens — sin dar ningún error.
 */
#[CoversClass(RastroDeSkill::class)]
final class RastroDeSkillTest extends TestCase
{
    public function testReconoceElErrorPelado(): void
    {
        self::assertTrue(RastroDeSkill::fueFallo(['error' => 'No existe la herramienta.']));
    }

    /** El caso real: viaja como resultado CORRECTO, con opciones, y aun así es un «no pude». */
    public function testReconoceLosErroresConSufijo(): void
    {
        self::assertTrue(RastroDeSkill::fueFallo(['error_de_nombre' => '…', 'opciones' => []]));
        self::assertTrue(RastroDeSkill::fueFallo(['error_repetida' => '…']));
    }

    public function testUnResultadoBuenoNoEsFallo(): void
    {
        self::assertFalse(RastroDeSkill::fueFallo(['personas' => [], 'total' => 0]));
    }

    /** `errores_conocidos` no empieza por `error_`: no se cuenta como fallo por parecerse. */
    public function testNoConfundeUnaClaveQueSoloEmpiezaParecido(): void
    {
        self::assertFalse(RastroDeSkill::fueFallo(['errores' => 3, 'erroresConocidos' => []]));
    }

    public function testLosArgumentosSeRecortan(): void
    {
        $largo = RastroDeSkill::argumentos(['persona' => str_repeat('á', 400)]);

        self::assertStringEndsWith('…', $largo);
        self::assertLessThanOrEqual(301, mb_strlen($largo));
        self::assertSame('{"grupo":"6"}', RastroDeSkill::argumentos(['grupo' => '6']));
        self::assertSame('{}', RastroDeSkill::argumentos([]));
    }

    /** Del resultado salen las CLAVES y el tamaño, nunca los datos personales de dentro. */
    public function testDelResultadoSalenClavesYTamano(): void
    {
        $rastro = RastroDeSkill::resultado(['total' => 1, 'personas' => [['nombre' => 'Susan Acuña']]]);

        self::assertStringStartsWith('total, personas · ', $rastro);
        self::assertStringNotContainsString('Susan', $rastro);
    }
}
