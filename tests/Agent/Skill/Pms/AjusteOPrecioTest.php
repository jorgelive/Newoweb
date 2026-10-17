<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Skill\Pms\AjustarTarifasSkill;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * «Ponlo a 24» no es «+24».
 *
 * El 17/10/2026 el modelo pasó el precio final como ajuste (`"24"`, queriendo dejar la noche EN 24)
 * y la skill lo leyó como subida: 35,40 → 59,40, ya confirmado. No se aplicó sólo porque esas
 * noches estaban vendidas. Ahora el precio final tiene su parámetro y un ajuste sin signo se
 * rechaza. Ver docs/Mensajeria.md, «ajustar_tarifas».
 *
 * Son los caminos que se deciden antes de tocar la base: la skill se crea sin constructor.
 */
final class AjusteOPrecioTest extends TestCase
{
    #[Test]
    public function un_ajuste_sin_signo_se_rechaza_y_dice_que_use_precio(): void
    {
        $resultado = $this->ejecutar(['ajuste' => '24']);

        self::assertNotNull($resultado->error);
        self::assertStringContainsString('«+24» sube 24', (string) $resultado->error);
        self::assertStringContainsString('«precio»', (string) $resultado->error);
    }

    #[Test]
    public function un_porcentaje_sin_signo_tambien(): void
    {
        self::assertNotNull($this->ejecutar(['ajuste' => '10%'])->error);
    }

    #[Test]
    public function ajuste_y_precio_a_la_vez_no(): void
    {
        $resultado = $this->ejecutar(['ajuste' => '-5', 'precio' => '24']);

        self::assertNotNull($resultado->error);
        self::assertStringContainsString('no los dos', (string) $resultado->error);
    }

    #[Test]
    public function un_precio_que_no_es_numero_se_rechaza(): void
    {
        self::assertNotNull($this->ejecutar(['precio' => 'barato'])->error);
    }

    /** @param array<string, string> $datos */
    private function ejecutar(array $datos): \App\Agent\Skill\SkillResult
    {
        $skill = (new ReflectionClass(AjustarTarifasSkill::class))->newInstanceWithoutConstructor();

        return $skill->ejecutar(
            $datos + ['casita' => 'Casita 4', 'desde' => '2026-10-17', 'hasta' => '2026-10-19', 'confirmado' => true],
            $this->createStub(ActorInterface::class)
        );
    }
}
